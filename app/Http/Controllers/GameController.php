<?php

namespace App\Http\Controllers;

use App\Models\GameSession;
use App\Models\User;
use App\Services\Members\GameBoard;
use App\Services\Members\GamePlay;
use App\Support\BrandQr;
use App\Support\PlayerName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * banugenc.com/oyun — telefondan oynanan Ters Komut:
 * kısa kayıt (öğrenci no, ad, telefon) → doğrudan üyelik (e-posta öğrenci
 * numarasından) → oyun → skor + hoş geldin e-postası. Sıralamaya ilk oyun
 * girer; sonra istenildiği kadar pratik oynanır. Hesabına giriş yapmış üye
 * tek dokunuşla oynar.
 */
class GameController extends Controller
{
    public const GAME = 'ters-komut';

    /**
     * "Tekrar oyna" (pratik). Tanıtım Günleri'nde kapalı: her öğrenci yalnız bir kez,
     * kayıtlı oynar. Yeniden açmak için true.
     */
    public static bool $replays = false;

    /** Misafir oyunu: kayıtsız deneme, hiçbir şey kaydedilmez (19 Ekim 2026 akşamı yeniden açıldı). */
    public static bool $guests = true;

    private const SESSION_KEY = 'game.player_id';

    /** Kayıtta açılan üyeliğin hoş geldin e-postası oyun bitince gider. */
    private const WELCOME_KEY = 'game.welcome_pending';

    /**
     * Telefonun ikinci kimliği: tarayıcıdaki kimlikten ayrı, sunucunun verdiği
     * çerez. Biri silinse öbürü kalır; aynı telefondan açılan hesaplar sayılır.
     */
    private const DEVICE_COOKIE = 'tk_cihaz';

    public function __construct(
        private readonly GamePlay $games,
        private readonly GameBoard $board,
    ) {}

    /** Standa asılacak QR: telefondan oyunun giriş sayfasını açar. */
    public function qr(string $format): Response
    {
        return BrandQr::response(route('game.index'), $format, 'ters-komut-qr');
    }

    public function index(Request $request): View
    {
        $game = $this->games->game(self::GAME);
        $player = $this->player($request);
        $this->deviceCookie($request);

        return view('games.index', [
            'game' => $game,
            'player' => $player,
            'member' => $request->user('member'),
            'replays' => self::$replays,
            'guests' => self::$guests,
            // Oyun kapalıysa ve başlangıcı ileri bir tarihe kurulduysa: "… açılıyor";
            // bitiş tarihi geçtiyse: "Oyun bitti".
            'opensAt' => $game ? null : $this->games->opensAt(self::GAME),
            'endedAt' => $game ? null : $this->games->endedAt(self::GAME),
            // Oynadıysa: skoru ve pratik için "Tekrar oyna".
            'played' => $player && $game ? $this->games->sessionFor($player, $game)?->isFinished() : false,
        ]);
    }

