/**
 * Ters Komut — telefondan oynanan renk/kelime oyunu.
 *
 * Ortada bir renk adı yazar ama yazının rengi başkadır; oyuncu kelimenin
 * dediğine değil yazının rengine basar. Soru başına süre ilk 5 soruda 1,5 sn,
 * sonra her soruda 35 ms kısalır; yanlış renk ya da dolan süre bir can
 * götürür, 3 can bitince oyun biter.
 *
 * Sorular sunucuda üretilir ve sıralamalı oyunda ancak "Başla"da gelir;
 * istemci yalnız basılan rengi ve tepki süresini gönderir, skoru sunucu
 * hesaplar. Her cevap basıldığı anda sunucuya da gider (sunucu kendi saatiyle
 * yazar, hile denetimi buna dayanır) ve telefona yazılır: sayfa yenilenir ya
 * da bağlantı koparsa o ana kadarki skor kaybolmaz.
 *
 * Akıcılık: geri bildirimler transform/opacity ile (ekran kartında), süre
 * çubuğu soru başına tek animasyon; kare başına JS çalışmaz.
 */
import { sound } from './oyun-ses';

const INKS = ['#16b8c4', '#f5b426', '#8b3fd9', '#ee3d8f'];
const WORDS = ['TURKUAZ', 'SARI', 'MOR', 'PEMBE'];

const timeLimit = (question) => (question <= 5 ? 1500 : Math.max(150, 1500 - (question - 5) * 35));
const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));
const buzz = (pattern) => {
    try {
        navigator.vibrate?.(pattern);
    } catch (error) {
        // Titreşim yoksa sessizce geç.
    }
};

const root = document.querySelector('[data-game]');

if (root) {
    play(root);
}

