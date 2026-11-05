@extends('games.layout')

@section('title', 'Ters Komut')
@section('inline-errors', '1')

@section('header-right')
    @if($game?->prize)
        <span class="tk-sticker tk-pop text-sm" style="--r: 4deg">{{ $game->prize }}</span>
    @endif
@endsection

@push('scripts')
    <script nonce="{{ Vite::cspNonce() }}">
        // Numara yazılırken üyelik e-postası görünsün: yanlış yazılırsa fark edilsin.
        (() => {
            const domain = @json('@'.\App\Services\Members\GamePlay::STUDENT_MAIL_DOMAIN);
            const input = document.getElementById('g-number');
            const preview = document.querySelector('[data-mail-preview]');

            if (! input || ! preview) {
                return;
            }

            // Okul e-postası numaradan yalnız 2025 ve sonrası girişlilerde türetilir (GamePlay::studentEmail).
            const fromYear = @json(\App\Services\Members\GamePlay::STUDENT_MAIL_FROM_YEAR);
            const thisYear = @json((int) now()->format('y'));
            const label = preview.querySelector('[data-mail-label]');
            const value = preview.querySelector('[data-mail-value]');

            const update = () => {
                const digits = input.value.replace(/\D+/g, '');
                const year = parseInt(digits.slice(0, 2), 10);
                preview.hidden = digits.length !== 10;

                if (year >= fromYear && year <= thisYear) {
                    label.textContent = 'Okul e-postan:';
                    value.textContent = digits + domain;
                } else if (year >= 15 && year < fromYear) {
                    label.textContent = 'E-postanı oyun bitince soracağız.';
                    value.textContent = '';
                } else {
                    label.textContent = 'Öğrenci numaranı kontrol et.';
                    value.textContent = '';
                }
            };

            input.addEventListener('input', update);
            update();
        })();

        // Misafir uyarı penceresi.
        (() => {
            const modal = document.querySelector('[data-guest-modal]');
            const open = document.querySelector('[data-guest-open]');

            if (location.hash === '#kayit') {
                document.getElementById('g-name')?.focus();
            }

            if (! modal || ! open) {
                return;
            }

            const card = modal.querySelector('.tk-modal__card');
            const show = () => {
                modal.hidden = false;
                card.animate([{ transform: 'translateY(40px) scale(.94)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 260, easing: 'cubic-bezier(.3, 1.4, .5, 1)' });
                modal.querySelector('[data-guest-register]').focus();
            };
            const hide = () => {
                modal.hidden = true;
                open.focus();
            };

            open.addEventListener('click', show);
            modal.querySelectorAll('[data-guest-close]').forEach((el) => el.addEventListener('click', hide));
            modal.querySelector('[data-guest-register]').addEventListener('click', () => {
                document.getElementById('kayit')?.scrollIntoView({ block: 'center' });
                document.getElementById('g-name')?.focus();
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && ! modal.hidden) {
                    hide();
                }
            });
        })();
    </script>
@endpush

@section('content')
    <h1 class="tk-logo tk-pop tk-bounce tk-headline mt-4 text-center text-[3.3rem]" style="--r: -4deg" aria-label="Ters Komut">
        @include('games.partials.ad')
    </h1>


    @unless($game)
        <section class="tk-card mt-6 px-5 py-5 text-center">
            @if($opensAt ?? null)
                <p class="font-extrabold text-[#5b5f73]">Oyun henüz başlamadı</p>
                <p class="tk-display mt-1 text-2xl">{{ $opensAt->translatedFormat('j F l') }}, {{ $opensAt->format('H.i') }}</p>
                <p class="mt-1 font-extrabold text-[#5b5f73]">Bu saatte açılıyor. Stantta QR'ı okut ya da bu sayfaya o saatte gel.</p>
            @elseif($endedAt ?? null)
                <p class="tk-display text-2xl">Oyun bitti</p>
                <p class="mt-1 font-extrabold text-[#5b5f73]">Ters Komut sona erdi, sıralama kesinleşti. Oynayan herkese teşekkür ederiz.</p>
            @else
                <p class="tk-display text-2xl">Stant günü açılıyor!</p>
                <p class="mt-1 font-extrabold text-[#5b5f73]">Stantta QR'ı okut, buradan oyna.</p>
            @endif
        </section>
    @else
        @if($player)
            <section class="mt-8 text-center">
                <p class="tk-display tk-outline text-[2.3rem] leading-tight text-white">
                    Tekrar hoş geldin, {{ \Illuminate\Support\Str::of($player->name)->squish()->explode(' ')->first() }}!
                </p>
                @if($played)
                    <a href="{{ route('game.play') }}" class="tk-btn mt-6">Skoruma bak</a>
                    @if($replays)
                        <a href="{{ route('game.play', ['tekrar' => 1]) }}" class="tk-btn tk-btn--berry mt-3">Tekrar oyna</a>
                        <p class="mt-2 text-sm font-extrabold">Sıralamaya yalnız ilk oyunun girer; tekrarlar pratik.</p>
                    @else
                        <p class="mt-2 text-sm font-extrabold">Her öğrenci bir kez oynar; skorun sıralamada.</p>
                    @endif
                @else
                    <a href="{{ route('game.play') }}" class="tk-btn mt-6">Oyna!</a>
                @endif
                <form method="POST" action="{{ route('game.leave') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="text-sm font-extrabold text-[#1b1b2f] underline underline-offset-4">Ben değilim</button>
                </form>
            </section>
        @else
            {{-- Kayıt: ad, telefon, öğrenci no. Ad alanına dokununca telefonun
                 kişi kartı önerisi adı ve numarayı birlikte doldurur. Hesabına
                 giriş yapmış üyeden yalnız eksik olan istenir. --}}
            @php
                $needsName = ! $member || blank($member->name);
                $needsPhone = ! $member || blank($member->phone);
                $needsNumber = ! $member || blank($member->student_number);
            @endphp
            <form method="POST" action="{{ route('game.register') }}" id="kayit" class="tk-card tk-tight mt-5 space-y-3 px-5 pb-5 pt-4">
                @csrf
                <input type="hidden" name="device" value="" data-device>
                <script nonce="{{ Vite::cspNonce() }}">
                    // Telefonun oyun kimliği (ilk oyunda üretilir): aynı telefondan açılan hesaplar sayılır.
                    try {
                        document.querySelector('[data-device]').value = localStorage.getItem('ters-komut:cihaz') || '';
                    } catch (error) {
                        // Gizli sekme: sunucu çerezi yeter.
                    }
                </script>
                @foreach($errors->getMessages() as $field => $messages)
                    @unless(in_array($field, ['name', 'phone', 'student_number'], true))
                        <p class="rounded-xl bg-[#fff3f6] px-3 py-2 text-sm font-extrabold text-[#c0175e]" role="alert">{{ $messages[0] }}</p>
                    @endunless
                @endforeach
                @if($member)
                    <p class="tk-display text-[1.7rem] leading-tight">Merhaba {{ \Illuminate\Support\Str::of($member->name)->squish()->explode(' ')->first() }}!</p>
                    @if($needsNumber || $needsPhone)
                        <p class="text-sm font-extrabold text-[#5b5f73]">Oynamak için bir tek bunu yaz:</p>
                    @endif
                @else
                    <p class="tk-display text-[1.7rem]">Oyuncu kartın</p>
                @endif

                @if($needsName)
                    <div>
                        <label for="g-name" class="tk-label">Ad soyad</label>
                        <input id="g-name" @error('name') aria-invalid="true" style="border-color:#ee3d8f" @enderror name="name" value="{{ old('name') }}" required autocomplete="name" autocapitalize="words" enterkeyhint="next" class="tk-input">
                        @error('name')<p class="mt-1 text-sm font-extrabold text-[#c0175e]">{{ $message }}</p>@enderror
                    </div>
                @endif
                @if($needsPhone)
                    <div>
                        <label for="g-phone" class="tk-label">Telefon</label>
                        <input id="g-phone" @error('phone') aria-invalid="true" style="border-color:#ee3d8f" @enderror type="tel" name="phone" value="{{ old('phone') }}" required autocomplete="tel" inputmode="tel" enterkeyhint="next" placeholder="05XX XXX XX XX" class="tk-input">
                        @error('phone')<p class="mt-1 text-sm font-extrabold text-[#c0175e]">{{ $message }}</p>@enderror
                    </div>
                @endif
                @if($needsNumber)
                    <div>
                        <label for="g-number" class="tk-label">Öğrenci no</label>
                        <input id="g-number" @error('student_number') aria-invalid="true" style="border-color:#ee3d8f" @enderror name="student_number" value="{{ old('student_number') }}" required inputmode="numeric" pattern="[0-9]*" autocomplete="off" enterkeyhint="go" class="tk-input">
                        @error('student_number')<p class="mt-1 text-sm font-extrabold text-[#c0175e]">{{ $message }}</p>@enderror
                        <p class="mt-1 text-sm font-extrabold text-[#5b5f73]" data-mail-preview hidden><span data-mail-label>Okul e-postan:</span> <span data-mail-value class="text-[#1b1b2f]"></span></p>
                    </div>
                @endif

                <button type="submit" class="tk-btn">Oyna!</button>
                <p class="text-[0.72rem] font-bold leading-snug text-[#7a7f91]">
                    Kaydınla {{ \App\Support\Brand::name() }} üyesi olursun; öğrenci e-postan hesabın olur. Bilgilerin yalnız üyelik, yarışma ve ödül teslimi için kullanılır. Ödül öğrenci kimliğiyle teslim edilir.
                </p>
            </form>

            {{-- Kayıtsız denemek isteyene önce uyarı penceresi (misafir oyunu açıkken). --}}
            @if($guests)
                <button type="button" class="tk-btn tk-btn--light mt-4" data-guest-open>Misafir olarak dene</button>
            @endif
        @endif
    @endunless

    @if($game && ! $player && $guests)
        <div class="tk-modal" data-guest-modal hidden role="dialog" aria-modal="true" aria-labelledby="misafir-baslik">
            <div class="tk-modal__backdrop" data-guest-close></div>
            <div class="tk-card tk-modal__card">
                <p id="misafir-baslik" class="tk-display text-[1.7rem] leading-tight">Misafir olarak mı?</p>
                <p class="mt-2 font-extrabold text-[#5b5f73]">İstediğin kadar oynayabilirsin, ama misafirken:</p>
                <ul class="tk-modal__list mt-3">
                    <li>Skorun sıralamaya girmez.</li>
                    <li>Ödül kazanamazsın{{ $game->prize ? ' ('.$game->prize.')' : '' }}.</li>
                </ul>
                <p class="mt-3 text-sm font-extrabold text-[#5b5f73]">Ödül için 3 alanı doldurman yeter; kayıtlı ilk oyunun sıralamaya girer.</p>
                <button type="button" class="tk-btn mt-4" data-guest-close data-guest-register>Kayıt olup oyna</button>
                <a href="{{ route('game.guest') }}" class="tk-btn tk-btn--light mt-3">Yine de misafir oyna</a>
            </div>
        </div>
    @endif

    {{-- Kural tek bakışta: yazı MOR ama rengi turkuaz → turkuaza bas. --}}
    <section class="tk-card mt-6 px-5 pb-5 pt-4 text-center" aria-label="Nasıl oynanır">
        <p class="tk-display text-[3.4rem] leading-none" style="color: #16b8c4">MOR</p>
        <div class="mt-3 grid grid-cols-4 gap-2.5" aria-hidden="true">
            <span class="relative flex h-12 items-center justify-center rounded-xl border-[3px] border-[#1b1b2f] shadow-[0_4px_0_#1b1b2f]" style="background:#16b8c4">
                <svg class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
            </span>
            <span class="h-12 rounded-xl border-[3px] border-[#1b1b2f] shadow-[0_4px_0_#1b1b2f]" style="background:#f5b426"></span>
            <span class="h-12 rounded-xl border-[3px] border-[#1b1b2f] shadow-[0_4px_0_#1b1b2f]" style="background:#8b3fd9"></span>
            <span class="h-12 rounded-xl border-[3px] border-[#1b1b2f] shadow-[0_4px_0_#1b1b2f]" style="background:#ee3d8f"></span>
        </div>
        <p class="tk-display mt-4 text-[1.6rem] leading-tight">Kelimeye değil, <span style="color:#ee3d8f">rengine</span> bas!</p>
        <p class="mt-1 text-sm font-extrabold text-[#5b5f73]">3 canın var · süre her soruda kısalır · sıralamaya ilk oyunun girer</p>
    </section>
@endsection
