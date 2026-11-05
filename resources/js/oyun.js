/**
 * Oyun sayfalarının ortak sesleri: düğmelere basınca kısa "pop" ve ses
 * aç/kapa düğmesi. Oyunun kendi sesleri ters-komut.js'te.
 */
import { sound } from './oyun-ses';

// İkonlar SVG: .hidden özelliği SVG'de çalışmaz, nitelik değiştirilir.
const paint = (toggle) => {
    toggle.setAttribute('aria-pressed', sound.enabled ? 'true' : 'false');
    toggle.setAttribute('aria-label', sound.enabled ? 'Sesi kapat' : 'Sesi aç');
    toggle.querySelector('[data-sound-on]').toggleAttribute('hidden', ! sound.enabled);
    toggle.querySelector('[data-sound-off]').toggleAttribute('hidden', sound.enabled);
};

document.querySelectorAll('[data-sound-toggle]').forEach((toggle) => {
    paint(toggle);
    toggle.addEventListener('click', () => {
        sound.unlock();
        sound.toggle();
        paint(toggle);
        sound.tap();
    });
});

// "Başla"nın kendi sesi var.
document.addEventListener('click', (event) => {
    if (event.target.closest('.tk-btn:not([data-start])')) {
        sound.unlock();
        sound.tap();
    }
});
