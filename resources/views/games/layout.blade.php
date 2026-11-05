<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="@yield('viewport', 'width=device-width, initial-scale=1, viewport-fit=cover')">
    <meta name="theme-color" content="#16b8c4">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Ters Komut') · {{ \App\Support\Brand::name() }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=paytone-one:400|nunito:700,800,900" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/oyun.js'])
    <style>
        /* Başlık yazı tipi Paytone One: Türkçe harflerin hepsi var (Lilita One'da
           ğ, ş, İ yoktu, başka yazı tipinden geliyordu).
           Stant oyunu: gündelik mobil oyunların dili — düz parlak renkler,
           kalın siyah çerçeveli "çıkartma" kartlar, basınca gömülen düğmeler. */
        :root { --ink: #1b1b2f; --sky: #16b8c4; --sun: #f5b426; --grape: #8b3fd9; --berry: #ee3d8f; }
        .tk { font-family: 'Nunito', system-ui, sans-serif; color: var(--ink); background-color: var(--sky); background-image: repeating-linear-gradient(135deg, rgba(255, 255, 255, 0.09) 0 26px, transparent 26px 52px); }
        .tk-display { font-family: 'Paytone One', 'Nunito', sans-serif; font-weight: 400; }
        .tk-logo { font-family: 'Paytone One', sans-serif; line-height: 0.88; -webkit-text-stroke: 7px var(--ink); paint-order: stroke fill; text-shadow: 0 7px 0 var(--ink); }
        .tk-outline { -webkit-text-stroke: 5px var(--ink); paint-order: stroke fill; text-shadow: 0 5px 0 var(--ink); }
        .tk-card { background: #fff; border: 3px solid var(--ink); border-radius: 22px; box-shadow: 0 6px 0 var(--ink); }
        .tk-btn { display: flex; width: 100%; align-items: center; justify-content: center; border: 3px solid var(--ink); border-radius: 18px; padding: 0.95rem 1rem 0.85rem; font-family: 'Paytone One', sans-serif; font-size: 1.75rem; letter-spacing: 0.02em; text-transform: uppercase; color: var(--ink); background: var(--sun); box-shadow: 0 6px 0 var(--ink); transition: transform .08s ease, box-shadow .08s ease; -webkit-tap-highlight-color: transparent; }
        .tk-btn:active { transform: translateY(5px); box-shadow: 0 1px 0 var(--ink); }
        .tk-btn--berry { background: var(--berry); color: #fff; }
        .tk-btn--light { background: #fff; font-size: 1.2rem; }
        .tk-modal { position: fixed; inset: 0; z-index: 70; display: flex; align-items: flex-end; justify-content: center; padding: 1rem 1rem max(1rem, env(safe-area-inset-bottom)); }
        .tk-modal[hidden] { display: none; }
        .tk-modal__backdrop { position: absolute; inset: 0; background: rgba(27, 27, 47, 0.5); }
        .tk-modal__card { position: relative; width: 100%; max-width: 26rem; padding: 1.25rem 1.25rem 1.4rem; }
        .tk-modal__list { display: grid; gap: 0.4rem; font-weight: 900; }
        .tk-modal__list li { display: flex; align-items: center; gap: 0.55rem; }
        .tk-modal__list li::before { content: ''; width: 0.7rem; height: 0.7rem; flex-shrink: 0; border: 2.5px solid var(--ink); border-radius: 50%; background: var(--berry); }
        @media (min-height: 720px) { .tk-modal { align-items: center; } }
        /* Sonuç ekranı: sıralama kartı, merdiven ve ilk 5 listesi. */
        .tk-rank--prize { background: #fff8e1; }
        .tk-tier { background: #fff; font-size: 0.85rem; transform: rotate(4deg); white-space: nowrap; }
        .tk-tier--prize { background: var(--sun); }
        .tk-ladder { position: relative; height: 0.9rem; border: 3px solid var(--ink); border-radius: 999px; background: #eef1f6; overflow: visible; }
        .tk-ladder__zone { position: absolute; top: 0; right: 0; bottom: 0; border-radius: 0 999px 999px 0; background: var(--sun); }
        .tk-ladder__me { position: absolute; top: 50%; width: 1.4rem; height: 1.4rem; margin: -0.7rem 0 0 -0.7rem; border: 3px solid var(--ink); border-radius: 50%; background: var(--berry); box-shadow: 0 3px 0 var(--ink); }
        .tk-board { display: grid; gap: 0.35rem; }
        .tk-board li { display: grid; grid-template-columns: 2rem minmax(0, 1fr) auto; align-items: center; gap: 0.6rem; padding: 0.4rem 0.6rem; border: 2.5px solid transparent; border-radius: 12px; font-weight: 900; }
        .tk-board li.is-prize { background: #fff3cc; }
        .tk-board li.is-me { border-color: var(--ink); background: #e7f8fa; }
        .tk-board li.is-me.is-prize { background: var(--sun); }
        .tk-board__rank { font-family: 'Paytone One', sans-serif; text-align: center; }
        .tk-board__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tk-board__score { font-family: 'Paytone One', sans-serif; font-variant-numeric: tabular-nums; }
        .tk-board li.tk-board__gap { display: block; padding: 0; text-align: center; color: #7a7f91; }
        .tk-star { width: 3.4rem; height: 3.4rem; fill: rgba(255, 255, 255, 0.35); stroke: var(--ink); stroke-width: 1.6; }
        .tk-star.is-on { fill: var(--sun); }
        .tk-input { width: 100%; border: 3px solid var(--ink); border-radius: 14px; background: #fff; padding: 0.8rem 0.95rem; font-size: 1.05rem; font-weight: 800; color: var(--ink); outline: none; box-shadow: inset 0 -3px 0 rgba(27, 27, 47, 0.08); }
        .tk-input::placeholder { color: #9aa0ad; font-weight: 700; }
        .tk-input:focus { border-color: var(--grape); }
        .tk-label { display: block; margin-bottom: 0.3rem; font-size: 0.85rem; font-weight: 900; color: var(--ink); }
        .tk-sticker { display: inline-block; border: 3px solid var(--ink); border-radius: 14px; background: var(--sun); padding: 0.35rem 0.8rem; font-weight: 900; box-shadow: 0 4px 0 var(--ink); }
        .tk-pop { animation: tk-pop .5s cubic-bezier(.3, 1.6, .5, 1) both; }
        @keyframes tk-pop { from { transform: scale(.6) rotate(var(--r, 0deg)); opacity: 0; } to { transform: scale(1) rotate(var(--r, 0deg)); opacity: 1; } }
        /* Oyunun adı: harfler soldan sağa sırayla zıplar (yükselirken uzar,
           inişte basılıp toparlanır), kısa aradan sonra yeniden. */
        .tk-letter { display: inline-block; }
        .tk-bounce .tk-letter { transform-origin: 50% 100%; animation: tk-hop 2.8s cubic-bezier(.3, .7, .4, 1) infinite; animation-delay: calc(var(--i) * 75ms); }
        @keyframes tk-hop {
            0%, 34%, 100% { transform: none; }
            7% { transform: translateY(-0.24em) scale(0.94, 1.08) rotate(-5deg); }
            14% { transform: translateY(0) scale(1.12, 0.86); }
            20% { transform: translateY(-0.06em) scale(0.97, 1.04) rotate(2deg); }
            26% { transform: translateY(0) scale(1.02, 0.98); }
        }
        @media (prefers-reduced-motion: reduce) { .tk-pop, .tk-bounce .tk-letter { animation: none; } }
        /* Kısa ekran (küçük iPhone, adres çubuğu açık Safari): başlıklar ve
           aralıklar küçülür, form ve "Üye ol" kaydırmadan görünsün. */
        @media (max-height: 700px) {
            .tk-headline { font-size: 2.6rem; margin-top: 0.5rem; }
            .tk-score { font-size: 3.8rem; }
            .tk-lead { font-size: 1.3rem; margin-top: 0.75rem; }
            .tk-tight { margin-top: 0.9rem; }
            .tk-tight.space-y-3 > * + * { margin-top: 0.55rem; }
            .tk-input { padding-top: 0.6rem; padding-bottom: 0.6rem; }
            .tk-btn { padding-top: 0.75rem; padding-bottom: 0.65rem; font-size: 1.55rem; }
        }
        @media (max-height: 600px) {
            .tk-headline { font-size: 2.15rem; margin-top: 0.25rem; }
            .tk-score { font-size: 3.2rem; }
            .tk-lead { font-size: 1.15rem; margin-top: 0.5rem; }
            .tk-tight { padding-top: 0.75rem; padding-bottom: 0.9rem; }
            .tk-tight .tk-label { margin-bottom: 0.15rem; font-size: 0.78rem; }
            .tk-sticker { padding: 0.25rem 0.6rem; font-size: 0.78rem; }
        }
    </style>
    @stack('head')
</head>
<body class="tk min-h-screen antialiased @yield('body-class')">
    <main class="relative mx-auto flex min-h-screen max-w-md flex-col px-5 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-5">
        <header class="flex items-center justify-between">
            {{-- Sitenin web logosu, oyunun beyaz kartında: turkuaz zeminde
                 "Genç"in turkuaz G'si kaybolmasın. --}}
            <a href="{{ route('game.index') }}" class="tk-card flex items-center px-2.5 py-1" data-game-home>
                @if($gameLogo = \App\Support\Brand::logoUrls()['light'] ?? null)
                    <img src="{{ $gameLogo }}" alt="{{ \App\Support\Brand::name() }}" class="h-9 w-auto">
                @else
                    <span class="tk-display text-2xl">{{ \App\Support\Brand::name() }}</span>
                @endif
            </a>
            @yield('header-right')
        </header>

        @if($errors->any() && ! View::hasSection('inline-errors'))
            <div class="tk-card tk-pop mt-4 bg-[#fff3f6] px-4 py-3 text-[0.95rem] font-extrabold text-[#c0175e]" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        @yield('content')
    </main>
    @stack('scripts')
    @include('partials.form-yardimcisi')
</body>
</html>
