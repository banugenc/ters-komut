@extends('games.layout')

@section('title', 'Skorun')
@section('inline-errors', '1')

@push('scripts')
    <script nonce="{{ Vite::cspNonce() }}">
        // Sıralama kartı: başkaları oynadıkça sıra değişir; sayfa açık ve
        // görünürken 20 saniyede bir yenilenir.
        (() => {
            const box = document.querySelector('[data-standing]');

            if (! box) {
                return;
            }

            const refresh = async () => {
                if (document.visibilityState !== 'visible') {
                    return;
                }

                try {
                    const response = await fetch(box.dataset.url, { headers: { Accept: 'application/json' } });

                    if (response.ok) {
                        box.innerHTML = (await response.json()).html;
                    }
                } catch (error) {
                    // Bağlantı yoksa bir sonraki turda yeniden denenir.
                }
            };

            window.setInterval(refresh, 20000);
            document.addEventListener('visibilitychange', refresh);
        })();
    </script>
@endpush

@section('content')
    @php
        $game = $session->campaign;
    @endphp

    <h1 class="tk-logo tk-pop tk-headline mt-5 text-center text-[3.1rem] text-white" style="--r: -3deg">Oyun bitti!</h1>

    {{-- Skor rozeti: büyük, çerçeveli, hafif eğik. --}}
    <section class="tk-card tk-pop mx-auto mt-5 w-full max-w-[16rem] px-6 pb-4 pt-3 text-center" style="--r: 2deg">
        <p class="tk-display text-xl text-[#5b5f73]">{{ $game->score_type === 'duration' ? 'Süren' : 'Doğru cevap' }}</p>
        <p class="tk-display tk-score text-[4.6rem] leading-none tabular-nums" style="color: #8b3fd9">{{ $game->formatScore((int) $session->score?->score) }}</p>
        @if($game->game_key === 'ters-komut')
            {{-- 8, 16, 24 doğruda birer yıldız (oyunun bitiş sahnesiyle aynı). --}}
            @php $earned = collect([8, 16, 24])->filter(fn ($at) => (int) $session->score?->score >= $at)->count(); @endphp
            <div class="mt-2 flex justify-center gap-1.5" aria-label="{{ $earned }} yıldız">
                @for($i = 0; $i < 3; $i++)
                    <svg class="tk-star {{ $i < $earned ? 'is-on' : '' }}" style="width:2.4rem;height:2.4rem" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linejoin="round" d="M12 2.8l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 16.8l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/></svg>
                @endfor
            </div>
        @endif
    </section>

    {{-- Oyunun amacı ilk 3: sıra, seviye ve ilk 5; sayfa açıkken yenilenir. --}}
    @if($standing)
        <div data-standing data-url="{{ route('game.standing', $session->token) }}">
            @include('games.partials.siralama')
        </div>
    @endif
    @if($game->prize)
        <p class="mt-3 text-center font-extrabold">{{ $game->prize }} · ödüller stant sonunda kimlikle</p>
    @endif

    @if($isOwner)
        @if(\App\Http\Controllers\GameController::$replays)
            {{-- Tekrar oynanır ama sıralamaya ilk oyun girer: oyuncu bunu bilsin. --}}
            <a href="{{ route('game.play', ['tekrar' => 1]) }}" class="tk-btn tk-tight mt-5">Tekrar oyna</a>
            <p class="mt-2 text-center text-sm font-extrabold">Tekrar oynayabilirsin, ama sıralamaya yalnız ilk oyunun girer.</p>
        @endif

        @php
            $member = $session->user;
            $studentMail = \App\Services\Members\GamePlay::isStudentEmail($member?->email);
        @endphp
        @if(session('joined'))
            <section class="tk-card tk-pop mt-5 px-5 pb-5 pt-4" style="--r: -1deg">
                <p class="tk-display text-[1.5rem]">E-postan kaydedildi</p>
                <p class="mt-1 font-extrabold text-[#5b5f73]">Hesabına giriş bağlantısını {{ $member->email }} adresine gönderdik. Üye kartın ve GençPUAN'ın orada.</p>
            </section>
        @elseif(blank($member?->email))
            {{-- Öğrenci no 10 haneli değilse e-posta tamamlanamadı: burada istenir. --}}
            <form method="POST" action="{{ route('game.join', $session->token) }}" class="tk-card tk-tight mt-5 space-y-3 px-5 pb-5 pt-4">
                @csrf
                <p class="tk-display text-[1.5rem] leading-tight">Üye ol, kartın hazır</p>
                <p class="text-sm font-extrabold text-[#5b5f73]">E-postanı yaz; üye kartın, etkinlik biletlerin ve GençPUAN'ın hesabına gelsin.</p>
                <div>
                    <label for="g-email" class="tk-label">E-posta</label>
                    <input id="g-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" inputmode="email" autocapitalize="off" enterkeyhint="go" class="tk-input" @error('email') aria-invalid="true" style="border-color:#ee3d8f" @enderror>
                    @error('email')<p class="mt-1 text-sm font-extrabold text-[#c0175e]">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="tk-btn tk-btn--berry">Üye ol</button>
            </form>
        @elseif($studentMail)
            {{-- Öğrenci e-postasıyla üye oldu; kişisel e-postasını kullanmak isterse. --}}
            <form method="POST" action="{{ route('game.join', $session->token) }}" class="tk-card tk-tight mt-5 space-y-3 px-5 pb-5 pt-4">
                @csrf
                <p class="tk-display text-[1.5rem] leading-tight">Artık üyesin!</p>
                <p class="text-sm font-extrabold text-[#5b5f73]">Hoş geldin e-postası öğrenci mailine gidiyor: <span class="text-[#1b1b2f]">{{ $member->email }}</span>. Kişisel mailini daha sık açıyorsan onu yaz, hesabın oraya geçsin.</p>
                <div>
                    <label for="g-email" class="tk-label">Kişisel e-posta (isteğe bağlı)</label>
                    <input id="g-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" inputmode="email" autocapitalize="off" enterkeyhint="go" class="tk-input" @error('email') aria-invalid="true" style="border-color:#ee3d8f" @enderror>
                    @error('email')<p class="mt-1 text-sm font-extrabold text-[#c0175e]">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="tk-btn tk-btn--berry">Kaydet</button>
            </form>
        @else
            <section class="tk-card mt-5 px-5 pb-5 pt-4">
                <p class="tk-display text-[1.5rem]">Artık üyesin!</p>
                <p class="mt-1 font-extrabold text-[#5b5f73]">Üye kartın, etkinlik biletlerin ve GençPUAN'ın hesabında.</p>
                <a href="{{ route('account.login') }}" class="tk-btn tk-btn--berry mt-4">Hesabıma gir</a>
            </section>
        @endif
    @endif


@endsection