    /**
     * Tek ekran, üç alan. Daha önce oynayan aynı numara ve telefonla girer
     * (adı yeniden sorulmaz, eşleşmesi aranmaz). Telefon eşleşmesi,
     * başkasının numarasıyla onun tek hakkının yakılmasını önler. Hesabına
     * giriş yapmış üyenin adı ve telefonu hesabından gelir.
     */
    public function register(Request $request): RedirectResponse
    {
        // Oyun açık değilse (başlamadı ya da bitti) form yok; doğrudan istekle de hesap açılmaz.
        if (! $this->games->game(self::GAME)) {
            return redirect()->route('game.index');
        }

        $member = $request->user('member');

        if ($member) {
            $request->merge([
                'student_number' => $request->input('student_number') ?: $member->student_number,
                'name' => $request->input('name') ?: $member->name,
                'phone' => $request->input('phone') ?: $member->phone,
            ]);
        }

        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen(preg_replace('/\D+/', '', (string) $value) ?? '') < 10) {
                    $fail('Telefon numaranı kontrol et.');
                }
            }],
        ], [
            'student_number.required' => 'Öğrenci numaranı yaz.',
            'name.required' => 'Adını ve soyadını yaz.',
            'phone.required' => 'Telefonunu yaz.',
        ]);

        $player = $this->games->findPlayer($data['student_number']);

        // Yeni numara okul biçiminde olmalı. 20 Ekim 2026'da 5–6 haneli uydurma
        // numaralarla tek telefondan 138 hesap açıldı.
        if (! $player && ($problem = self::numberProblem($data['student_number']))) {
            return back()->withInput()->withErrors(['student_number' => $problem]);
        }

        try {
            if ($player && $member && ! $player->is($member)) {
                return back()->withInput()->withErrors(['student_number' => 'Bu öğrenci numarası başka bir hesapta kayıtlı. Stanttaki görevliye uğra.']);
            }

            if ($player) {
                if (filled($player->phone) && ! GamePlay::samePhone($player->phone, $data['phone'])) {
                    return back()->withInput()->withErrors(['phone' => 'Bu öğrenci numarası başka bir telefonla kayıtlı. Stanttaki görevliye uğra.']);
                }

                $player = $this->games->attachStudentNumber($player, $data['student_number'], $data['phone']);
            } elseif ($member) {
                $player = $this->games->attachStudentNumber($member, $data['student_number'], $data['phone']);
            } else {
                if ($problem = $this->newPlayerProblem($request, $data)) {
                    return back()->withInput()->withErrors($problem);
                }

                $player = $this->games->register($data);

                if ($player->wasRecentlyCreated) {
                    $request->session()->put(self::WELCOME_KEY, $player->getKey());
                }
            }
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        $request->session()->put(self::SESSION_KEY, $player->getKey());

        return redirect()->route('game.play');
    }

    public function leave(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('game.index');
    }

    public function play(Request $request): View|RedirectResponse
    {
        $player = $this->player($request);
        $game = $this->games->game(self::GAME);

        if (! $player || ! $game) {
            return redirect()->route('game.index');
        }

        // Oynayan tekrar gelirse skoru açılır; "Tekrar oyna" ile pratik oyun:
        // sorular yeni, hiçbir şey sunucuya yazılmaz, sıralamaya girmez.
        $existing = $this->games->sessionFor($player, $game);

        if ($existing?->isFinished()) {
            if (! $request->boolean('tekrar') || ! self::$replays) {
                return redirect()->route('game.result', $existing->token);
            }

            $existing->loadMissing('score');

            return view('games.play', [
                'game' => $game,
                'player' => $player,
                'session' => $existing,
                'resume' => false,
                'practice' => true,
                'guest' => false,
                'questions' => GamePlay::questions(),
            ]);
        }

        // Yarım kalan oturum sürdürülür (soru dizisi eksikse start() tamamlar).
        $session = $this->games->start($player, $game, $request->ip(), $request->userAgent());
        $this->deviceCookie($request);

        return view('games.play', [
            'game' => $game,
            'player' => $player,
            'session' => $session,
            // Oyun başlamışken sayfa yenilendiyse yeniden oynanmaz: o ana
            // kadarki cevaplar gönderilip oyun bitirilir.
            'resume' => GamePlay::hasBegun($session),
            'practice' => false,
            'guest' => false,
            // Sorular sayfada yok: "Başla"da sunucudan gelir, önceden görülemez.
            'questions' => [],
        ]);
    }

    /**
     * Misafir oyunu: kayıt istemez, sunucuya hiçbir şey yazılmaz, sıralamaya
     * girmez ve ödül kazandırmaz (giriş ekranındaki uyarı penceresi bunu söyler).
     */
    public function guest(Request $request): View|RedirectResponse
    {
        $game = $this->games->game(self::GAME);

        if (! $game || ! self::$guests) {
            return redirect()->route('game.index');
        }

        return view('games.play', [
            'game' => $game,
            'player' => $this->player($request),
            'session' => null,
            'resume' => false,
            'practice' => true,
            'guest' => true,
            'questions' => GamePlay::questions(),
        ]);
    }

    /**
     * "Başla": süre sunucuda buradan başlar, sorular ancak şimdi verilir.
     * Telefonun izleri de şimdi gelir. Biçimi bozuk iz oyunu durdurmaz
     * (422 telefonda oyunu yeniden açtırır), yalnız yazılmaz.
     */
    public function begin(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:64']]);
        $print = (string) $request->input('fp');
        $session = $this->games->begin(
            $this->ownToken($request, $data['token']),
            $this->deviceId($request),
            [
                'cookie' => $this->deviceCookie($request),
                'net' => GamePlay::network($request->ip()),
                'print' => preg_match('/^[a-z0-9]{4,16}$/', $print) ? $print : null,
                'env' => [
                    'wd' => $request->boolean('wd'),
                    'tp' => $request->filled('tp') ? max(-1, min(255, (int) $request->input('tp'))) : -1,
                    'gpu' => Str::limit((string) preg_replace('/[^\x20-\x7E]/', '', (string) $request->input('gpu')), 120, ''),
                    'scr' => Str::limit((string) preg_replace('/[^0-9x@.]/', '', (string) $request->input('scr')), 24, ''),
                ],
            ],
        );

        return response()->json(['questions' => $session->meta['questions'] ?? []]);
    }

    /** Oyun sürerken her cevap basıldığı anda gelir; sunucu kendi saatiyle yazar. */
    public function answer(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'i' => ['required', 'integer', 'min:0', 'max:1000'],
            'c' => ['required', 'integer', 'min:-1', 'max:3'],
            'r' => ['required', 'integer', 'min:0', 'max:600000'],
            'k' => ['required', 'integer', 'min:0', 'max:86400000'],
            'p' => ['nullable', 'string', 'max:1'],
        ]);

        // Dokunuşun tuştaki yeri (binde) doğrulamada değil kırpılarak alınır:
        // bozuk değer canlı cevabı düşürmesin.
        $spot = fn (string $key) => $request->filled($key) ? max(0, min(1000, (int) $request->input($key))) : null;

        $this->games->answer(
            $this->ownToken($request, $data['token']),
            (int) $data['i'], (int) $data['c'], (int) $data['r'], (int) $data['k'], (string) ($data['p'] ?? ''),
            $spot('x'), $spot('y'),
        );

        return response()->noContent();
    }

    public function finish(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'answers' => ['nullable', 'string', 'max:20000'],
            'abandoned' => ['nullable', 'boolean'],
        ]);

        $answers = json_decode((string) ($data['answers'] ?? '[]'), true);

        try {
            $session = $this->games->finishTersKomut(
                $this->ownToken($request, $data['token']),
                is_array($answers) ? $answers : [],
                (bool) ($data['abandoned'] ?? false),
            );
        } catch (ValidationException $exception) {
            if ($request->wantsJson()) {
                throw $exception;
            }

            return redirect()->route('game.index')->withErrors($exception->errors());
        }

        // Kayıtta açılan üyeliğin hoş geldin e-postası şimdi, yanıt gittikten
        // sonra: skor ekranı e-postayı beklemez.
        if ((int) $request->session()->pull(self::WELCOME_KEY) === (int) $session->user_id) {
            $user = $session->user;
            dispatch(fn () => $this->games->welcomeAfterGame($user))->afterResponse();
        }

        $url = route('game.result', $session->token);

        return $request->wantsJson() ? response()->json(['url' => $url]) : redirect($url);
    }

    public function result(Request $request, string $token): View
    {
        $session = GameSession::query()->with(['campaign', 'score', 'user'])->where('token', $token)->whereNotNull('finished_at')->firstOrFail();

        return view('games.result', [
            'session' => $session,
            // Üyelik/e-posta kartı ve "Tekrar oyna" yalnız skorun sahibine.
            'isOwner' => (bool) $this->player($request)?->is($session->user),
            // Oyunun amacı ilk 3: her sıralamalı oyundan sonra yeri ve seviyesi.
            'standing' => $session->user ? $this->board->standing($session->campaign, $session->user) : null,
        ]);
    }

    /** Sonuç ekranındaki sıralama kartı; sayfa açıkken kendiliğinden yenilenir. */
    public function standing(string $token): JsonResponse
    {
        $session = GameSession::query()->with(['campaign', 'score', 'user'])->where('token', $token)->whereNotNull('finished_at')->firstOrFail();

        return response()->json([
            'html' => view('games.partials.siralama', [
                'session' => $session,
                'standing' => $session->user ? $this->board->standing($session->campaign, $session->user) : null,
            ])->render(),
        ]);
    }

    /**
     * Oyun sonunda tek alan, e-posta: hesabı e-postasız olan (öğrenci no 10
     * haneli değil) üye olur; öğrenci e-postasıyla üye olan isterse kişisel
     * e-postasına geçer.
     */
    public function join(Request $request, string $token): RedirectResponse
    {
        $session = GameSession::query()->with('user')->where('token', $token)->whereNotNull('finished_at')->firstOrFail();
        $player = $this->player($request);

        abort_unless($player?->is($session->user), 403);

        $data = $request->validate(
            ['email' => ['required', 'email', 'max:255', new \App\Rules\EmailTypo]],
            ['email.required' => 'E-postanı yaz.', 'email.email' => 'E-posta adresini kontrol et.'],
        );

        try {
            blank($player->email)
                ? $this->games->completeMembership($player, $data['email'])
                : $this->games->usePersonalEmail($player, $data['email']);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return redirect()->route('game.result', $token)->with('joined', true);
    }

    private function player(Request $request): ?User
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return $id ? User::query()->find($id) : null;
    }

    /** Yeni öğrenci numarasının sorunu: 10 hane, ilk ikisi giriş yılı. */
    private static function numberProblem(string $studentNumber): ?string
    {
        if (strlen(GamePlay::normalizeStudentNumber($studentNumber)) !== 10) {
            return 'Öğrenci numaran 10 haneli olmalı.';
        }

        return GamePlay::schoolNumber($studentNumber) ? null : 'Öğrenci numaranı kontrol et.';
    }

    /**
     * Yeni hesap açılmadan önce: gerçek ad soyad ve telefon başına hesap
     * sınırı. Arkadaşının telefonundan oynayan olur; dördüncü hesap açılmaz.
     *
     * @param  array{name: string}  $data
     * @return array<string, string>|null
     */
    private function newPlayerProblem(Request $request, array $data): ?array
    {
        if ($problem = PlayerName::problem($data['name'])) {
            return ['name' => $problem];
        }

        $game = $this->games->game(self::GAME);

        if ($game && $this->games->accountsOnDevice($game, $this->deviceId($request), $this->deviceCookie($request)) >= GamePlay::DEVICE_ACCOUNTS) {
            return ['device' => 'Bu telefondan çok sayıda hesap açıldı. Oynamak için stanttaki görevliye uğra.'];
        }

        return null;
    }

    /** Telefonun tarayıcıdaki oyun kimliği (oyunda üretilir, kayıt formuna da gelir). */
    private function deviceId(Request $request): ?string
    {
        $device = (string) $request->input('device');

        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $device) ? $device : null;
    }

    /** Sunucu çerezindeki telefon kimliği; yoksa üretilip yanıta eklenir. */
    private function deviceCookie(Request $request): string
    {
        $id = (string) $request->cookie(self::DEVICE_COOKIE);

        if (! preg_match('/^[A-Za-z0-9]{32}$/', $id)) {
            $id = Str::random(32);
            Cookie::queue(self::DEVICE_COOKIE, $id, 60 * 24 * 365);
        }

        return $id;
    }

    /** Oturum anahtarı yalnız sahibinin elinden kullanılır. */
    private function ownToken(Request $request, string $token): string
    {
        $owner = GameSession::query()->where('token', $token)->value('user_id');

        if ($owner === null || (int) $owner !== (int) $request->session()->get(self::SESSION_KEY)) {
            throw ValidationException::withMessages(['token' => 'Bu oyun oturumu geçersiz ya da kullanılmış.']);
        }

        return $token;
    }
}
