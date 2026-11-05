# Ters Komut

BanüGENÇ Gençlik Topluluğu'nun Tanıtım Günleri standında telefondan oynanan renk ve kelime oyunu.

Ekranın ortasında bir renk adı yazar, fakat yazının rengi başkadır. Oyuncu kelimenin söylediğine değil, yazının rengine basar. İlk beş soruda süre 1,5 saniyedir, sonra her soruda 35 ms kısalır. Yanlış renk ya da dolan süre bir can götürür; üç can bitince oyun biter. Sıralama standdaki ekranda canlı görünür, ilk üç oyuncu ödül alır.

## Nasıl çalışır

- Oyuncu öğrenci numarası, ad soyad ve telefonla kısa bir kayıt yapar. Kayıtlı üye numarasından tanınır.
- Sorular sunucuda üretilir ve sıralamalı oyunda ancak "Başla"ya basıldığında gelir. Telefon yalnız basılan rengi ve tepki süresini gönderir; skoru sunucu hesaplar.
- Her cevap basıldığı anda sunucuya gider ve telefona da yazılır. Sayfa yenilenir ya da bağlantı koparsa o ana kadarki skor kaybolmaz.
- Oyun sonunda süreler, hız, cevap düzeni, dokunuş, tarayıcı ortamı ve aynı telefondaki hesaplar denetlenir. İşaretlenen skor sıralamadan düşer, son kararı standdaki görevli verir.
- Ad soyad alanı küfür ve hakaret içeren adları reddeder (`app/Support/PlayerName.php`).
- Ekrandaki geri bildirimler `transform` ve `opacity` ile çizilir, süre çubuğu soru başına tek animasyondur. Böylece eski telefonlarda da akıcı çalışır.

## Dosyalar

| Yol | İçerik |
| --- | --- |
| `resources/js/ters-komut.js` | Oyun ekranı: sorular, süre, can, cevap gönderimi |
| `resources/js/oyun.js`, `resources/js/oyun-ses.js` | Ortak sayfa kodu ve ses efektleri |
| `resources/views/games/` | Giriş, oyun ve sonuç sayfaları (Blade) |
| `app/Http/Controllers/GameController.php` | Kayıt, oyun akışı, sonuç ve üyelik |
| `app/Http/Controllers/GameApiController.php` | Standdaki iPad uygulamasının kullandığı API |
| `app/Services/Members/GamePlay.php` | Soru üretimi, skor hesabı ve hile denetimi |
| `app/Services/Members/GameBoard.php` | Sıralama ve kazanan seçimi |
| `app/Models/` | Oyun oturumu ve skor modelleri |
| `database/migrations/` | `game_sessions` ve `game_scores` tabloları |
| `routes/oyun.php` | Oyun rotaları |
| `tests/Feature/GamePlayTest.php` | Kayıt, oyun ve denetim testleri |

## Not

Bu depo, BanüGENÇ panelinin (Laravel 12, Filament 3, Tailwind CSS 4, Vite) içinden ayrılmış oyun dosyalarını içerir ve tek başına çalışmaz. Kullanıcı, puan, üyelik ve kampanya altyapısı panelde kalır. Dosyalar paneldeki yollarıyla durur.

Geliştiren: Tuna Karataş