function play(root) {
    const { token, csrf, beginUrl, answerUrl, finishUrl, playUrl } = root.dataset;
    // Cevaptan sonra yeni kelimeye kadar bekleme: sunucu süreleri bununla denetler.
    const gapHit = Number(root.dataset.gapHit) || 170;
    const gapMiss = Number(root.dataset.gapMiss) || 560;
    // Pratik: sıralamadaki ilk oyundan sonraki tekrarlar. Sunucuya hiçbir
    // şey yazılmaz, skor yalnız ekranda kalır.
    const practice = root.dataset.practice === '1';
    // Misafir: kayıtsız oyun; pratik gibi sunucuya yazılmaz, sıralamaya girmez.
    const guest = root.dataset.guest === '1';
    const praiseEl = root.querySelector('[data-praise]');
    const confettiBox = document.querySelector('[data-confetti]');
    const BEST_KEY = 'ters-komut:rekor';
    const TUTORIAL_KEY = 'ters-komut:ogretici';
    const DEVICE_KEY = 'ters-komut:cihaz';
    const STAR_AT = [8, 16, 24];
    // Pratik ve misafirde sayfada; sıralamalı oyunda "Başla"da sunucudan.
    let questions = JSON.parse(document.querySelector('[data-questions]')?.textContent || '[]');
    const storageKey = `ters-komut:${token}`;

    const scoreEl = document.querySelector('[data-score]');
    const hearts = [...root.querySelectorAll('[data-heart]')];
    const timerBar = root.querySelector('[data-timer]');
    const timerHot = root.querySelector('[data-timer-hot]');
    const arena = root.querySelector('[data-arena]');
    const word = root.querySelector('[data-word]');
    const hint = root.querySelector('[data-hint]');
    const streakEl = root.querySelector('[data-streak]');
    const pad = root.querySelector('[data-pad]');
    const keys = [...root.querySelectorAll('[data-key]')];
    const startButton = root.querySelector('[data-start]');
    const danger = document.querySelector('[data-danger]');

    let index = 0;
    let lives = hearts.length;
    let score = 0;
    let streak = 0;
    let answers = [];
    let limit = 0;
    let shownAt = 0;
    let timeout = null;
    let accepting = false;
    let begun = false;
    let ended = false;
    let timers = [];
    let warning = null;
    let hushTicks = null;
    let wakeLock = null;
    // Oyun saati: "Başla"ya basılan an (sunucu süreyi aynı andan ölçer).
    let origin = 0;

    const form = (fields) => {
        const body = new FormData();
        body.append('_token', csrf);
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));

        return body;
    };

    const send = (url, fields) => fetch(url, {
        method: 'POST',
        body: form(fields),
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        credentials: 'same-origin',
        keepalive: true,
    });

    const save = () => {
        try {
            localStorage.setItem(storageKey, JSON.stringify(answers));
        } catch (error) {
            // Gizli sekmede yazılamayabilir; oyun yine sürer.
        }
    };

    const saved = () => {
        try {
            return JSON.parse(localStorage.getItem(storageKey) || '[]');
        } catch (error) {
            return [];
        }
    };

    // Android'de (ve iPad'de) adres çubuğu ve alt çubuk gizlenir, ekran dikey
    // kilitlenir. iPhone Safari tam ekrana izin vermez; orada sayfa zaten
    // ekranın tamamını kullanır.
    const fullscreen = () => {
        const page = document.documentElement;
        const request = page.requestFullscreen || page.webkitRequestFullscreen;

        if (! request || document.fullscreenElement || document.webkitFullscreenElement) {
            return Promise.resolve();
        }

        try {
            return Promise.resolve(request.call(page, { navigationUI: 'hide' }))
                .then(() => {
                    screen.orientation?.lock?.('portrait')?.catch?.(() => {});

                    // Ekran büyüyünce yerleşim otursun, sahne ondan sonra ölçülür.
                    return wait(120);
                })
                .catch(() => {});
        } catch (error) {
            // Tam ekran yoksa oyun normal sürer.
            return Promise.resolve();
        }
    };

    const leaveFullscreen = () => {
        try {
            (document.exitFullscreen || document.webkitExitFullscreen)?.call(document)?.catch?.(() => {});
        } catch (error) {
            // önemli değil
        }
    };

    const keepAwake = async () => {
        try {
            wakeLock = await navigator.wakeLock?.request('screen');
        } catch (error) {
            wakeLock = null;
        }
    };

    /* ── Geri bildirim (juice) ───────────────────────────────── */

    // Aynı öğede üst üste binen animasyon ekran kartından düşer: önce temizle.
    const fresh = (element) => {
        element.getAnimations().forEach((animation) => animation.cancel());

        return element;
    };

    const pop = (element, from = 0.75) => fresh(element).animate(
        [{ transform: `scale(${from})` }, { transform: 'scale(1)' }],
        { duration: 180, easing: 'cubic-bezier(.3, 1.6, .5, 1)' },
    );

    // Cevapta çubuk olduğu yerde durur, kızaran kenar söner.
    const stopTimer = () => {
        timers.forEach((animation) => animation.pause());
        warning?.cancel();
        hushTicks?.();
        hushTicks = null;
    };

    const runTimer = (duration) => {
        timers.forEach((animation) => animation.cancel());
        timers = [
            timerBar.animate([{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }], { duration, easing: 'linear', fill: 'forwards' }),
            // Son kısımda çubuk pembeye döner, ekran kenarı kızarır.
            timerHot.animate([{ opacity: 0 }, { opacity: 0, offset: 0.55 }, { opacity: 1 }], { duration, fill: 'forwards' }),
        ];
        warning = danger.animate([{ opacity: 0 }, { opacity: 0, offset: 0.6 }, { opacity: 0.85 }], { duration, fill: 'forwards' });
        // Kenar kızarırken tik sesleri.
        hushTicks = sound.warn(duration);
    };

    const burst = (color) => {
        for (let i = 0; i < 9; i++) {
            const bit = document.createElement('span');
            bit.className = 'tk-bit';
            bit.style.background = color;
            arena.append(bit);

            const angle = (Math.PI * 2 * i) / 9 + Math.random() * 0.5;
            const distance = 70 + Math.random() * 60;
            bit.animate([
                { transform: 'translate(0, 0) rotate(0deg) scale(1)', opacity: 1 },
                { transform: `translate(${Math.cos(angle) * distance}px, ${Math.sin(angle) * distance}px) rotate(${180 + Math.random() * 180}deg) scale(.4)`, opacity: 0 },
            ], { duration: 520, easing: 'cubic-bezier(.2, .8, .3, 1)' }).finished.then(() => bit.remove(), () => bit.remove());
        }
    };

    const floatUp = (text) => {
        const node = document.createElement('span');
        node.className = 'tk-float';
        node.textContent = text;
        arena.append(node);
        node.animate([
            { transform: 'translate(-50%, 0) scale(.8)', opacity: 1 },
            { transform: 'translate(-50%, -70px) scale(1.1)', opacity: 0 },
        ], { duration: 600, easing: 'cubic-bezier(.2, .8, .3, 1)' }).finished.then(() => node.remove(), () => node.remove());
    };

    const showStreak = () => {
        if (streak >= 3) {
            streakEl.hidden = false;
            streakEl.textContent = `${streak} seri`;
            streakEl.animate([{ transform: 'rotate(6deg) scale(1.35)' }, { transform: 'rotate(6deg) scale(1)' }], { duration: 220, easing: 'cubic-bezier(.3, 1.6, .5, 1)' });
        } else if (! streakEl.hidden) {
            streakEl.animate([
                { transform: 'rotate(6deg) scale(1)', opacity: 1 },
                { transform: 'rotate(-14deg) scale(.4) translateY(30px)', opacity: 0 },
            ], { duration: 320, easing: 'ease-in' }).finished.then(() => {
                streakEl.hidden = streak < 3;
            });
        }
    };

    // Tuş karakterleri: doğruda gözleri güler ve zıplar, yanlışta üzülüp sallanır.
    const react = (key, mood) => {
        if (! key) {
            return;
        }

        key.classList.remove('is-happy', 'is-sad');
        void key.offsetWidth;
        key.classList.add(mood);
        window.setTimeout(() => key.classList.remove(mood), mood === 'is-happy' ? 420 : 600);
        key.querySelector('.tk-face')?.animate(mood === 'is-happy'
            ? [{ transform: 'translateX(-50%) translateY(0)' }, { transform: 'translateX(-50%) translateY(-7px)' }, { transform: 'translateX(-50%) translateY(0)' }]
            : [{ transform: 'translateX(-50%)' }, { transform: 'translateX(calc(-50% - 4px))' }, { transform: 'translateX(calc(-50% + 4px))' }, { transform: 'translateX(-50%)' }],
        { duration: 300, easing: 'ease-out' });
    };

    // Her 5'lik seride övgü.
    const PRAISE = ['Süper!', 'Harika!', 'Müthiş!', 'Efsane!', 'Durdurulamaz!'];
    const praise = (text) => {
        praiseEl.textContent = text;
        fresh(praiseEl).animate([
            { opacity: 0, transform: 'scale(.4) rotate(-8deg)' },
            { opacity: 1, transform: 'scale(1.12) rotate(3deg)', offset: 0.25 },
            { opacity: 1, transform: 'scale(1) rotate(0deg)', offset: 0.7 },
            { opacity: 0, transform: 'scale(1.05) translateY(-14px)' },
        ], { duration: 950, easing: 'ease-out' });
    };

    // Konfeti: marka renklerinde, ekranın üstünden yağar.
    const confetti = (count = 40) => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        for (let i = 0; i < count; i++) {
            const bit = document.createElement('i');
            bit.style.left = `${Math.random() * window.innerWidth}px`;
            bit.style.background = INKS[i % 4];
            confettiBox.append(bit);
            bit.animate([
                { transform: 'translate(0, 0) rotate(0deg)' },
                { transform: `translate(${(Math.random() - 0.5) * 180}px, ${window.innerHeight + 60}px) rotate(${(Math.random() - 0.5) * 900}deg)` },
            ], { duration: 1500 + Math.random() * 900, delay: Math.random() * 300, easing: 'cubic-bezier(.25, .6, .4, 1)' })
                .finished.then(() => bit.remove(), () => bit.remove());
        }
    };

    const remember = (key, value) => {
        try {
            localStorage.setItem(key, String(value));
        } catch (error) {
            // Gizli sekmede yazılamayabilir.
        }
    };

    const recall = (key) => {
        try {
            return localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    };

    const hit = (color) => {
        react(keys[color], 'is-happy');
        burst(INKS[color]);
        floatUp('+1');
        scoreEl.textContent = String(score);
        scoreEl.animate([{ transform: 'scale(1.5)' }, { transform: 'scale(1)' }], { duration: 260, easing: 'cubic-bezier(.3, 1.6, .5, 1)' });
        fresh(word).animate([{ transform: 'scale(1)', opacity: 1 }, { transform: 'scale(1.25)', opacity: 0 }], { duration: 150, easing: 'ease-out', fill: 'forwards' });
        showStreak();
        sound.hit(streak);

        if (streak % 5 === 0) {
            sound.streak();
            praise(PRAISE[Math.min(streak / 5 - 1, PRAISE.length - 1)]);
        }

        if (streak % 10 === 0) {
            confetti(30);
        }

        buzz(12);
    };

    const miss = (correctColor, timedOut, pressed) => {
        // Basılan karakter üzülür; süre dolduysa hepsi birden.
        (timedOut ? keys : [keys[pressed]]).forEach((key) => react(key, 'is-sad'));

        const heart = hearts[lives];
        heart?.animate([
            { transform: 'scale(1) rotate(0deg)' },
            { transform: 'scale(1.5) rotate(-12deg)' },
            { transform: 'scale(1) rotate(0deg)' },
        ], { duration: 380, easing: 'ease-out' });
        heart?.classList.add('is-lost');

        arena.animate([
            { transform: 'translateX(0)' },
            { transform: 'translateX(-12px)' },
            { transform: 'translateX(10px)' },
            { transform: 'translateX(-6px)' },
            { transform: 'translateX(0)' },
        ], { duration: 320, easing: 'ease-out' });
        danger.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 450, easing: 'ease-out' });

        // Doğru renk bir an parlar: oyuncu neyi kaçırdığını görsün.
        keys[correctColor]?.animate([
            { transform: 'scale(1)' },
            { transform: 'scale(1.08)' },
            { transform: 'scale(1)' },
        ], { duration: 360, easing: 'ease-out' });

        hint.textContent = timedOut ? 'Süre doldu!' : 'Kelimeye değil, renge bas!';
        hint.hidden = false;
        showStreak();
        timedOut ? sound.timeout() : sound.miss();
        buzz([45, 35, 45]);
    };

    /* ── Akış ────────────────────────────────────────────────── */

    const next = () => {
        const [wordIndex, ink] = questions[index];
        limit = timeLimit(index + 1);
        word.classList.remove('is-small');
        word.textContent = WORDS[wordIndex];
        word.style.color = INKS[ink];
        pop(word);

        if (index === 0) {
            sound.go();
        }

        // Süre, sorunun ekrana çizildiği kareden ölçülür.
        requestAnimationFrame((frameTime) => {
            if (ended) {
                return;
            }

            shownAt = frameTime;
            accepting = true;
            runTimer(limit);
            timeout = window.setTimeout(() => answer(-1, limit, performance.now(), ''), limit);
        });
    };

    // at: dokunuşun (ya da dolan sürenin) anı; type: dokunuşun türü;
    // x, y: dokunuşun tuştaki yeri (binde), süre dolunca yok.
    const answer = (color, ms, at, type, x, y) => {
        if (! accepting) {
            return;
        }

        accepting = false;
        window.clearTimeout(timeout);
        stopTimer();

        // Süre dolduktan sonra gelen dokunuş da "süre doldu" sayılır.
        const timedOut = color < 0 || ms > limit;
        const pressed = timedOut ? -1 : color;
        const reaction = timedOut ? limit : Math.max(0, Math.round(ms));
        const ink = questions[index][1];
        const correct = pressed === ink;

        answers.push([pressed, reaction]);

        if (! practice) {
            save();
            // Sunucuya hemen: geliş anını kendi saatiyle yazar, sonradan
            // değiştirilemez. Ulaşmazsa sondaki liste tamamlar.
            send(answerUrl, {
                token, i: index, c: pressed, r: reaction, k: Math.max(0, Math.round(at - origin)), p: (type || '').charAt(0),
                ...(x === undefined ? {} : { x, y }),
            }).catch(() => {});
        }
        hint.hidden = true;

        if (correct) {
            score++;
            streak++;
            hit(ink);
        } else {
            lives--;
            streak = 0;
            miss(ink, timedOut, pressed);
        }

        index++;

        if (lives <= 0 || index >= questions.length) {
            end();

            return;
        }

        window.setTimeout(next, correct ? gapHit : gapMiss);
    };

    const end = async () => {
        ended = true;
        accepting = false;
        stopTimer();
        pad.dataset.state = 'idle';
        window.setTimeout(() => sound.gameOver(), 150);
        buzz([60, 40, 120]);

        // Sahnenin arkasında, sahne kapanınca görünen durum.
        word.classList.add('is-small');
        word.style.color = INKS[3];
        word.textContent = 'Oyun bitti!';
        hint.hidden = false;
        hint.textContent = guest
            ? `${score} doğru. Misafir oyunuydu; kayıt olsaydın sıralamaya girerdi.`
            : (practice ? `${score} doğru. Pratik oyundu, sıralamaya girmez.` : `${score} doğru. Skorun kaydediliyor…`);

        // Skor sahne oynarken gönderilir.
        const saving = practice ? null : submit(false);

        await wait(ms(380)); // son cevabın sarsıntısı görünsün
        await outro();

        if (practice) {
            await wait(ms(700));
            await closeStage();

            // Düğmeler tuşların yerinde çıkar: oyun biterken hızla basan
            // parmak yanlışlıkla yeniden başlatmasın diye kısa süre kapalı.
            const again = root.querySelector('[data-again]');

            if (guest) {
                root.querySelector('[data-guest-score]').textContent = String(score);
            }

            again.style.pointerEvents = 'none';
            again.hidden = false;
            again.animate([{ opacity: 0, transform: 'scale(.9)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 220, easing: 'cubic-bezier(.3, 1.6, .5, 1)' });
            window.setTimeout(() => {
                again.style.pointerEvents = '';
            }, 700);

            return;
        }

        const url = await saving;
        await wait(ms(500));

        if (url) {
            leaveFullscreen();
            window.location.replace(url);
        }
    };

    // Skoru gönderir; bağlantı yoksa bekleyip yeniden dener. Oturum zaten
    // kapanmışsa (ör. sayfadan çıkarken gönderilmiş) oyun sayfası skoru açar.
    const submit = async (abandoned) => {
        for (let attempt = 0; ; attempt++) {
            try {
                const response = await send(finishUrl, { token, answers: JSON.stringify(answers), abandoned: abandoned ? '1' : '0' });

                if (response.ok) {
                    const { url } = await response.json();
                    try {
                        localStorage.removeItem(storageKey);
                    } catch (error) {
                        // önemli değil
                    }

                    return url;
                }

                if (response.status === 422 || response.status === 419) {
                    return playUrl;
                }
            } catch (error) {
                // Bağlantı yok: aşağıda bekle, yeniden dene.
            }

            hint.textContent = 'Bağlantı bekleniyor, skorun telefonunda duruyor…';

            if (! stage.hidden) {
                stageLabel.textContent = 'Bağlantı bekleniyor…';
            }
            await wait(Math.min(1000 * 2 ** attempt, 8000));
        }
    };

    /* ── Sahne: başlangıç ve bitiş ───────────────────────────── */

    const stage = document.querySelector('[data-stage]');
    const stageTitle = stage.querySelector('[data-stage-title]');
    const stageLabel = stage.querySelector('[data-stage-label]');
    const stageCount = stage.querySelector('[data-stage-count]');
    const stageColors = [...stage.querySelectorAll('.tk-stage__color')];
    const miniTitle = document.querySelector('[data-mini-title]');
    const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const ms = (duration) => (calm ? 0 : duration);
    let layer = 1;

    // Renk katmanı en üste çıkıp belirir: zemin sırayla dört rengi yapar.
    const paintStage = (index, duration = 280) => {
        const color = stageColors[index % stageColors.length];
        color.style.zIndex = String(++layer);

        return fresh(color).animate([{ opacity: 0 }, { opacity: 1 }], { duration: ms(duration), easing: 'ease-out', fill: 'forwards' });
    };

    // Ortadaki büyük ad kendi boyunda çizilir, küçük adın yerinden büyür
    // (FLIP): yazı büyürken bulanıklaşmaz.
    const fromMini = () => {
        const from = miniTitle.getBoundingClientRect();
        const to = stageTitle.getBoundingClientRect();
        const dx = from.left + from.width / 2 - (to.left + to.width / 2);
        const dy = from.top + from.height / 2 - (to.top + to.height / 2);

        return `translate(${dx}px, ${dy}px) scale(${from.width / to.width})`;
    };

    const starBox = stage.querySelector('[data-stars]');
    const stars = [...stage.querySelectorAll('[data-star]')];
    const bestEl = stage.querySelector('[data-stage-best]');

    const resetStage = () => {
        stage.hidden = false;
        layer = 1;
        stageColors.forEach((color) => {
            fresh(color).style.zIndex = '1';
        });
        fresh(stageLabel).style.opacity = '0';
        stageLabel.textContent = '';
        fresh(stageCount).textContent = '';
        fresh(stageTitle).style.opacity = '0';
        starBox.hidden = true;
        stars.forEach((star) => star.classList.remove('is-on'));
        bestEl.hidden = true;
    };

    const flyIn = () => {
        const from = fromMini();
        stageTitle.style.opacity = '';
        miniTitle.style.visibility = 'hidden';

        return stageTitle.animate([{ transform: from }, { transform: 'none' }], {
            duration: ms(620),
            easing: 'cubic-bezier(.2, .9, .25, 1.12)',
            fill: 'forwards',
        }).finished;
    };

    const say = (text) => {
        stageLabel.textContent = text;
        stageLabel.style.opacity = '1';
        pop(stageLabel, 0.6);
    };

    const show = (text) => {
        stageCount.textContent = text;
        pop(stageCount, 0.35);
    };

    // Ad küçülerek yerine döner; zemin ve yazılar söner.
    const closeStage = async () => {
        const to = fromMini();
        const fades = [...stageColors, stageLabel, stageCount].map((element) => element.animate(
            [{ opacity: getComputedStyle(element).opacity }, { opacity: 0 }],
            { duration: ms(380), easing: 'ease-in', fill: 'forwards' },
        ));

        await fresh(stageTitle).animate([{ transform: 'none' }, { transform: to }], {
            duration: ms(520),
            easing: 'cubic-bezier(.5, 0, .2, 1)',
            fill: 'forwards',
        }).finished;

        miniTitle.style.visibility = '';
        stage.hidden = true;
        fades.forEach((animation) => animation.cancel());
    };

    // Başlangıç: ad ortaya büyür, "Oyun başlıyor", 3-2-1 (her saniye zemin
    // bir sonraki renk), sonra ad yerine döner ve ilk soru gelir. Sorular
    // geri sayım sürerken sunucudan gelir; bağlantı yavaşsa sonda beklenir.
    const intro = async (settled, loading) => {
        resetStage();
        paintStage(0, 260);
        await settled;
        await flyIn();
        say('Oyun başlıyor');

        for (const step of [3, 2, 1]) {
            if (step < 3) {
                paintStage(3 - step);
            }

            show(String(step));
            sound.count();
            buzz(8);
            await wait(ms(1000) || 250);
        }

        if (! questions.length) {
            stageCount.textContent = '';
            say('Bağlanıyor…');
            await loading;
        }

        paintStage(3, 200);
        say('Başla!');
        stageCount.textContent = '';
        await wait(ms(260));
        await closeStage();
    };

    // Bitiş: zemin kutlama gibi dört rengi döner, ad ortaya büyür, skor
    // sayarak yükselir; turkuazda durur (sonuç sayfasıyla aynı zemin).
    const outro = async () => {
        resetStage();
        paintStage(0, 200);

        // Zemin ad uçarken dönmeye başlar: 200 ms'de bir sonraki renk, iki tur.
        const cycling = (async () => {
            for (let i = 1; i <= (calm ? 0 : 8); i++) {
                await wait(200);
                paintStage(i, 170);
            }
        })();

        await flyIn();
        say(guest ? 'Misafir oyunu' : 'Oyun bitti!');

        const counting = new Promise((resolve) => {
            const started = performance.now();
            const duration = ms(900);
            const tick = (now) => {
                const progress = duration ? Math.min(1, (now - started) / duration) : 1;
                stageCount.textContent = String(Math.round(score * (1 - (1 - progress) ** 3)));

                if (progress < 1) {
                    requestAnimationFrame(tick);
                } else {
                    pop(stageCount, 0.6);
                    resolve();
                }
            };
            requestAnimationFrame(tick);
        });

        await Promise.all([cycling, counting]);

        // Yıldızlar: 8, 16 ve 24 doğru; kazanılanlar sırayla dolar.
        const earned = STAR_AT.filter((at) => score >= at).length;
        starBox.hidden = false;

        for (let i = 0; i < stars.length; i++) {
            if (i < earned) {
                stars[i].classList.add('is-on');
                sound.star(i);
            }

            pop(stars[i], i < earned ? 0.3 : 0.8);
            await wait(ms(230));
        }

        // Kendi rekoru (bu telefonda): kırılınca kutlama.
        const best = Number(recall(BEST_KEY) || 0);

        if (score > best) {
            remember(BEST_KEY, score);

            if (best > 0) {
                bestEl.hidden = false;
                pop(bestEl, 0.5);
                sound.cheer();
            }
        }

        if (earned === stars.length || (score > best && best > 0)) {
            confetti();
        }
    };

    /* ── Öğretici: sıralamalı oyundan önce üç adım ────────────── */

    // Oyunun kendi tuşlarıyla, süresiz (son adım hariç); can gitmez, süre
    // sunucuda sayılmaz ("Başla"dan sonra başlar).
    const COLOR_NAMES = ['turkuaz', 'sarı', 'mor', 'pembe'];
    const lessons = [
        { word: 2, ink: 0, guide: true, text: 'Yazı <b>MOR</b> diyor ama rengi <b style="color:#16b8c4">turkuaz</b>. Kelimeye değil, <span style="color:#ee3d8f">rengine</span> bas!' },
        { word: 1, ink: 3, text: 'Şimdi sen: kelimeyi boş ver, <span style="color:#ee3d8f">yazının rengi</span> ne?' },
        { word: 3, ink: 1, limit: 3000, text: 'Süre bitmeden bas! Oyunda süre her soruda kısalır.' },
    ];
    const stepSticker = root.querySelector('[data-step]');
    const skipButton = root.querySelector('[data-skip]');
    let lesson = 0;
    let lessonBusy = false;
    let lessonTimer = null;
    let guide = null;

    const shake = () => arena.animate([
        { transform: 'translateX(0)' },
        { transform: 'translateX(-12px)' },
        { transform: 'translateX(10px)' },
        { transform: 'translateX(-6px)' },
        { transform: 'translateX(0)' },
    ], { duration: 320, easing: 'ease-out' });

    const pulse = (key, iterations = 2) => key.animate(
        [{ transform: 'scale(1)' }, { transform: 'scale(1.07)' }, { transform: 'scale(1)' }],
        { duration: 900, iterations, easing: 'ease-in-out' },
    );

    const clearGuide = () => {
        guide?.cancel();
        guide = null;
        keys.forEach((key) => {
            key.style.opacity = '';
        });
    };

    const showLesson = () => {
        const { word: wordIndex, ink, guide: guided, text, limit: seconds } = lessons[lesson];
        lessonBusy = false;
        stepSticker.textContent = `Nasıl oynanır · ${lesson + 1}/${lessons.length}`;
        word.classList.remove('is-small');
        word.textContent = WORDS[wordIndex];
        word.style.color = INKS[ink];
        pop(word);
        hint.hidden = false;
        hint.innerHTML = text;
        clearGuide();

        // İlk adımda doğru tuş nabız gibi atar, ötekiler söner.
        if (guided) {
            keys.forEach((key, i) => {
                key.style.opacity = i === ink ? '' : '0.45';
            });
            guide = pulse(keys[ink], Infinity);
        }

        if (seconds) {
            runTimer(seconds);
            lessonTimer = window.setTimeout(() => lessonMiss(true), seconds);
        }
    };

    const lessonMiss = (timedOut) => {
        const { ink, limit: seconds } = lessons[lesson];
        window.clearTimeout(lessonTimer);
        stopTimer();
        shake();
        danger.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 450, easing: 'ease-out' });
        timedOut ? sound.timeout() : sound.miss();
        buzz([45, 35, 45]);
        pulse(keys[ink]);
        hint.innerHTML = timedOut
            ? 'Süre doldu! Oyunda bu bir can götürür. Bir daha dene.'
            : `Kelimeye değil, rengine bak: <b style="color:${INKS[ink]}">${COLOR_NAMES[ink]}</b>.`;

        // Süreli adım baştan; ötekilerde aynı soruda yeniden denenir.
        if (seconds) {
            lessonBusy = true;
            window.setTimeout(showLesson, 1300);
        }
    };

    const lessonAnswer = (color) => {
        if (lessonBusy) {
            return;
        }

        const { ink } = lessons[lesson];

        if (color !== ink) {
            react(keys[color], 'is-sad');
            lessonMiss(false);

            return;
        }

        lessonBusy = true;
        react(keys[ink], 'is-happy');
        window.clearTimeout(lessonTimer);
        stopTimer();
        clearGuide();
        burst(INKS[ink]);
        floatUp('Doğru!');
        sound.hit(lesson + 1);
        buzz(12);
        fresh(word).animate([{ transform: 'scale(1)', opacity: 1 }, { transform: 'scale(1.25)', opacity: 0 }], { duration: 150, easing: 'ease-out', fill: 'forwards' });
        lesson++;
        window.setTimeout(lesson < lessons.length ? showLesson : ready, 650);
    };

    // Öğretici bitti (ya da geçildi): "Hazırsın!" ve Başla.
    const ready = () => {
        window.clearTimeout(lessonTimer);
        clearGuide();
        timers.forEach((animation) => animation.cancel());
        warning?.cancel();
        hushTicks?.();
        pad.dataset.state = 'idle';
        remember(TUTORIAL_KEY, '1');

        if (guest) {
            stepSticker.textContent = 'Misafir · sıralamaya girmez';
        } else {
            stepSticker.hidden = true;
        }

        skipButton.hidden = true;
        word.classList.add('is-small');
        word.style.color = '';
        word.textContent = 'Hazırsın!';
        pop(word, 0.6);
        hint.hidden = false;
        hint.innerHTML = '3 canın var: yanlış renk ya da dolan süre bir can götürür. '
            + (guest ? '<span style="color:#ee3d8f">Misafir oyunu sıralamaya girmez.</span>' : '<span style="color:#ee3d8f">Sıralamaya bu oyun girer.</span>');
        startButton.hidden = false;
        startButton.animate(
            [{ opacity: 0, transform: 'translateY(-50%) scale(.8)' }, { opacity: 1, transform: 'translateY(-50%) scale(1)' }],
            { duration: 240, easing: 'cubic-bezier(.3, 1.6, .5, 1)' },
        );
    };

    skipButton?.addEventListener('click', () => {
        sound.unlock();
        sound.tap();
        ready();
    });

    /* ── Olaylar ─────────────────────────────────────────────── */

    pad.addEventListener('pointerdown', (event) => {
        const key = event.target.closest('[data-key]');

        // Betikle üretilen dokunuş sayılmaz.
        if (! key || ! event.isTrusted || (pad.dataset.state !== 'live' && pad.dataset.state !== 'tutorial')) {
            return;
        }

        // Sıralamalı oyunda yalnız parmak (ya da kalem): fare ile oynanmaz.
        if (! practice && pad.dataset.state === 'live' && event.pointerType === 'mouse') {
            hint.hidden = false;
            hint.textContent = 'Ekrana parmağınla dokun.';

            return;
        }

        event.preventDefault();
        // Tuşun yeri basılı görünümden (küçülme) önce okunur.
        const box = key.getBoundingClientRect();
        key.classList.add('is-pressed');
        window.setTimeout(() => key.classList.remove('is-pressed'), 110);

        if (pad.dataset.state === 'tutorial') {
            lessonAnswer(Number(key.dataset.key));

            return;
        }

        // Olay zamanı kare zamanıyla aynı saatte; çok eski tarayıcıda değilse.
        const at = event.timeStamp > 1e11 ? performance.now() : event.timeStamp;

        // Soru görünmeden (ya da görünür görünmez) gelen dokunuş tahmindir,
        // cevap sayılmaz: oyuncu yeniden basar, süre işlemeye devam eder.
        if (at - shownAt < 60) {
            return;
        }

        // Dokunuşun tuştaki yeri (binde): parmak her seferinde başka bir noktaya değer.
        const spot = (value, start, size) => Math.max(0, Math.min(1000, Math.round(((value - start) / (size || 1)) * 1000)));

        answer(Number(key.dataset.key), at - shownAt, at, event.pointerType, spot(event.clientX, box.left, box.width), spot(event.clientY, box.top, box.height));
    });

    // Bu telefonun kalıcı kimliği: aynı telefondan birden çok numarayla
    // sıralamalı oyun görevliye not düşer.
    const device = () => {
        let id = recall(DEVICE_KEY);

        if (! id) {
            id = window.crypto?.randomUUID?.() || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
            remember(DEVICE_KEY, id);
        }

        return id;
    };

    // Telefonun ortam izi: otomasyon, dokunmatik nokta sayısı, ekran kartı ve
    // ekran; hepsinden kısa bir özet. Bilgisayarda telefon taklidini ve aynı
    // telefonu (kimliği silinse de) tanımaya yarar. Okunamazsa boş kalır.
    const environment = () => {
        try {
            let gpu = '';

            try {
                const gl = document.createElement('canvas').getContext('webgl');
                const info = gl?.getExtension('WEBGL_debug_renderer_info');
                gpu = String((info ? gl.getParameter(info.UNMASKED_RENDERER_WEBGL) : gl?.getParameter(gl.RENDERER)) || '');
                gl?.getExtension('WEBGL_lose_context')?.loseContext();
            } catch (error) {
                // Ekran kartı okunamıyor (kilitli mod): iz eksik kalır.
            }

            const tp = Number.isFinite(navigator.maxTouchPoints) ? navigator.maxTouchPoints : -1;
            const scr = `${screen.width}x${screen.height}@${window.devicePixelRatio || 1}`;
            const zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            const parts = [navigator.userAgent, scr, tp, gpu, navigator.language, zone, navigator.hardwareConcurrency || 0].join('|');
            let hash = 0x811c9dc5;

            for (let i = 0; i < parts.length; i++) {
                hash = Math.imul(hash ^ parts.charCodeAt(i), 0x01000193);
            }

            return { wd: navigator.webdriver ? '1' : '0', tp, gpu: gpu.slice(0, 120), scr, fp: (hash >>> 0).toString(36) };
        } catch (error) {
            return {};
        }
    };

    // Sayfa açılırken okunur: "Başla"da ekran kartı okumak geri sayımı geciktirmesin.
    const trace = practice ? {} : environment();

    // Sıralamalı oyunun soruları: süre sunucuda başlarken gelir. Bağlantı
    // yoksa bekleyip yeniden dener; oturum kapanmışsa (ör. başka sekmede
    // bitmiş) oyun sayfası skoru açar.
    const load = async () => {
        for (let attempt = 0; ; attempt++) {
            try {
                const response = await send(beginUrl, { token, device: device(), ...trace });

                if (response.ok) {
                    const data = await response.json();

                    if (Array.isArray(data.questions) && data.questions.length) {
                        questions = data.questions;

                        return questions;
                    }
                }

                if (response.status === 422 || response.status === 419) {
                    window.location.replace(playUrl);

                    return new Promise(() => {});
                }
            } catch (error) {
                // Bağlantı yok: bekle, yeniden dene.
            }

            await wait(Math.min(4000, 700 * (attempt + 1)));
        }
    };

    startButton?.addEventListener('click', async () => {
        if (begun) {
            return;
        }

        begun = true;
        origin = performance.now();
        const loading = practice ? Promise.resolve(questions) : load();
        const settled = Promise.race([fullscreen(), wait(450)]);
        sound.unlock();
        sound.start();
        startButton.animate([{ opacity: 1, transform: 'translateY(-50%) scale(1)' }, { opacity: 0, transform: 'translateY(-50%) scale(.8)' }], { duration: 160, fill: 'forwards' })
            .finished.then(() => startButton.remove());
        keepAwake();

        hint.hidden = true;
        await intro(settled, loading);
        pad.dataset.state = 'live';
        next();
    });

    // Sıralamalı oyun sürerken logoya yanlışlıkla dokunmak oyunu bitirmesin.
    document.querySelector('[data-game-home]')?.addEventListener('click', (event) => {
        if (begun && ! ended && ! practice) {
            event.preventDefault();
        }
    });

    // Uygulama hissi: ekran parmakla kaymaz, iki parmakla yakınlaşmaz.
    document.addEventListener('touchmove', (event) => event.preventDefault(), { passive: false });
    document.addEventListener('gesturestart', (event) => event.preventDefault());

    // Oyun sürerken sayfadan çıkılırsa o ana kadarki skor gönderilir;
    // yarıda bırakıp yeniden başlamak yok.
    window.addEventListener('pagehide', () => {
        if (begun && ! ended && ! practice) {
            navigator.sendBeacon?.(finishUrl, form({ token, answers: JSON.stringify(answers), abandoned: '1' }));
        }
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted && begun) {
            window.location.replace(practice ? window.location.href : playUrl);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && begun && ! ended && ! wakeLock) {
            keepAwake();
        }
    });

    // Ses ancak bir dokunuştan sonra açılabilir: öğreticideki ilk dokunuş açar.
    pad.addEventListener('click', () => sound.unlock());

    // Sıralamalı oyun yalnız dokunmatik ekrandan (telefon, tablet): bilgisayarda
    // betikle oynamanın önü kesilir. Pratik ve misafir her yerde açık.
    if (! practice && root.dataset.resume !== '1' && ! window.matchMedia('(any-pointer: coarse)').matches) {
        pad.dataset.state = 'idle';
        stepSticker?.setAttribute('hidden', '');
        skipButton?.setAttribute('hidden', '');
        startButton?.remove();
        word.classList.add('is-small');
        word.style.color = '';
        word.textContent = 'Telefondan oyna';
        hint.hidden = false;
        hint.textContent = 'Sıralamalı oyun telefonda ya da tablette oynanır. Bu sayfayı telefonundan aç.';
    } else if (pad.dataset.state === 'tutorial') {
        // Öğreticiyi bu telefonda daha önce gören doğrudan "Hazırsın"a geçer.
        recall(TUTORIAL_KEY) === '1' ? ready() : showLesson();
    }

    // Oyun başlamışken sayfa yenilendiyse: telefonda kalan cevaplarla bitir.
    if (root.dataset.resume === '1') {
        begun = true;
        ended = true;
        answers = saved();
        submit(true).then((url) => url && window.location.replace(url));
    }
}
