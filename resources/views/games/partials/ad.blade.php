{{-- Oyunun adı: harfler farklı renklerde (oyunun fikri de bu, yazı ile
     renk tutmaz). Giriş sayfasında büyük, oyun ekranında küçük ve başlangıç/
     bitiş sahnesinde ortada kullanılır. Kapsayıcıda .tk-bounce varsa harfler
     sırayla zıplar. --}}
@php
    $letterColors = ['#ffffff', '#f5b426', '#ee3d8f', '#8b3fd9'];
    $line = fn (string $word, int $offset, int $first) => collect(mb_str_split($word))
        ->map(fn ($char, $i) => '<span class="tk-letter" style="color:'.$letterColors[($i + $offset) % 4].';--i:'.($first + $i).'">'.e($char).'</span>')
        ->implode('');
@endphp
<span class="block">{!! $line('TERS', 0, 0) !!}</span>
<span class="block">{!! $line('KOMUT', 2, 4) !!}</span>
