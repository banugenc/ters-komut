<?php

namespace App\Services\Members;

use App\Models\Campaign;
use App\Models\GameSession;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Services\EventMemberRegistrationService;
use App\Support\MemberIdentity;
use App\Support\PlayerName;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stant oyunlarının ortak akışı (web oyunu ve iPad uygulaması aynı kuralları
 * kullanır): oyuncu öğrenci numarasından tanınır, yoksa kısa kayıt onu
 * oyuncu yapar; oyun sunucuda tek kullanımlık oturumla başlar; skor yalnız o
 * oturumla ve makul sürede gönderilirse sıralamaya yazılır.
 */
class GamePlay
{
    /** Oyunun en kısa makul süresi (ms) ve en yüksek makul skoru. */
    public const LIMITS = [
        'ters-komut' => ['min_ms' => 3000, 'max_score' => 300],
        'kacis' => ['min_ms' => 2000, 'max_score' => 600000],
    ];

    /** Ters Komut: düğme sırasıyla renkler. Kelime ve yazı rengi bu sıradaki indekslerdir. */
    public const COLORS = ['turkuaz', 'sari', 'mor', 'pembe'];

    /** Ters Komut: can sayısı, oturumda saklanan soru sayısı, dokunma gecikmesi payı (ms). */
    public const LIVES = 3;

    private const QUESTION_COUNT = 150;

    private const GRACE_MS = 60;

    /** Ters Komut: cevaptan sonra yeni kelimeye kadar bekleme (ms); istemci sayfadan alır. */
    public const GAP_HIT = 170;

    public const GAP_MISS = 560;

    /**
     * Hile denetimi. Telefon her cevabı anında gönderir, sunucu kendi saatiyle
     * yazar; oyun sonunda süreler, hız, düzen, dokunuş, tarayıcı ortamı ve
     * telefonun önceki hesapları denetlenir. İşaretli skor sıralamadan düşer,
     * kararı stanttaki görevli verir. Notlar (cihaz, numara) yalnız görevliye
     * gösterilir, skoru düşürmez. Oyuncuya gerekçe gösterilmez.
     */
    public const FLAG_REASONS = [
        'hiz' => 'İnsanüstü hızlı doğrular',
        'duzen' => 'Makine gibi düzenli süreler',
        'sinir' => 'İnsan sınırının üstünde skor',
        'zaman' => 'Süreler sunucu saatiyle tutmuyor',
        'liste' => 'Sonuç listesi canlı kayıtla uyuşmuyor',
        'canli' => 'Cevapların çoğu canlı gelmedi',
        'dokunus' => 'Dokunmatik ekran dışından oynanmış',
        'parmak' => 'Dokunuşlar hep aynı noktada, makine gibi',
        'ortam' => 'Bilgisayarda telefon taklidiyle oynanmış',
        'otomasyon' => 'Tarayıcıyı bir program yönetiyor',
        'coklu' => 'Aynı telefondan üç ya da daha çok hesap',
        'tekrar' => 'Aynı telefondan aynı adla ikinci hesap',
        'ad' => 'Uygunsuz ad',
        'cihaz' => 'Aynı telefondan başka sıralamalı oyun da var',
        'numara' => 'Öğrenci numarası okul biçiminde değil',
    ];

    /** Bir telefondan bu kadar hesap oyuna başladıysa yeni hesap açılmaz; üçüncü hesabın skoru işaretlenir. */
    public const DEVICE_ACCOUNTS = 3;

    /** Bundan hızlı tepki insan için olanaksız; bu kadarı (tek bir şanslı tahmin değil) işaretlenir. */
    private const BOT_MS = 120;

    private const BOT_HITS = 3;

    /**
     * 300 ms altı doğru dürüst oyunlarda en çok 2 kez görüldü (Ekim 2026, ~750
     * oyun); denenen botlarda 26–43 kez. Tahminle gelen doğru da sayılır:
     * her tahmin 1/4 tutar, can 3.
     */
    private const FAST_MS = 300;

    private const FAST_HITS = 4;

    /** Dürüst oyunlarda doğruların ortanca tepkisi en az 391 ms; en az 10 doğruda 330'un altı makinedir. */
    private const MEDIAN_MS = 330;

    private const MEDIAN_HITS = 10;

    /** İnsanın tepki süresi oynar; en az 15 doğruda sapma bundan küçükse makine işidir. */
    private const STEADY_HITS = 15;

    private const STEADY_SD = 35;

