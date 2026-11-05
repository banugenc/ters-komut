{{-- Sonuç ekranında oyuncunun sıralamadaki yeri. Oyunun amacı ilk 3:
     sıra, seviye, ilk 3'e uzaklık ve ilk 5 listesi. Sayfa açıkken
     /sonuc/{token}/sira ile kendiliğinden yenilenir. --}}
@php
    $game = $session->campaign;
    $rank = $standing['rank'] ?? null;
    $total = $standing['total'] ?? 0;
    $winners = $standing['winners'] ?? 3;
    $inPrize = $rank && $rank <= $winners;
    // Merdiven: solda sonuncu, sağda birinci; sağdaki ödül bölgesi altın.
    $position = $rank ? ($total > 1 ? ($total - $rank) / ($total - 1) * 100 : 100) : 0;
    $zone = $total > 0 ? min(100, $winners / $total * 100) : 100;
@endphp
<section class="tk-card mt-5 px-5 pb-5 pt-4 {{ $inPrize ? 'tk-rank--prize' : '' }}" aria-live="polite">
    @if($rank)
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="tk-label">Sıralaman{{ $game->prizeName() ? ' · ilk '.$winners.' → '.$game->prizeName() : '' }}</p>
                <p class="tk-display text-[3.4rem] leading-none tabular-nums">{{ $rank }}.</p>
                <p class="mt-1 text-sm font-extrabold text-[#5b5f73]">{{ $total }} oyuncu arasında</p>
            </div>
            <span class="tk-sticker tk-tier {{ $inPrize ? 'tk-tier--prize' : '' }}">{{ $standing['tier'] }}</span>
        </div>

        <div class="tk-ladder mt-4" aria-hidden="true">
            <span class="tk-ladder__zone" style="width: {{ round($zone, 1) }}%"></span>
            <span class="tk-ladder__me" style="left: {{ round($position, 1) }}%"></span>
        </div>
        <div class="mt-1 flex justify-between text-[0.7rem] font-extrabold text-[#7a7f91]" aria-hidden="true">
            <span>Son</span><span>İlk {{ $winners }}</span>
        </div>

        <p class="mt-3 font-extrabold">
            @if($inPrize)
                İlk {{ $winners }}'tesin! {{ $game->prizeName() ? $game->prizeName().' için' : 'Ödülün için' }} stant sonunda kimliğinle gel; sıranı koru.
            @elseif($standing['cut'] !== null)
                İlk {{ $winners }} şu an {{ $game->formatScore((int) $standing['cut']) }} doğru ve üstünde.
            @else
                İlk {{ $winners }}'te henüz yer var.
            @endif
        </p>

        <ol class="tk-board mt-4">
            @foreach($standing['top'] as $row)
                <li class="{{ $row->rank === $rank ? 'is-me' : '' }} {{ $row->rank <= $winners ? 'is-prize' : '' }}">
                    <span class="tk-board__rank">{{ $row->rank }}</span>
                    <span class="tk-board__name">{{ $row->rank === $rank ? 'Sen' : $row->name }}</span>
                    <span class="tk-board__score">{{ $game->formatScore((int) $row->best) }}</span>
                </li>
            @endforeach
            @if($rank > $standing['top']->count())
                <li class="tk-board__gap" aria-hidden="true">…</li>
                <li class="is-me">
                    <span class="tk-board__rank">{{ $rank }}</span>
                    <span class="tk-board__name">Sen</span>
                    <span class="tk-board__score">{{ $game->formatScore((int) $standing['me']->best) }}</span>
                </li>
            @endif
        </ol>
        <p class="mt-2 text-[0.72rem] font-bold text-[#7a7f91]">Eşit skorda daha hızlı cevaplayan önde. Sıralama stant sonuna kadar değişebilir.</p>
    @else
        {{-- Hile denetimi skoru işaretlediyse: görevli bakar, onaylarsa sıralamaya girer. --}}
        <p class="tk-display text-[1.4rem] leading-tight">Skorun görevli kontrolünde</p>
        <p class="mt-1 font-extrabold text-[#5b5f73]">Stanttaki görevliye uğra; onaylanınca sıralamada görünür.</p>
    @endif
</section>
