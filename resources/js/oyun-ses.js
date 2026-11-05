/**
 * Stant oyunlarının sesleri. Dosya indirilmez: sesler tarayıcıda Web Audio
 * ile üretilir, gecikmesiz çalar. Telefonlar sesi ancak bir dokunuştan sonra
 * açtığı için ilk dokunuşta unlock() çağrılır. Sessize alma tercihi
 * telefonda saklanır. iPhone'un sessiz düğmesine uyulur.
 */
const STORAGE_KEY = 'oyun:ses';

// Notalar (Hz): doğru cevaplar pentatonik dizide yükselir, kulağa hoş gelir.
const LADDER = [783.99, 880, 1046.5, 1174.66, 1318.51, 1567.98, 1760, 2093];

let context = null;
let master = null;
let enabled = true;

try {
    enabled = localStorage.getItem(STORAGE_KEY) !== '0';
} catch (error) {
    enabled = true;
}

const unlock = () => {
    const Context = window.AudioContext || window.webkitAudioContext;

    if (! Context) {
        return;
    }

    if (! context) {
        context = new Context();
        // Üst üste binen seslerde cızırtı olmasın.
        const limiter = context.createDynamicsCompressor();
        limiter.threshold.value = -10;
        limiter.ratio.value = 6;
        master = context.createGain();
        master.gain.value = 0.7;
        master.connect(limiter).connect(context.destination);
    }

    if (context.state !== 'running') {
        context.resume().catch(() => {});
    }
};

// Uygulamadan dönünce (iOS bağlamı askıya alır) ses yeniden açılsın.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && context && context.state !== 'running') {
        context.resume().catch(() => {});
    }
});

/**
 * Tek nota: yumuşak başlayıp sönen zarf. "to" verilirse perde kayar.
 * Çalan osilatörü döndürür (zamanlanmış sesi iptal etmek için).
 */
export const tone = ({ freq, to = null, type = 'sine', at = 0, duration = 0.12, volume = 0.25, attack = 0.005 }) => {
    // Askıdaki bağlam (dokunuşla açılıyor) sesi açılınca çalar.
    if (! enabled || ! context || context.state === 'closed') {
        return null;
    }

    const start = context.currentTime + at;
    const oscillator = context.createOscillator();
    const gain = context.createGain();

    oscillator.type = type;
    oscillator.frequency.setValueAtTime(freq, start);

    if (to) {
        oscillator.frequency.exponentialRampToValueAtTime(to, start + duration);
    }

    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(volume, start + attack);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    oscillator.connect(gain).connect(master);
    oscillator.start(start);
    oscillator.stop(start + duration + 0.03);

    return oscillator;
};

export const sound = {
    unlock,

    get enabled() {
        return enabled;
    },

    toggle() {
        enabled = ! enabled;

        try {
            localStorage.setItem(STORAGE_KEY, enabled ? '1' : '0');
        } catch (error) {
            // Kaydedilemezse bu sayfada geçerli kalır.
        }

        return enabled;
    },

    /** Düğme: kısa, yumuşak "pop". */
    tap() {
        tone({ freq: 520, to: 340, duration: 0.07, volume: 0.18 });
    },

    /** Başla: yukarı kayan "vuuup". */
    start() {
        tone({ freq: 392, to: 784, duration: 0.2, volume: 0.22, type: 'triangle' });
    },

    /** Geri sayım: 3, 2, 1. */
    count() {
        tone({ freq: 659.25, duration: 0.12, volume: 0.22, type: 'triangle' });
    },

    /** İlk soru: kısa akor. */
    go() {
        tone({ freq: 1046.5, duration: 0.22, volume: 0.18, type: 'triangle' });
        tone({ freq: 1318.51, duration: 0.22, volume: 0.12, type: 'triangle', at: 0.01 });
    },

    /** Doğru: seri uzadıkça nota yükselir; üstte hafif parıltı. */
    hit(streak = 1) {
        const note = LADDER[Math.min(Math.max(streak, 1), LADDER.length) - 1];
        tone({ freq: note, duration: 0.14, volume: 0.22, type: 'triangle' });
        tone({ freq: note * 2, duration: 0.08, volume: 0.05, at: 0.02 });
    },

    /** Her 5'lik seri: kısa arpej. */
    streak() {
        [1046.5, 1318.51, 1567.98, 2093].forEach((freq, i) => {
            tone({ freq, duration: 0.12, volume: 0.13, type: 'triangle', at: 0.09 + i * 0.055 });
        });
    },

    /** Yanlış renk: aşağı kayan yumuşak "bonk". */
    miss() {
        tone({ freq: 330, to: 247, duration: 0.2, volume: 0.24, type: 'triangle' });
        tone({ freq: 165, to: 110, duration: 0.26, volume: 0.22 });
    },

    /** Süre doldu: daha alçak, daha uzun iniş. */
    timeout() {
        tone({ freq: 262, to: 150, duration: 0.32, volume: 0.24, type: 'triangle' });
        tone({ freq: 131, to: 82, duration: 0.34, volume: 0.2 });
    },

    /**
     * Süre azalınca tik sesleri. Soru başında zamanlanır, cevapta iptal
     * edilir; dönen fonksiyon bekleyen tikleri susturur.
     */
    warn(limitMs) {
        const marks = limitMs >= 600 ? [0.62, 0.8, 0.92] : [0.7];
        const ticks = marks.map((mark, i) => tone({ freq: 1567.98 + i * 220, duration: 0.035, volume: 0.1 + i * 0.03, at: (limitMs * mark) / 1000 }));

        return () => ticks.forEach((tick) => {
            try {
                tick?.stop();
            } catch (error) {
                // Zaten çalıp bitmiş.
            }
        });
    },

    /** Kazanılan yıldız: her biri bir öncekinden ince. */
    star(i = 0) {
        const freq = [1046.5, 1318.51, 1567.98][Math.min(i, 2)];
        tone({ freq, duration: 0.18, volume: 0.2, type: 'triangle' });
        tone({ freq: freq * 2, duration: 0.12, volume: 0.06, at: 0.03 });
    },

    /** Yeni rekor: yükselen kısa kutlama. */
    cheer() {
        [783.99, 1046.5, 1318.51, 1567.98, 2093].forEach((freq, i) => {
            tone({ freq, duration: 0.14, volume: 0.14, type: 'triangle', at: i * 0.07 });
        });
    },

    /** Oyun bitti: ta-ta-ta-dam. */
    gameOver() {
        [783.99, 659.25, 523.25].forEach((freq, i) => {
            tone({ freq, duration: 0.16, volume: 0.2, type: 'triangle', at: i * 0.14 });
        });
        tone({ freq: 261.63, duration: 0.6, volume: 0.18, type: 'triangle', at: 0.44 });
        tone({ freq: 392, duration: 0.6, volume: 0.1, at: 0.44 });
    },
};