    /**
     * Sıralamaya incelemesiz giren en yüksek skorun bir fazlası. Dürüst ~1000
     * oyunda en yüksek 34–35 (Ekim 2026): rekor skora görevli bakar.
     */
    private const HUMAN_MAX = 36;

    /**
     * Dokunuşun tuş içindeki yeri (binde). Parmak her seferinde başka noktaya
     * değer; en az 15 doğruda yayılım binde 15'in (tuşun %1,5'i) altındaysa
     * dokunan makinedir.
     */
    private const TAP_HITS = 15;

    private const TAP_SD = 15;

    /**
     * Telefon taklidi: iPhone ya da Android diye görünen tarayıcının ekran
     * kartı bilgisayarınki. Gerçek iPhone "Apple GPU", Android Adreno, Mali vb.
     * yazar (Android'de Chrome "ANGLE (Qualcomm, …)" da yazabilir).
     */
    private const DESKTOP_GPU = '/\b(ANGLE|Direct3D|D3D1\d|Metal Renderer|OpenGL Engine|NVIDIA|GeForce|Quadro|Radeon|AMD|Intel|Mesa|llvmpipe|SwiftShader)\b|Apple M\d/i';

    private const ANDROID_DESKTOP_GPU = '/\b(Direct3D|D3D1\d|Metal|OpenGL Engine|GeForce|Quadro|llvmpipe|SwiftShader)\b|Apple M\d/i';

    /** Aynı IPv6 ağı ve aynı cihaz özeti bu kadar saat içinde aynı telefon sayılır. */
    private const NETWORK_HOURS = 6;

    /** Canlı kayıt payları (ms): ağ gecikmesi, kare ve zamanlayıcı kayması. */
    private const DRIFT_MS = 3000;

    private const IDLE_MS = 1500;

    private const IDLE_STEP_MS = 120;

    private const IDLE_HARD_MS = 80;

    private const FIRST_ANSWER_MS = 15000;

    /** BANÜ öğrenci e-postası: 10 haneli öğrenci no + bu alan adı. */
    public const STUDENT_MAIL_DOMAIN = 'ogr.bandirma.edu.tr';

    public function __construct(
        private readonly MembershipSignup $signup,
        private readonly GameBoard $board,
        private readonly EventMemberRegistrationService $members,
    ) {}

