<?php

namespace App\Support;

/**
 * Stant oyununda yeni hesabın adı. Ad stanttaki ekranda (TV) ve sıralamada
 * görünür; 20 Ekim 2026'da tek telefondan açılan 118 "APOYUDOMALTAN" hesabı
 * sıralamaya girdi. Ad soyad istenir, küfür ve hakaret reddedilir.
 *
 * Kök listesi gerçek adlara takılmayacak biçimde seçildi ve sitedeki 1371 ada
 * karşı denendi: "sik" hiç aranmaz (Işık, Şık soyadı), "amina" (Amina adı) ve
 * "kaltak" (Kaltakkıran) aranmaz. Kısa kökler yalnız tam kelime olarak aranır.
 */
class PlayerName
{
    /** Kelimenin içinde geçmesi yeten kökler (Türkçe harfler sadeleşmiş, tekrar eden harf teke inmiş). */
    private const ROOTS = [
        'siktir', 'sikerim', 'sikeyim', 'sikiyim', 'sikim', 'sikis', 'sikik', 'siken', 'sikici', 'sikti', 'sikmek',
        'domal', 'orospu', 'orosbu', 'oruspu', 'yarak', 'yaragi', 'amcik', 'aminakoy', 'aminako', 'pezevenk',
        'pezeveng', 'kahpe', 'yavsak', 'gotveren', 'gotlek', 'surtuk', 'ibne', 'tasak', 'penis', 'vajina',
        'serefsiz', 'gerizekali', 'fuck', 'bitch',
    ];

    /** Yalnız tam kelime olarak reddedilenler. */
    private const WORDS = ['amk', 'amq', 'aq', 'mk', 'oc', 'pic', 'got', 'am', 'gavat', 'pust', 'salak', 'aptal'];

    /** Yeni hesapta adın sorunu; sorun yoksa null. */
    public static function problem(string $name): ?string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $real = array_filter($words, fn (string $word) => mb_strlen((string) preg_replace('/[^\p{L}]/u', '', $word)) >= 2);

        if (count($real) < 2) {
            return 'Adını ve soyadını yaz.';
        }

        if (preg_match('/[^\p{L}\p{M}\s\'’.\-]/u', $name)) {
            return 'Adında yalnız harf olsun.';
        }

        return self::rude($name) ? 'Adını ve soyadını gerçek hâliyle yaz.' : null;
    }

    /**
     * Aynı ad mı: Türkçe harf, büyük-küçük, boşluk ve tekrar eden harf farkı
     * yok sayılır ("Berkay Demircanlı" = "Berkay Demircanli", "Furykan" = "Furykann").
     */
    public static function same(?string $a, ?string $b): bool
    {
        $key = fn (?string $name) => (string) preg_replace('/(.)\1+/', '$1', (string) preg_replace('/[^a-z]/', '', self::fold((string) $name)));

        return $key($a) !== '' && $key($a) === $key($b);
    }

    public static function rude(?string $name): bool
    {
        foreach (preg_split('/\s+/u', self::fold((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // Arada nokta, tire, rakam: "s.i.k.e.n", "d0mal" de yakalanır.
            $word = (string) preg_replace('/(.)\1+/', '$1', (string) preg_replace('/[^a-z]/', '', $word));

            if (in_array($word, self::WORDS, true) || array_filter(self::ROOTS, fn (string $root) => str_contains($word, $root))) {
                return true;
            }
        }

        return false;
    }

    private static function fold(string $text): string
    {
        $text = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');

        return strtr($text, [
            'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u',
            '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '$' => 's', '@' => 'a',
        ]);
    }
}
