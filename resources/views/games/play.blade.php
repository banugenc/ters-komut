@extends('games.layout')

@section('title', 'Oyna')
@section('viewport', 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover')
@section('body-class', 'tk-game')

@push('head')
    <style>
        /* Uygulama gibi: oyun ekranı telefonun tamamını kullanır (çentik ve
           alt çubuk payı bırakılır), kaymaz; çekip yenileme, yakınlaştırma
           ve uzun basış menüsü kapalı. Android'de "Başla" tam ekran açar. Bütün hareket
           transform/opacity ile; süre çubuğu tek bir animasyon. */
        html, body { overscroll-behavior: none; }
        .tk-game { touch-action: manipulation; -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
        .tk-game main { height: 100vh; height: 100dvh; min-height: 0; overflow: hidden; padding: max(0.75rem, env(safe-area-inset-top)) max(1rem, env(safe-area-inset-right)) max(1rem, env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-left)); }
        .tk-play { display: flex; flex: 1; min-height: 0; flex-direction: column; }
        .tk-heart { width: 2.25rem; height: 2.25rem; color: var(--berry); }
        .tk-heart.is-lost { color: #c9cdd6; }
        .tk-timer { position: relative; height: 1.25rem; flex: 1; overflow: hidden; border: 3px solid var(--ink); border-radius: 999px; background: #fff; }
        .tk-timer__bar { position: absolute; inset: 0; transform-origin: left center; background: var(--sun); will-change: transform; }
        .tk-timer__hot { position: absolute; inset: 0; background: var(--berry); opacity: 0; }
        .tk-arena { position: relative; display: flex; flex: 1; min-height: 0; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; margin-top: 0.8rem; padding: 1rem; text-align: center; }
        .tk-word { font-family: 'Paytone One', sans-serif; font-size: min(4.6rem, 15.5vw); line-height: 1; color: var(--ink); -webkit-text-stroke: 4px var(--ink); paint-order: stroke fill; text-shadow: 0 5px 0 var(--ink); will-change: transform; }
        .tk-word.is-small { font-size: min(3.2rem, 12vw); -webkit-text-stroke-width: 0; text-shadow: none; }
        .tk-hint { margin-top: 1rem; max-width: 17rem; font-size: 0.95rem; font-weight: 800; color: #5b5f73; }
        .tk-streak { position: absolute; top: 0.8rem; right: 0.8rem; transform: rotate(6deg); }
        .tk-float { position: absolute; left: 50%; top: 42%; font-family: 'Paytone One', sans-serif; font-size: 2rem; color: var(--ink); pointer-events: none; }
        .tk-bit { position: absolute; left: 50%; top: 45%; width: 0.7rem; height: 0.7rem; margin: -0.35rem 0 0 -0.35rem; border: 2px solid var(--ink); border-radius: 3px; pointer-events: none; }
        .tk-pad { position: relative; margin-top: 0.9rem; }
        .tk-key { height: clamp(5rem, 16vh, 8.5rem); height: clamp(5rem, 16dvh, 8.5rem); border: 3px solid var(--ink); border-radius: 20px; box-shadow: 0 6px 0 var(--ink); transition: transform 0.06s ease, opacity 0.2s ease; -webkit-tap-highlight-color: transparent; }
        .tk-key.is-pressed { transform: translateY(5px); box-shadow: 0 1px 0 var(--ink); }
        .tk-pad[data-state='idle'] .tk-key { opacity: 0.45; }
        .tk-start { position: absolute; left: 0; right: 0; top: 50%; transform: translateY(-50%); }
        /* Basılınca yalnız 5px iner: ortalama kaybolursa düğme parmağın altından
           kaçıyor, dokunuş boşa gidiyordu (3-4 kez basmak gerekiyordu). */
        .tk-btn.tk-start:active { transform: translateY(calc(-50% + 5px)); }
        .tk-practice { position: absolute; top: 0.8rem; left: 0.8rem; transform: rotate(-4deg); }
        .tk-skip { position: absolute; bottom: 0.7rem; left: 50%; transform: translateX(-50%); padding: 0.4rem 0.8rem; font-size: 0.85rem; font-weight: 900; color: #5b5f73; text-decoration: underline; text-underline-offset: 3px; -webkit-tap-highlight-color: transparent; }
        .tk-skip[hidden] { display: none; }
        /* Renk karakterleri: iki göz ve küçük ağız; ara sıra göz kırpar. */
        .tk-key { position: relative; overflow: hidden; }
        .tk-face { position: absolute; left: 50%; top: 22%; display: flex; gap: 0.55rem; align-items: center; transform: translateX(-50%); pointer-events: none; }
        .tk-eye { position: relative; display: block; width: 0.9rem; height: 1.05rem; border: 2.5px solid var(--ink); border-radius: 50%; background: #fff; transform-origin: 50% 50%; animation: tk-blink 4.2s infinite; }
        .tk-eye::after { content: ''; position: absolute; left: 50%; top: 22%; width: 0.42rem; height: 0.48rem; margin-left: -0.21rem; border-radius: 50%; background: var(--ink); }
        .tk-mouth { position: absolute; left: 50%; top: 1.3rem; width: 0.7rem; height: 0.35rem; margin-left: -0.35rem; border: 2.5px solid var(--ink); border-top: 0; border-radius: 0 0 0.7rem 0.7rem; }
        .tk-key:nth-child(2) .tk-eye { animation-duration: 5.1s; animation-delay: -1.3s; }
        .tk-key:nth-child(3) .tk-eye { animation-duration: 3.7s; animation-delay: -2.4s; }
        .tk-key:nth-child(4) .tk-eye { animation-duration: 4.6s; animation-delay: -0.6s; }
        @keyframes tk-blink { 0%, 93%, 100% { transform: scaleY(1); } 96% { transform: scaleY(0.12); } }
        /* Doğru: gözler gülümser (^ ^). Yanlış: gözler kısılır, ağız ters döner. */
        .tk-key.is-happy .tk-eye { height: 0.5rem; border-bottom: 0; border-radius: 0.5rem 0.5rem 0 0; background: transparent; animation: none; }
        .tk-key.is-happy .tk-eye::after { display: none; }
        .tk-key.is-sad .tk-eye { transform: scaleY(0.4); animation: none; }
        .tk-key.is-sad .tk-mouth { top: 1.45rem; border-top: 2.5px solid var(--ink); border-bottom: 0; border-radius: 0.7rem 0.7rem 0 0; }
        .tk-praise { position: absolute; left: 0; right: 0; top: 16%; z-index: 2; font-family: 'Paytone One', sans-serif; font-size: 2.3rem; line-height: 1; color: #fff; -webkit-text-stroke: 5px var(--ink); paint-order: stroke fill; text-shadow: 0 4px 0 var(--ink); opacity: 0; pointer-events: none; }
        .tk-confetti { position: fixed; inset: 0; z-index: 60; overflow: hidden; pointer-events: none; }
        .tk-confetti i { position: absolute; top: -20px; width: 10px; height: 14px; border: 2px solid var(--ink); border-radius: 3px; }
        .tk-stars { display: flex; gap: 0.6rem; margin-top: 0.8rem; }
        .tk-stars[hidden] { display: none; }
        .tk-stage__best { margin-top: 0.6rem; font-size: 1.5rem; color: var(--sun); }
        .tk-stage__best[hidden] { display: none; }
        @media (prefers-reduced-motion: reduce) { .tk-eye { animation: none; } }
        .tk-again { position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: center; gap: 0.75rem; }
        .tk-again[hidden] { display: none; }
        /* Misafir sonucu: tam ekran, oyunun kendi zemini; çevresi ince kırmızı çerçeve, üstte ve altta "misafir" yazan şeritler
           yavaşça karşılıklı akar. Kayıtlı oyuncunun sonuç sayfası turkuaz ve adlıdır; bu ekran ondan ilk bakışta
           ayrılır, stantta ödül ya da hediye için gösterilemez. Ama korkutmaz: oyunun çıkartması, konturlu skor.
           (Koyu zemin + kırmızı damga + hızlı büyük harf şerit "çok korkunç", beyaz zemin "normal oyun olsun" bulundu.) */
        .tk-misafir { --kirmizi: #e3262f; position: fixed; inset: 0; z-index: 60; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: calc(3.4rem + env(safe-area-inset-top)) 1.75rem calc(3.4rem + env(safe-area-inset-bottom)); background-color: var(--sky); background-image: repeating-linear-gradient(135deg, rgba(255, 255, 255, 0.09) 0 26px, transparent 26px 52px); box-shadow: inset 0 0 0 8px var(--kirmizi); color: var(--ink); text-align: center; }
        .tk-misafir[hidden] { display: none; }
        .tk-misafir__serit { position: absolute; left: 0; right: 0; display: flex; align-items: center; overflow: hidden; height: calc(2rem + env(safe-area-inset-top)); padding-top: env(safe-area-inset-top); background: var(--kirmizi); white-space: nowrap; }
        .tk-misafir__serit--ust { top: 0; }
        .tk-misafir__serit--alt { bottom: 0; height: calc(2rem + env(safe-area-inset-bottom)); padding-top: 0; padding-bottom: env(safe-area-inset-bottom); }
        .tk-misafir__serit span { display: inline-block; flex: none; font-size: 0.95rem; font-weight: 900; white-space: pre; color: #fff; animation: tk-serit 40s linear infinite; }
        .tk-misafir__serit--alt span { animation-direction: reverse; }
        @keyframes tk-serit { to { transform: translateX(-50%); } }
        @media (prefers-reduced-motion: reduce) { .tk-misafir__serit span { animation: none; } }
        .tk-misafir__etiket { font-size: 1.05rem; transform: rotate(-4deg); }
        .tk-misafir__skor { display: flex; align-items: baseline; justify-content: center; gap: 0.45rem; margin-top: 1.7rem; }
        .tk-misafir__skor b { font-weight: 400; font-size: 4.4rem; line-height: 1; color: #fff; -webkit-text-stroke: 5px var(--ink); paint-order: stroke fill; text-shadow: 0 5px 0 var(--ink); }
        .tk-misafir__skor span { font-size: 1.4rem; font-weight: 900; }
        .tk-misafir__not { max-width: 21rem; margin-top: 0.9rem; font-size: 0.95rem; font-weight: 800; line-height: 1.45; color: var(--ink); }
        .tk-misafir__dugmeler { display: flex; width: 100%; max-width: 24rem; flex-direction: column; gap: 0.75rem; margin-top: 1.8rem; }
                .tk-mini-title { font-size: 1.15rem; line-height: 0.88; text-align: center; -webkit-text-stroke-width: 3.5px; text-shadow: 0 2px 0 var(--ink); }
        .tk-stage { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; overflow: hidden; touch-action: none; }
        .tk-stage[hidden] { display: none; }
        .tk-stage__color { position: absolute; inset: 0; opacity: 0; will-change: opacity; background-image: repeating-linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0 26px, transparent 26px 52px); }
        .tk-stage__body { position: relative; z-index: 1000; display: flex; flex-direction: column; align-items: center; padding: 0 1rem; text-align: center; }
        .tk-stage__title { font-size: min(4.6rem, 19vw); will-change: transform; }
        .tk-stage__label { margin-top: 1.4rem; font-size: 1.9rem; color: #fff; opacity: 0; }
        .tk-stage__count { margin-top: 0.4rem; min-height: 1em; font-size: min(7rem, 30vw); line-height: 1; color: #fff; }
        .tk-danger { position: fixed; inset: 0; z-index: 30; pointer-events: none; opacity: 0; box-shadow: inset 0 0 0 6px var(--berry), inset 0 0 70px rgba(238, 61, 143, 0.5); }
    </style>
@endpush

@push('scripts')
    @vite('resources/js/ters-komut.js')
@endpush

@section('header-right')
    <div class="flex min-w-0 flex-1 items-center gap-2.5 pl-2">
        {{-- Oyunun adı burada küçük durur; başlangıç ve bitişte ortaya büyür. --}}
        <span class="tk-logo tk-mini-title mx-auto" data-mini-title aria-label="Ters Komut">@include('games.partials.ad')</span>
        <button type="button" class="tk-card flex h-11 w-11 items-center justify-center" data-sound-toggle aria-pressed="true" aria-label="Ses">
            <svg class="h-6 w-6" data-sound-on fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke="#1b1b2f" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 010 12.728M16.463 8.288a5.25 5.25 0 010 7.424M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 012.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75z"/></svg>
            <svg class="h-6 w-6" data-sound-off hidden fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke="#1b1b2f" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75L19.5 12m0 0l2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25m-10.5-6l4.72-4.72a.75.75 0 011.28.531V19.94a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.506-1.938-1.354A9.01 9.01 0 012.25 12c0-.83.112-1.633.322-2.395C2.806 8.757 3.63 8.25 4.51 8.25H6.75z"/></svg>
        </button>
        <div class="tk-card flex items-center gap-2 px-4 py-1.5" aria-label="Skor">
            <svg class="h-6 w-6 text-[#f5b426]" viewBox="0 0 24 24" fill="currentColor" stroke="#1b1b2f" stroke-width="1.6" aria-hidden="true"><path stroke-linejoin="round" d="M12 2.8l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 16.8l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/></svg>
            <span class="tk-display text-3xl tabular-nums" data-score>0</span>
        </div>
    </div>
@endsection

@section('content')
    @php $guest = $guest ?? false; @endphp
    <div class="tk-play" data-game
         data-token="{{ $session?->token }}"
         data-csrf="{{ csrf_token() }}"
         data-begin-url="{{ route('game.begin') }}"
         data-answer-url="{{ route('game.answer') }}"
         data-finish-url="{{ route('game.finish') }}"
         data-gap-hit="{{ \App\Services\Members\GamePlay::GAP_HIT }}"
         data-gap-miss="{{ \App\Services\Members\GamePlay::GAP_MISS }}"
         data-play-url="{{ route('game.play') }}"
         data-result-url="{{ $session ? route('game.result', $session->token) : route('game.index') }}"
         data-practice="{{ $practice ? '1' : '0' }}"
         data-guest="{{ $guest ? '1' : '0' }}"
         data-resume="{{ $resume ? '1' : '0' }}">
        <section class="mt-3 flex items-center gap-3" aria-label="Canlar ve süre">
            <div class="flex gap-1">
                @for($i = 0; $i < \App\Services\Members\GamePlay::LIVES; $i++)
                    <svg class="tk-heart" viewBox="0 0 24 24" fill="currentColor" stroke="#1b1b2f" stroke-width="1.8" aria-hidden="true" data-heart><path stroke-linejoin="round" d="M12 20.3s-7.8-4.6-7.8-10.3A4.4 4.4 0 0112 7.3a4.4 4.4 0 017.8 2.7c0 5.7-7.8 10.3-7.8 10.3z"/></svg>
                @endfor
            </div>
            <div class="tk-timer" aria-hidden="true">
                <div class="tk-timer__bar" data-timer><span class="tk-timer__hot" data-timer-hot></span></div>
            </div>
        </section>

        {{-- Kısa öğretici: sıralamalı oyundan ve misafir oyunundan önce (pratikte
             yok; daha önce görülmüşse "Hazırsın"a atlanır). --}}
        @php $tutorial = (! $practice && ! $resume) || $guest; @endphp
        <section class="tk-card tk-arena" data-arena aria-live="polite">
            <span class="tk-sticker tk-streak text-sm" data-streak hidden></span>
            <p class="tk-praise" data-praise aria-hidden="true"></p>
            @if($practice && ! $guest)
                <span class="tk-sticker tk-practice text-xs" style="background:#fff">Pratik · sıralamaya girmez</span>
            @elseif($tutorial)
                <span class="tk-sticker tk-practice text-xs" style="background:#fff" data-step>Nasıl oynanır · 1/3</span>
            @endif
            @if($practice && ! $guest)
                <p class="tk-word is-small" data-word>Pratik oyun</p>
                <p class="tk-hint" data-hint>
                    Bu oyun <span style="color:#ee3d8f">sıralamaya girmez</span>. Sıralamaya ilk oyunun girdi:
                    {{ $session->campaign->formatScore((int) $session->score?->score) }} doğru.
                </p>
            @elseif($resume)
                <p class="tk-word is-small" data-word>Bir saniye</p>
                <p class="tk-hint" data-hint>Oyunun yarıda kalmıştı; o ana kadarki skorun kaydediliyor.</p>
            @elseif($tutorial)
                <p class="tk-word" data-word style="color:#16b8c4">MOR</p>
                <p class="tk-hint" data-hint>Yazı <b>MOR</b> diyor ama rengi <b style="color:#16b8c4">turkuaz</b>. Kelimeye değil, <span style="color:#ee3d8f">rengine</span> bas!</p>
                <button type="button" class="tk-skip" data-skip>Biliyorum, geç</button>
            @else
                <p class="tk-word is-small" data-word>Hazır mısın?</p>
                <p class="tk-hint" data-hint>Kelimeye değil, <span style="color:#ee3d8f">yazının rengine</span> bas. 3 canın var, süre her soruda kısalır.</p>
            @endif
        </section>

        <div class="tk-pad" data-pad data-state="{{ $tutorial ? 'tutorial' : 'idle' }}">
            <div class="grid grid-cols-2 gap-3.5" aria-label="Renkler">
                @foreach(['Turkuaz' => '#16b8c4', 'Sarı' => '#f5b426', 'Mor' => '#8b3fd9', 'Pembe' => '#ee3d8f'] as $label => $color)
                    {{-- Her renk küçük bir karakter: göz kırpar, doğruda sevinir, yanlışta üzülür. --}}
                    <button type="button" class="tk-key" style="background: {{ $color }}" data-key="{{ $loop->index }}" aria-label="{{ $label }}">
                        <span class="tk-face" aria-hidden="true"><i class="tk-eye"></i><i class="tk-eye"></i><i class="tk-mouth"></i></span>
                    </button>
                @endforeach
            </div>
            @unless($resume)
                <button type="button" class="tk-btn tk-start" data-start @if($tutorial) hidden @endif>Başla</button>
            @endunless
            @if($guest)
                {{-- Misafir bitince: oyun zemininde, kırmızı çerçeveli, "misafir" şeritli sonuç; istediği kadar yeniden
                     oynar, ama bu ekranla stantta ödül alınmaz. --}}
                <div class="tk-misafir" data-again hidden>
                    {{-- Şerit iki eş yarıdan: yarısı kadar kayınca başa döner, akış kesintisiz. --}}
                    @php $serit = str_repeat('misafir · ', 16); @endphp
                    <p class="tk-misafir__serit tk-misafir__serit--ust" aria-hidden="true"><span>{{ $serit }}{{ $serit }}</span></p>
                    <p class="tk-misafir__serit tk-misafir__serit--alt" aria-hidden="true"><span>{{ $serit }}{{ $serit }}</span></p>
                    <span class="tk-sticker tk-misafir__etiket">Misafir oyunu · ödüle geçmez</span>
                    <p class="tk-misafir__skor"><b class="tk-display" data-guest-score>0</b><span>doğru</span></p>
                    <p class="tk-misafir__not">Bu skor kaydedilmez, sıralamaya girmez. Ödül ve hediyeler yalnız kayıtlı oyunculara.</p>
                    <div class="tk-misafir__dugmeler">
                        <a href="{{ route('game.guest') }}" class="tk-btn">Tekrar oyna</a>
                        <a href="{{ route('game.index') }}#kayit" class="tk-btn tk-btn--light">Kayıt ol, ödüle katıl</a>
                    </div>
                </div>
            @elseif($practice)
                {{-- Pratik bitince: yeniden pratik ya da sıralamadaki skora dön. --}}
                <div class="tk-again" data-again hidden>
                    <a href="{{ route('game.play', ['tekrar' => 1]) }}" class="tk-btn">Tekrar oyna</a>
                    <a href="{{ route('game.result', $session->token) }}" class="tk-btn tk-btn--light">Skoruma dön</a>
                </div>
            @endif
        </div>
    </div>

    <div class="tk-danger" data-danger aria-hidden="true"></div>
    <div class="tk-confetti" data-confetti aria-hidden="true"></div>

    {{-- Başlangıç/bitiş sahnesi: ad ortaya büyür, zemin dört rengi sırayla
         yapar, geri sayım ya da skor; sonra ad yerine döner. --}}
    <div class="tk-stage" data-stage hidden aria-hidden="true">
        @foreach(['#16b8c4', '#f5b426', '#8b3fd9', '#ee3d8f'] as $stageColor)
            <span class="tk-stage__color" style="background: {{ $stageColor }}"></span>
        @endforeach
        <div class="tk-stage__body">
            <p class="tk-logo tk-bounce tk-stage__title" data-stage-title>@include('games.partials.ad')</p>
            <p class="tk-display tk-outline tk-stage__label" data-stage-label></p>
            <p class="tk-logo tk-stage__count" data-stage-count></p>
            {{-- Bitişte 1-3 yıldız (8, 16, 24 doğru). --}}
            <div class="tk-stars" data-stars hidden>
                @for($i = 0; $i < 3; $i++)
                    <svg class="tk-star" viewBox="0 0 24 24" aria-hidden="true" data-star><path stroke-linejoin="round" d="M12 2.8l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 16.8l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/></svg>
                @endfor
            </div>
            <p class="tk-display tk-outline tk-stage__best" data-stage-best hidden>Yeni rekor!</p>
        </div>
    </div>
    <script type="application/json" data-questions>@json($resume ? [] : $questions)</script>
@endsection