    public static function normalizeStudentNumber(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    /** Telefonlar aynı mı (boşluk, +90, baştaki 0 farkı yok sayılır). */
    public static function samePhone(?string $a, ?string $b): bool
    {
        $digits = fn (?string $phone) => substr(preg_replace('/\D+/', '', (string) $phone) ?? '', -10);

        return $digits($a) !== '' && $digits($a) === $digits($b);
    }

    /**
     * Ters Komut'ta n. sorunun süresi (ms): ilk 5 soruda 1500, sonra her
     * soruda 35 ms kısalır (20. soruda ~975, 40. soruda ~275). Tavan yok;
     * duvarı daralan süre kurar.
     */
    public static function timeLimit(int $question): int
    {
        return $question <= 5 ? 1500 : max(150, 1500 - ($question - 5) * 35);
    }

    /**
     * Soru dizisi: [kelime, yazı rengi]. İkisi hiç aynı olmaz; aynı soru
     * art arda gelmez ki oyuncu yeni soruyu fark etsin.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function questions(int $count = self::QUESTION_COUNT): array
    {
        $questions = [];
        $previous = null;

        while (count($questions) < $count) {
            $word = random_int(0, 3);
            $ink = random_int(0, 2);
            $ink += $ink >= $word ? 1 : 0;

            if ([$word, $ink] === $previous) {
                continue;
            }

            $questions[] = $previous = [$word, $ink];
        }

        return $questions;
    }

    /**
     * Numarayla açılan okul e-postası 2025 ve sonrası girişlilerde var: 19 Ekim 2026'da 21–24 girişlilere giden 39
     * mailin hepsi "hesap yok" diye döndü, 25–26 girişlilere giden 95 mailin 92'si ulaştı.
     */
    public const STUDENT_MAIL_FROM_YEAR = 25;

    /**
     * Öğrenci numarasından öğrenci e-postası: 10 haneli ve giriş yılı (ilk iki hane) 25 ile bu yıl arasındaysa.
     * Diğerlerinde e-posta oyun sonunda sorulur; tahminle geçersiz adres yazılmaz.
     */
    public static function studentEmail(?string $studentNumber): ?string
    {
        $number = self::normalizeStudentNumber($studentNumber);
        $year = (int) substr($number, 0, 2);

        return strlen($number) === 10 && $year >= self::STUDENT_MAIL_FROM_YEAR && $year <= (int) now()->format('y')
            ? $number.'@'.self::STUDENT_MAIL_DOMAIN
            : null;
    }

    public static function isStudentEmail(?string $email): bool
    {
        return str_ends_with(strtolower((string) $email), '@'.self::STUDENT_MAIL_DOMAIN);
    }

    /** Öğrenci e-postasındaki numara (1234567890@ogr.bandirma.edu.tr); başka adreste yok. */
    public static function studentNumberFromEmail(?string $email): ?string
    {
        return self::isStudentEmail($email) && preg_match('/^(\d{10})@/', strtolower(trim((string) $email)), $match)
            ? $match[1]
            : null;
    }

    /** Okul biçimindeki numara: 10 hane, ilk ikisi giriş yılı (15 ile bu yıl arası). Değilse görevliye not düşer. */
    public static function schoolNumber(?string $studentNumber): bool
    {
        $number = self::normalizeStudentNumber($studentNumber);
        $year = (int) substr($number, 0, 2);

        return strlen($number) === 10 && $year >= 15 && $year <= (int) now()->format('y');
    }

    /**
     * IPv6 adresinin ağı (/64). Mobil hatta bu ağ telefonun kendisine özgüdür;
     * IPv4 kampüste ve operatörde paylaşıldığından kullanılmaz.
     */
    public static function network(?string $ip): ?string
    {
        if (! $ip || str_contains($ip, '.') || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }

        return bin2hex(substr((string) inet_pton($ip), 0, 8));
    }

    public function game(string $key): ?Campaign
    {
        return Campaign::query()->running()->where('game_key', $key)->first();
    }

    /** Bu telefondan (tarayıcı kimliği ya da çerez) bu oyuna başlamış hesap sayısı. */
    public function accountsOnDevice(Campaign $game, ?string $device, ?string $cookie): int
    {
        if (! $device && ! $cookie) {
            return 0;
        }

        return GameSession::query()
            ->where('campaign_id', $game->getKey())
            ->where(fn ($query) => $query
                ->when($device, fn ($query) => $query->orWhere('meta->device', $device))
                ->when($cookie, fn ($query) => $query->orWhere('meta->cookie', $cookie)))
            ->distinct()
            ->count('user_id');
    }

    /** Henüz başlamamış oyunun açılış zamanı; giriş sayfası kapalıyken bunu gösterir. */
    public function opensAt(string $key): ?CarbonInterface
    {
        return Campaign::query()
            ->where('is_active', true)
            ->where('game_key', $key)
            ->where('start_date', '>', now())
            ->orderBy('start_date')
            ->first()?->start_date;
    }

    /** Bitmiş oyunun bitiş zamanı (kampanyanın bitiş tarihi geçti); giriş sayfası "Oyun bitti" der. */
    public function endedAt(string $key): ?CarbonInterface
    {
        return Campaign::query()
            ->where('is_active', true)
            ->where('game_key', $key)
            ->where('end_date', '<', now())
            ->latest('end_date')
            ->first()?->end_date;
    }

    public function findPlayer(?string $studentNumber): ?User
    {
        $number = self::normalizeStudentNumber($studentNumber);

        return $number === '' ? null : User::query()->where('student_number', $number)->first();
    }

    public function sessionFor(User $user, Campaign $game): ?GameSession
    {
        return GameSession::query()
            ->where('campaign_id', $game->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * Oyuncu kaydı ve doğrudan üyelik. Telefonda yalnız öğrenci no, ad soyad
     * ve telefon istenir; e-posta öğrenci numarasından tamamlanır
     * (no@ogr.bandirma.edu.tr) ve kişi onay beklemeden üye olur. Hoş geldin
     * e-postası oyun bitince gider (welcomeAfterGame). Numara 10 haneli
     * değilse e-posta oyun sonunda sorulur (completeMembership). Telefon ya
     * da e-posta zaten bir hesaba aitse o hesap kullanılır.
     *
     * @param  array{student_number: string, name: string, phone: string, email?: string|null, department?: string|null}  $data
     */
    public function register(array $data): User
    {
        $number = self::normalizeStudentNumber($data['student_number'] ?? '');

        if (strlen($number) < 5 || strlen($number) > 20) {
            throw ValidationException::withMessages(['student_number' => 'Öğrenci numaranı kontrol et.']);
        }

        if ($existing = $this->findPlayer($number)) {
            return $existing;
        }

        $email = filled($data['email'] ?? null) ? $data['email'] : self::studentEmail($number);

        $user = $email
            ? $this->signup->join([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $data['phone'],
                'department' => $data['department'] ?? null,
            ], matchByName: false, welcome: false)
            : $this->members->resolveOrCreateStudentMember([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'department' => $data['department'] ?? null,
                'match_by_name' => false,
            ]);

        return $this->attachStudentNumber($user, $number);
    }

    /** Hesabı açık üyeye öğrenci numarasını (ve eksikse telefonunu) yazar. */
    public function attachStudentNumber(User $user, string $studentNumber, ?string $phone = null): User
    {
        $number = self::normalizeStudentNumber($studentNumber);

        if (blank($user->student_number)) {
            User::releaseFromTrashed(studentNumber: $number);
            $user->forceFill(['student_number' => $number])->save();
        } elseif ($user->student_number !== $number) {
            throw ValidationException::withMessages(['student_number' => 'Bu telefon ya da hesap başka bir öğrenci numarasıyla kayıtlı. Stanttaki görevliye uğra.']);
        }

        if ($phone && blank($user->phone)) {
            $user->forceFill(['phone' => $phone])->save();
        }

        return $user;
    }

    /**
     * Oyun sonunda "Üye ol": e-postayı hesaba yazar, üyelik kaydını açar ve
     * giriş bağlantılı hoş geldin e-postasını gönderir.
     */
    public function completeMembership(User $user, string $email): User
    {
        $member = $this->signup->join([
            'name' => $user->name,
            'email' => $email,
            'phone' => $user->phone,
            'department' => $user->department,
        ], matchByName: false);

        if (! $member->is($user)) {
            throw ValidationException::withMessages(['email' => 'Bu e-posta başka bir hesapta kayıtlı. Stanttaki görevliye uğra.']);
        }

        $this->signup->welcome($member->refresh());

        return $member;
    }

    /** Oyun bitince: kayıtta ertelenen hoş geldin e-postası. */
    public function welcomeAfterGame(User $user): void
    {
        if (filled($user->email)) {
            $this->signup->welcome($user);
        }
    }

    /**
     * Öğrenci e-postası yerine kişisel e-posta: hesabın ve üyelik kaydının
     * e-postası değişir, giriş bağlantılı hoş geldin oraya da gider.
     */
    public function usePersonalEmail(User $user, string $email): User
    {
        $email = MemberIdentity::normalizeEmail($email);

        if (! $email || User::query()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
            throw ValidationException::withMessages(['email' => 'Bu e-posta başka bir hesapta kayıtlı. Stanttaki görevliye uğra.']);
        }

        $previous = $user->email;

        DB::transaction(function () use ($user, $email, $previous): void {
            User::releaseFromTrashed($email);
            $user->forceFill(['email' => $email])->save();

            if ($previous) {
                MembershipApplication::query()->where('email', $previous)->get()
                    ->each(fn (MembershipApplication $application) => $application->update(['email' => $email]));
            }
        });

        $this->signup->welcome($user->refresh());

        return $user;
    }

    /** Oyunu başlatır; sıralamaya her oyuncunun ilk oyunu girer (tekrarlar pratik, sunucuya yazılmaz). Yarım kalan oturum sürdürülür. */
    public function start(User $user, Campaign $game, ?string $ip = null, ?string $userAgent = null): GameSession
    {
        return DB::transaction(function () use ($user, $game, $ip, $userAgent): GameSession {
            $existing = GameSession::query()
                ->where('campaign_id', $game->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing?->isFinished()) {
                throw ValidationException::withMessages(['game' => 'Bu oyunu zaten oynadın. Sıralamanı stantta görebilirsin.']);
            }

            // Soru dizisi olmadan açılmış oturum (oyun yayına girmeden önceki
            // şablon ekranı): hiç soru görülmediği için "başladı" sayılmaz,
            // sorular eklenip oyun baştan açılır.
            if ($existing && $game->game_key === 'ters-komut' && empty($existing->meta['questions'])) {
                $meta = $existing->meta ?? [];
                unset($meta['begun_at']);
                $existing->forceFill(['meta' => [...$meta, 'questions' => self::questions()]])->save();
            }

            return $existing ?? GameSession::create([
                'campaign_id' => $game->getKey(),
                'user_id' => $user->getKey(),
                'token' => Str::random(48),
                'started_at' => now(),
                'ip' => $ip,
                'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
                // Ters Komut'ta sorular sunucuda üretilip saklanır: doğru cevap
                // istemciden değil buradan bilinir.
                'meta' => $game->game_key === 'ters-komut' ? ['questions' => self::questions()] : null,
            ]);
        });
    }

    /**
     * Oyuncu "Başla"ya bastı: süre buradan ölçülür, yeniden yüklemede
     * sıfırlanmaz. Ters Komut soruları sayfada değil, bu yanıtta verilir:
     * kimse önceden göremez. Telefonun izleri bir kez yazılır: tarayıcı
     * kimliği (device) ve $trace içinde sunucu çerezi (cookie), IPv6 ağı
     * (net), cihaz özeti (print) ve tarayıcı ortamı (env).
     *
     * @param  array{cookie?: ?string, net?: ?string, print?: ?string, env?: array<string, mixed>}  $trace
     */
    public function begin(string $token, ?string $device = null, array $trace = []): GameSession
    {
        return DB::transaction(function () use ($token, $device, $trace): GameSession {
            $session = $this->openSession($token);
            $meta = $session->meta ?? [];

            if (! isset($meta['begun_at'])) {
                $meta['begun_at'] = now()->getTimestampMs();
                $meta['device'] = $device;
                $meta = array_merge($meta, array_intersect_key($trace, array_flip(['cookie', 'net', 'print', 'env'])));
                $session->forceFill(['meta' => $meta])->save();
            }

            return $session;
        });
    }

    /**
     * Ters Komut oynanırken tek cevap, basıldığı anda: [renk, tepki, oyun
     * saati, sunucu saati, dokunuş türü, dokunuşun tuştaki yeri x, y (binde)].
     * Saatler "Başla"dan bu yana (ms); her soruya ilk gelen cevap geçerlidir,
     * sonradan değiştirilemez.
     */
    public function answer(string $token, int $index, int $color, int $reaction, int $clock, string $input, ?int $x = null, ?int $y = null): void
    {
        DB::transaction(function () use ($token, $index, $color, $reaction, $clock, $input, $x, $y): void {
            $session = $this->openSession($token);
            $meta = $session->meta ?? [];

            if (! isset($meta['begun_at']) || $index >= count($meta['questions'] ?? []) || isset($meta['stream'][$index])) {
                return;
            }

            $meta['stream'][$index] = [$color, $reaction, $clock, now()->getTimestampMs() - (int) $meta['begun_at'], $input, $x, $y];
            $session->forceFill(['meta' => $meta])->save();
        });
    }

    public static function hasBegun(GameSession $session): bool
    {
        return isset($session->meta['begun_at']);
    }

    /**
     * Skoru kaydeder. Süre sunucudaki başlangıçtan ölçülür; istemcinin
     * bildirdiği süreye güvenilmez.
     *
     * @param  array<string, mixed>  $meta
     */
    public function finish(string $token, int $score, array $meta = []): GameSession
    {
        return DB::transaction(function () use ($token, $score, $meta): GameSession {
            $session = $this->openSession($token);
            $game = $session->campaign;
            $limits = self::LIMITS[$game?->game_key] ?? ['min_ms' => 2000, 'max_score' => PHP_INT_MAX];
            $elapsed = $this->elapsed($session);

            if ($score < 0 || $score > $limits['max_score']) {
                throw ValidationException::withMessages(['score' => 'Skor geçersiz.']);
            }

            $flag = $elapsed < $limits['min_ms']
                // Süreli oyunda skor, sunucunun ölçtüğü süreyi aşamaz.
                || ($game?->score_type === 'duration' && $score > $elapsed + 1500);

            return $this->complete($session, $score, null, $flag, array_merge($meta, ['elapsed_ms' => $elapsed]));
        });
    }

    /**
     * Ters Komut skorunu sunucu hesaplar. İstemci her soru için yalnız
     * basılan rengi (süre dolduysa -1) ve tepki süresini gönderir; doğru
     * cevap oturumdaki sorulardan bilinir. Skor doğru sayısı, beraberlik
     * bozucu doğruların toplam tepki süresidir. Yarıda bırakılan oyunda o
     * ana kadarki cevaplar geçerlidir.
     *
     * Oyun sırasında canlı gelen cevaplar esastır; sondaki liste yalnız
     * canlı gelmeyenleri tamamlar.
     *
     * @param  array<int, mixed>  $answers
     */
    public function finishTersKomut(string $token, array $answers, bool $abandoned = false): GameSession
    {
        return DB::transaction(function () use ($token, $answers, $abandoned): GameSession {
            $session = $this->openSession($token);
            $questions = $session->meta['questions'] ?? [];
            $stream = $session->meta['stream'] ?? [];
            $list = array_values($answers);
            $total = min(count($questions), max(count($list), $stream ? max(array_keys($stream)) + 1 : 0));

            $lives = self::LIVES;
            $score = 0;
            $reaction = 0;
            $claimed = 0;
            $log = [];
            $hits = [];
            $live = [];
            $mismatch = false;

            for ($index = 0; $index < $total && $lives > 0; $index++) {
                $sent = $stream[$index] ?? null;
                $given = is_array($list[$index] ?? null) ? $list[$index] : null;
                $color = (int) ($sent[0] ?? $given[0] ?? -1);
                $ms = max(0, (int) ($sent[1] ?? $given[1] ?? 0));
                $mismatch = $mismatch || ($sent && $given && ((int) ($given[0] ?? -1) !== $color || (int) ($given[1] ?? 0) !== $ms));

                $limit = self::timeLimit($index + 1);
                $correct = $color === (int) $questions[$index][1] && $ms <= $limit + self::GRACE_MS;
                $claimed += min($ms, $limit);
                $log[] = [$color, $ms];
                $live[$index] = $sent ? [
                    'correct' => $correct, 'ms' => $ms, 'limit' => $limit, 'clock' => (int) ($sent[2] ?? 0), 'server' => (int) ($sent[3] ?? 0),
                    'input' => (string) ($sent[4] ?? ''), 'x' => isset($sent[5]) ? (int) $sent[5] : null, 'y' => isset($sent[6]) ? (int) $sent[6] : null,
                ] : null;

                if ($correct) {
                    $score++;
                    $reaction += $ms;
                    $hits[] = $ms;
                } else {
                    $lives--;
                }
            }

            $elapsed = $this->elapsed($session);
            [$reasons, $checks] = $this->suspicions($live, $hits, $score, $claimed, $elapsed);
            $reasons = [...$reasons, ...$this->environment($session, array_filter($live))];

            if ($mismatch) {
                $reasons[] = 'liste';
            }

            // Aynı telefondan ikinci hesap (arkadaşının telefonu) not düşer; aynı adla
            // açılmışsa aynı kişinin tekrar denemesidir. Üçüncü hesap işaretlenir.
            $owners = $this->deviceAccounts($session);
            $others = count($owners);
            $checks['device_accounts'] = $others;

            if ($others >= self::DEVICE_ACCOUNTS - 1) {
                $reasons[] = 'coklu';
            } elseif ($others === 1 && PlayerName::same(User::query()->whereKey($owners)->value('name'), $session->user?->name)) {
                $reasons[] = 'tekrar';
            }

            if (PlayerName::rude($session->user?->name)) {
                $reasons[] = 'ad';
            }

            $notes = array_values(array_filter([
                $others === 1 && ! in_array('tekrar', $reasons, true) ? 'cihaz' : null,
                self::schoolNumber($session->user?->student_number) ? null : 'numara',
            ]));

            return $this->complete($session, $score, $reaction, $reasons !== [], [
                'answers' => $log,
                'lives_left' => $lives,
                'reaction_ms' => $reaction,
                'abandoned' => $abandoned && $lives > 0,
                'elapsed_ms' => $elapsed,
                'flag_reasons' => $reasons,
                'notes' => $notes,
                'checks' => $checks,
            ]);
        });
    }

    /**
     * İnsan sınırı ve canlı kayıt denetimi. Döner: [gerekçe kodları, ölçümler].
     *
     * Saat denetimi: telefon her cevabın "Başla"dan bu yana oyun saatini
     * gönderir, sunucu geliş anını yazar. Dürüst telefonda iki saat arasındaki
     * fark yalnız ağ gecikmesidir, birikmez; oyun saati de tepki + sabit
     * bekleme + birkaç ms kare payıyla ilerler. Süreyi yavaşlatan ya da
     * tepkiyi küçük yazan değişiklik bu ikisinden birini bozar.
     *
     * @param  array<int, array{correct: bool, ms: int, limit: int, clock: int, server: int, input: string, x: ?int, y: ?int}|null>  $live
     * @param  list<int>  $hits
     * @return array{0: list<string>, 1: array<string, int|null>}
     */
    private function suspicions(array $live, array $hits, int $score, int $claimed, int $elapsed): array
    {
        $reasons = [];
        $count = count($hits);
        $sd = self::deviation($hits);
        $median = self::median($hits);
        $tooFast = count(array_filter($hits, fn (int $ms) => $ms < self::BOT_MS));
        $fast = count(array_filter($hits, fn (int $ms) => $ms < self::FAST_MS));

        if ($tooFast >= self::BOT_HITS || $fast >= self::FAST_HITS || ($count >= self::MEDIAN_HITS && $median < self::MEDIAN_MS)) {
            $reasons[] = 'hiz';
        }

        if ($count >= self::STEADY_HITS && $sd < self::STEADY_SD) {
            $reasons[] = 'duzen';
        }

        if ($score >= self::HUMAN_MAX) {
            $reasons[] = 'sinir';
        }

        $entries = array_filter($live);
        $missing = count($live) - count($entries);

        if ($missing > 3 && $missing > count($live) * 0.15) {
            $reasons[] = 'canli';
        }

        // Tür bilinmeyen ('') eski tarayıcı olabilir; yalnız fare işaretlenir.
        if (array_filter($entries, fn (array $entry) => $entry['correct'] && $entry['input'] === 'm')) {
            $reasons[] = 'dokunus';
        }

        // Dokunuşun tuştaki yeri: bir eksende bile hep aynı noktaysa parmak değil.
        $taps = array_filter($entries, fn (array $entry) => $entry['correct'] && $entry['x'] !== null && $entry['y'] !== null);
        $spread = count($taps) >= self::TAP_HITS
            ? min(self::deviation(array_column($taps, 'x')), self::deviation(array_column($taps, 'y')))
            : null;

        if ($spread !== null && $spread < self::TAP_SD) {
            $reasons[] = 'parmak';
        }

        // Ardışık iki canlı cevap arasında oyun saatinin tepki ve sabit
        // beklemeyle açıklanamayan kısmı (dürüst telefonda birkaç ms).
        $idle = [];
        $backwards = false;

        foreach ($entries as $index => $entry) {
            $previous = $live[$index - 1] ?? null;

            if ($previous) {
                $idle[$index] = $entry['clock'] - $previous['clock'] - $entry['ms'] - ($previous['correct'] ? self::GAP_HIT : self::GAP_MISS);
                $backwards = $backwards || $idle[$index] < -30;
            }
        }

        $base = min(self::median($idle), 60);
        $idleExcess = array_sum(array_map(
            fn (int $index) => $live[$index]['correct'] ? max(0, $idle[$index] - $base - self::IDLE_STEP_MS) : 0,
            array_keys($idle),
        ));
        // Zor sorularda (süre ≤ 625 ms) doğrudan önce kolaylara göre fazladan bekleme.
        $hard = array_filter($idle, fn (int $index) => $live[$index]['correct'] && $live[$index]['limit'] <= 625, ARRAY_FILTER_USE_KEY);
        $easy = array_filter($idle, fn (int $index) => $index < 20, ARRAY_FILTER_USE_KEY);
        $hardExcess = count($hard) >= 5 && count($easy) >= 5 ? self::median($hard) - self::median($easy) : 0;

        // Telefon saati ile sunucu saati arasındaki fark: son beş canlı cevabın
        // en küçüğü (tek bir ağ takılması sayılmaz).
        $drifts = array_map(fn (array $entry) => $entry['server'] - $entry['clock'], $entries);
        $drift = $drifts ? min(array_slice($drifts, -5)) : 0;
        $first = $live[0]['server'] ?? 0;

        if ($backwards
            || $idleExcess > self::IDLE_MS
            || $hardExcess > self::IDLE_HARD_MS
            || $drift > self::DRIFT_MS
            || $first > self::FIRST_ANSWER_MS
            || $claimed > $elapsed + 2000) {
            $reasons[] = 'zaman';
        }

        return [$reasons, [
            'too_fast' => $tooFast,
            'fast' => $fast,
            'median_ms' => $median,
            'sd' => $sd,
            'tap_sd' => $spread,
            'missing' => $missing,
            'idle_ms' => $idleExcess,
            'hard_idle_ms' => $hardExcess,
            'drift_ms' => $drift,
            'first_ms' => $first,
        ]];
    }

    /**
     * Bu telefondan bu oyunda bitmiş başka hesaplar (kullanıcı kimlikleri).
     * Telefon üç izden tanınır: tarayıcı kimliği, sunucu çerezi, ya da (son 6
     * saatte) aynı IPv6 ağı ile aynı cihaz özeti. Biri silinse öbürü tutar.
     *
     * @return list<int>
     */
    private function deviceAccounts(GameSession $session): array
    {
        $meta = $session->meta ?? [];
        $device = $meta['device'] ?? null;
        $cookie = $meta['cookie'] ?? null;
        $net = $meta['net'] ?? null;
        $print = $meta['print'] ?? null;

        if (! $device && ! $cookie && ! ($net && $print)) {
            return [];
        }

        return GameSession::query()
            ->where('campaign_id', $session->campaign_id)
            ->whereKeyNot($session->getKey())
            ->where('user_id', '!=', $session->user_id)
            ->whereNotNull('finished_at')
            ->where(fn ($query) => $query
                ->when($device, fn ($query) => $query->orWhere('meta->device', $device))
                ->when($cookie, fn ($query) => $query->orWhere('meta->cookie', $cookie))
                ->when($net && $print, fn ($query) => $query->orWhere(fn ($query) => $query
                    ->where('meta->net', $net)
                    ->where('meta->print', $print)
                    ->where('finished_at', '>=', now()->subHours(self::NETWORK_HOURS)))))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Tarayıcı ortamı ("Başla"da telefon gönderir): otomasyonla yönetilen
     * tarayıcı, bilgisayarda telefon taklidi (ekran kartı) ya da dokunmatik
     * ekranı olmayan cihazdan gelen dokunuş. Ortam bilgisi yoksa (eski sayfa)
     * hiçbir şey denmez.
     *
     * @param  array<int, array<string, mixed>>  $entries  canlı cevaplar
     * @return list<string>
     */
    private function environment(GameSession $session, array $entries): array
    {
        $env = $session->meta['env'] ?? null;

        if (! is_array($env)) {
            return [];
        }

        $agent = (string) $session->user_agent;
        $gpu = (string) ($env['gpu'] ?? '');
        $touched = (bool) array_filter($entries, fn (array $entry) => $entry['correct'] && in_array($entry['input'], ['t', 'p'], true));
        $reasons = ! empty($env['wd']) ? ['otomasyon'] : [];

        if (($gpu !== '' && preg_match('/iPhone|iPad|iPod/i', $agent) && preg_match(self::DESKTOP_GPU, $gpu))
            || ($gpu !== '' && preg_match('/Android/i', $agent) && preg_match(self::ANDROID_DESKTOP_GPU, $gpu))
            || ((int) ($env['tp'] ?? -1) === 0 && $touched)) {
            $reasons[] = 'ortam';
        }

        return $reasons;
    }

    /**
     * Görevliye gösterilecek gerekçeler (işaret ve notlar).
     *
     * @param  array<string, mixed>|null  $meta
     * @return list<string>
     */
    public static function reasonLabels(?array $meta): array
    {
        return array_values(array_map(
            fn (string $code) => self::FLAG_REASONS[$code] ?? $code,
            array_unique([...($meta['flag_reasons'] ?? []), ...($meta['notes'] ?? [])]),
        ));
    }

    /** Örneklem standart sapması (ms ya da binde); ikiden az değerde 0. @param  array<int, int>  $values */
    private static function deviation(array $values): int
    {
        $count = count($values);

        if ($count < 2) {
            return 0;
        }

        $mean = array_sum($values) / $count;

        return (int) round(sqrt(array_sum(array_map(fn (int $value) => ($value - $mean) ** 2, $values)) / ($count - 1)));
    }

    /** @param  array<int, int>  $values */
    private static function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);
    }

    private function openSession(string $token): GameSession
    {
        $session = GameSession::query()->where('token', $token)->lockForUpdate()->first();

        if (! $session || $session->isFinished()) {
            throw ValidationException::withMessages(['token' => 'Bu oyun oturumu geçersiz ya da kullanılmış.']);
        }

        return $session;
    }

    /** Sunucunun ölçtüğü oyun süresi: "Başla"dan (yoksa ekranın açılışından) bu yana. */
    private function elapsed(GameSession $session): int
    {
        $begun = $session->meta['begun_at'] ?? null;

        return $begun
            ? max(0, now()->getTimestampMs() - (int) $begun)
            : (int) $session->started_at->diffInMilliseconds(now(), true);
    }

    /** @param  array<string, mixed>  $meta */
    private function complete(GameSession $session, int $score, ?int $tiebreak, bool $flag, array $meta): GameSession
    {
        $record = $this->board->recordScore($session->user, $session->campaign, $score, tiebreak: $tiebreak);

        if ($flag) {
            $record->forceFill(['flagged_at' => now()])->save();
        }

        $session->forceFill([
            'finished_at' => now(),
            'game_score_id' => $record->getKey(),
            'meta' => array_merge($session->meta ?? [], $meta, ['flagged' => $flag]),
        ])->save();

        return $session;
    }
}
