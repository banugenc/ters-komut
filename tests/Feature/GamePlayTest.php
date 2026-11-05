<?php

namespace Tests\Feature;

use App\Http\Controllers\GameController;
use App\Mail\WelcomeMemberMail;
use App\Models\Campaign;
use App\Models\GameScore;
use App\Models\GameSession;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Services\Members\GameBoard;
use App\Services\Members\GamePlay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GamePlayTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $stroop;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->stroop = $this->game('Stant Oyunu: Ters Komut', 'ters-komut', 'number', 250, 4);
    }

    protected function tearDown(): void
    {
        GameController::$replays = false;
        GameController::$guests = true;

        parent::tearDown();
    }

    public function test_three_field_signup_makes_a_member_and_server_counts_the_first_game(): void
    {
        GameController::$replays = true;
        $this->get(route('game.index'))->assertOk()->assertSee('Oyuncu kartın')->assertSee('öğrenci e-postan hesabın olur');

        // Ad olmadan olmaz; e-posta sorulmaz.
        $this->post(route('game.register'), ['student_number' => '2511500046', 'phone' => '0555 111 22 33'])->assertSessionHasErrors('name');
        $this->post(route('game.register'), ['student_number' => '2511500046', 'phone' => '0555', 'name' => 'Elif Kaya'])->assertSessionHasErrors('phone');

        $this->post(route('game.register'), [
            'student_number' => '25115-05046',
            'phone' => '0555 111 22 33',
            'name' => 'Elif Kaya',
        ])->assertRedirect(route('game.play'));

        // Doğrudan üye: e-posta öğrenci numarasından, üyelik onaylı; hoş geldin oyun bitince.
        $player = User::query()->where('student_number', '2511500046')->sole();
        $this->assertSame('2511500046@ogr.bandirma.edu.tr', $player->email);
        $this->assertSame('approved', MembershipApplication::query()->where('email', $player->email)->value('status'));
        Mail::assertNothingSent();
        $this->get(route('game.index'))->assertSee('Tekrar hoş geldin')->assertSee('Elif');

        // Sorular sayfada yok: "Başla"da sunucudan gelir.
        $this->get(route('game.play'))->assertOk()->assertSee('data-score', false)->assertSee('Başla')->assertSee('data-practice="0"', false)
            ->assertSee('data-questions>[]<', false);
        $session = GameSession::query()->sole();
        $this->assertCount(150, $session->meta['questions']);

        // 10 doğru (400 ms), sonra 3 yanlış: skor 10, eşitlik bozucu 4000 ms.
        // Sonradan gelen cevaplar sayılmaz.
        $this->playGame($session, [...array_fill(0, 10, true), false, false, false, true, true])
            ->assertOk()->assertJson(['url' => route('game.result', $session->token)]);

        $score = GameScore::query()->sole();
        $this->assertSame(10, $score->score);
        $this->assertSame(4000, $score->tiebreak);
        $this->assertNull($score->flagged_at);
        $this->assertSame(10, app(GameBoard::class)->ranking($this->stroop)->first()->best);
        Mail::assertSent(WelcomeMemberMail::class, fn ($mail) => $mail->hasTo('2511500046@ogr.bandirma.edu.tr'));

        $this->get(route('game.result', $session->token))->assertOk()->assertSee('10')
            ->assertSee('Sıralaman')->assertSee('1 oyuncu arasında')->assertSee('Ödül bölgesi')->assertSee('Tekrar oyna')
            ->assertSee('sıralamaya yalnız ilk oyunun girer')->assertSee('2511500046@ogr.bandirma.edu.tr');

        // İsterse kişisel e-postaya geçer; giriş bağlantısı oraya da gider.
        $this->post(route('game.join', $session->token), ['email' => 'elif@example.com'])->assertRedirect(route('game.result', $session->token));
        $this->assertSame('elif@example.com', $player->refresh()->email);
        $this->assertSame('approved', MembershipApplication::query()->where('email', 'elif@example.com')->value('status'));
        Mail::assertSent(WelcomeMemberMail::class, fn ($mail) => $mail->hasTo('elif@example.com'));
        $this->get(route('game.result', $session->token))->assertSee('E-postan kaydedildi');

        // Tekrar: pratik; sorular yeni, sunucuya bir şey yazılmaz, sıralama değişmez.
        $this->get(route('game.play'))->assertRedirect(route('game.result', $session->token));
        $this->get(route('game.play', ['tekrar' => 1]))->assertOk()->assertSee('data-practice="1"', false)
            ->assertSee('sıralamaya girmez')->assertSee('10 doğru');
        $this->assertSame(1, GameSession::query()->count());
        $this->postJson(route('game.finish'), ['token' => $session->token, 'answers' => '[]'])->assertStatus(422);
        $this->assertSame(1, GameScore::query()->count());

        // Başka cihazdan: numara + aynı telefon yeter; farklı telefon reddedilir.
        $this->flushSession();
        $this->post(route('game.register'), ['student_number' => '2511500046', 'phone' => '0555 000 00 00', 'name' => 'Elif'])->assertSessionHasErrors('phone');
        $this->post(route('game.register'), ['student_number' => '2511500046', 'phone' => '+90 555 111 2233', 'name' => 'Elif'])->assertRedirect(route('game.play'));
        $this->assertSame('Elif Kaya', $player->refresh()->name);
    }

    public function test_result_shows_rank_level_and_top_five_and_refreshes(): void
    {
        $this->stroop->update(['winner_count' => 3]);
        $board = app(GameBoard::class);
        $sessions = [];

        // 7 oyuncu; iki kişi 25 doğruda berabere, hızlı olan önde.
        foreach ([[30, 9000], [25, 8000], [25, 9500], [20, 7000], [15, 6000], [10, 5000], [5, 4000]] as $i => [$score, $ms]) {
            $user = User::factory()->create(['name' => "Oyuncu{$i} Deneme", 'student_number' => '25000000'.$i.'0']);
            $record = $board->recordScore($user, $this->stroop, $score, tiebreak: $ms);
            $sessions[] = GameSession::create([
                'campaign_id' => $this->stroop->id, 'user_id' => $user->id, 'token' => Str::random(48),
                'started_at' => now(), 'finished_at' => now(), 'game_score_id' => $record->id,
            ]);
        }

        // Sonuncu: 7. sıra, Çaylak, ilk 3'ün sınırı ve kendi satırı.
        $this->get(route('game.result', $sessions[6]->token))->assertOk()
            ->assertSee('7.')->assertSee('7 oyuncu arasında')->assertSee('Çaylak')
            ->assertSee('İlk 3 şu an 25 doğru ve üstünde.')->assertSee('Sen')->assertSee('Oyuncu0 D.');

        // 25 doğru / 9500 ms: 8000 ms'nin arkasında 3., ödül bölgesinde.
        $this->get(route('game.result', $sessions[2]->token))
            ->assertSee('Ödül bölgesi')->assertSee("İlk 3'tesin!", false);
        $this->assertSame(3, $board->standing($this->stroop, $sessions[2]->user)['rank']);

        // Sayfa açıkken yenilenen kart.
        $this->getJson(route('game.standing', $sessions[6]->token))->assertOk()
            ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, '7 oyuncu arasında')));
    }

    public function test_during_the_stand_there_is_no_guest_game_and_no_replay(): void
    {
        GameController::$guests = false;
        $session = $this->rankedSession('2511500001');
        $this->playGame($session, ['ok', 'ok', 'wrong', 'wrong', 'wrong']);

        $this->get(route('game.index'))->assertOk()->assertDontSee('Misafir olarak dene')->assertDontSee('Tekrar oyna')->assertSee('Her öğrenci bir kez oynar');
        $this->get(route('game.guest'))->assertRedirect(route('game.index'));
        $this->get(route('game.play', ['tekrar' => 1]))->assertRedirect(route('game.result', $session->token));
        $this->get(route('game.result', $session->token))->assertOk()->assertDontSee('Tekrar oyna');
    }

    public function test_guests_play_without_signing_up_and_nothing_is_saved(): void
    {
        GameController::$replays = true;
        $users = User::query()->count();

        // Giriş ekranı: misafir düğmesi ve uyarı penceresi.
        $this->get(route('game.index'))->assertOk()
            ->assertSee('Misafir olarak dene')->assertSee('Skorun sıralamaya girmez.')->assertSee('Yine de misafir oyna');

        // Misafir oyunu açılır; oturum, kullanıcı, skor kaydı oluşmaz.
        $this->get(route('game.guest'))->assertOk()
            ->assertSee('data-guest="1"', false)->assertSee('data-practice="1"', false)->assertSee('Kayıt ol, ödüle katıl')
            ->assertSee('ödüle geçmez')->assertSee('data-guest-score', false);
        $this->assertSame(0, GameSession::query()->count());
        $this->assertSame(0, GameScore::query()->count());
        $this->assertSame($users, User::query()->count());

        // Oyun kapalıyken misafir de giriş ekranına döner.
        $this->stroop->update(['is_active' => false]);
        $this->get(route('game.guest'))->assertRedirect(route('game.index'));
    }

    public function test_guest_game_can_be_open_while_replays_stay_closed(): void
    {
        $session = $this->rankedSession('2511500002');
        $this->playGame($session, ['ok', 'ok', 'wrong', 'wrong', 'wrong']);
        $this->get(route('game.play', ['tekrar' => 1]))->assertRedirect(route('game.result', $session->token));

        $this->flushSession();
        $this->get(route('game.index'))->assertOk()->assertSee('Misafir olarak dene')->assertSee('Yine de misafir oyna');
        $this->get(route('game.guest'))->assertOk()->assertSee('data-guest="1"', false);
    }

    public function test_game_set_to_open_later_shows_its_opening_time_until_then(): void
    {
        $this->stroop->update(['start_date' => '2026-10-19 09:00:00']);

        $this->travelTo('2026-10-18 18:00:00');
        $this->get(route('game.index'))->assertOk()
            ->assertSee('Oyun henüz başlamadı')->assertSee('19 Ekim Pazartesi, 09.00')->assertDontSee('Oyuncu kartın');
        $this->get(route('game.guest'))->assertRedirect(route('game.index'));
        $this->get(route('game.play'))->assertRedirect(route('game.index'));

        $this->travelTo('2026-10-19 09:00:00');
        $this->get(route('game.index'))->assertOk()->assertSee('Oyuncu kartın')->assertDontSee('Oyun henüz başlamadı');
    }

    public function test_ended_game_says_so_takes_no_signups_and_keeps_its_results(): void
    {
        $player = User::factory()->create(['student_number' => '2511506001']);
        $this->withSession(['game.player_id' => $player->id])->get(route('game.play'))->assertOk();
        $session = GameSession::query()->sole();
        $this->playGame($session, array_fill(0, 12, true), array_map(fn (int $i) => 480 + ($i * 37) % 160, range(0, 11)))->assertOk();
        $this->flushSession();

        // Bitiş tarihi geçti: giriş sayfası "Oyun bitti" der, kayıt ve oyun kapanır.
        $this->stroop->update(['end_date' => now()->subMinute()]);
        $this->get(route('game.index'))->assertOk()
            ->assertSee('Oyun bitti')->assertDontSee('Stant günü açılıyor')->assertDontSee('Oyuncu kartın');
        $this->post(route('game.register'), ['student_number' => '2511506002', 'phone' => '0555 600 00 02', 'name' => 'Deniz Kara'])
            ->assertRedirect(route('game.index'));
        $this->assertFalse(User::query()->where('student_number', '2511506002')->exists());
        $this->get(route('game.play'))->assertRedirect(route('game.index'));
        $this->get(route('game.guest'))->assertRedirect(route('game.index'));

        // Sonuç sayfası ve sıralama kartı kalır.
        $this->get(route('game.result', $session->token))->assertOk()->assertSee('Sıralaman');
    }

    public function test_number_without_school_mail_joins_with_email_after_the_game(): void
    {
        // Yeni numara 10 haneli olmalı; 2025 öncesi girişlinin okul e-postası yok, oyun sonunda sorulur.
        $this->post(route('game.register'), ['student_number' => '1234567', 'phone' => '0555 100 00 09', 'name' => 'Can Er'])
            ->assertSessionHasErrors(['student_number' => 'Öğrenci numaran 10 haneli olmalı.']);
        $this->post(route('game.register'), ['student_number' => '2311507009', 'phone' => '0555 100 00 09', 'name' => 'Can Er'])->assertRedirect(route('game.play'));
        $player = User::query()->where('student_number', '2311507009')->sole();
        $this->assertNull($player->email);

        $this->get(route('game.play'));
        $session = GameSession::query()->sole();
        $this->playGame($session, [true, false, false, false])->assertOk();

        $this->get(route('game.result', $session->token))->assertSee('Üye ol');
        $this->post(route('game.join', $session->token), ['email' => 'can@example.com'])->assertRedirect(route('game.result', $session->token));
        $this->assertSame('can@example.com', $player->refresh()->email);
        Mail::assertSent(WelcomeMemberMail::class, fn ($mail) => $mail->hasTo('can@example.com'));
    }

    public function test_two_students_with_the_same_name_stay_separate(): void
    {
        $this->post(route('game.register'), ['student_number' => '2411502001', 'phone' => '0555 100 00 01', 'name' => 'Ali Yılmaz'])->assertRedirect(route('game.play'));
        $this->flushSession();
        $this->post(route('game.register'), ['student_number' => '2411502002', 'phone' => '0555 100 00 02', 'name' => 'Ali Yılmaz'])->assertRedirect(route('game.play'));

        $this->assertSame(['2411502001', '2411502002'], User::query()->where('name', 'Ali Yılmaz')->orderBy('id')->pluck('student_number')->all());
    }

    public function test_equal_scores_are_ranked_by_total_reaction_time(): void
    {
        foreach (['Yavaş Oyuncu' => 900, 'Hızlı Oyuncu' => 450] as $name => $ms) {
            $player = User::factory()->create(['name' => $name, 'student_number' => (string) random_int(1000000, 9999999)]);
            $this->withSession(['game.player_id' => $player->id])->get(route('game.play'))->assertOk();
            $session = GameSession::query()->where('user_id', $player->id)->sole();
            $this->playGame($session, array_fill(0, 8, true), $ms)->assertOk();
            $this->flushSession();
        }

        $this->assertSame(['Hızlı O.', 'Yavaş O.'], app(GameBoard::class)->ranking($this->stroop)->pluck('name')->all());

        // Stant ekranı eşitliği bozan toplam süreyi puanın yanında gösterir.
        $rows = app(GameBoard::class)->screenPayload(10, 'ters-komut')['games'][0]['rows'];
        $this->assertSame(['3,60 sn', '7,20 sn'], array_column($rows, 'time'));
    }

    public function test_inhuman_reactions_are_flagged_and_hidden_from_ranking(): void
    {
        $session = $this->rankedSession('1234567');
        $this->playGame($session, array_fill(0, 40, true), 60)->assertOk();

        $this->assertSame(40, GameScore::query()->sole()->score);
        $this->assertNotNull(GameScore::query()->sole()->flagged_at);
        $this->assertContains('hiz', $session->refresh()->meta['flag_reasons']);
        $this->assertTrue(app(GameBoard::class)->ranking($this->stroop)->isEmpty());
    }

    public function test_a_real_human_game_with_lucky_taps_and_network_hiccups_is_not_flagged(): void
    {
        $session = $this->rankedSession('2400000001');

        // 30 doğru, 2 yanlış; süre kısaldıkça hızlanan, dağınık tepkiler.
        // Yeni kelime gelirken tahminle iki dokunuş (95 ve 150 ms), bir kez
        // takılan telefon (180 ms kare), iki cevap ağda 2-2,5 sn gecikmiş.
        $pattern = array_fill(0, 32, true);
        $pattern[12] = $pattern[27] = false;
        $ms = array_map(fn (int $i) => min(GamePlay::timeLimit($i + 1) - 40, 520 + ($i * 137) % 380), range(0, 31));
        $ms[7] = 95;
        $ms[19] = 150;

        $this->playGame($session, $pattern, $ms, ['jank' => [15 => 180], 'lag' => [9 => 2500, 31 => 2000]])->assertOk();

        $this->assertSame(30, GameScore::query()->sole()->score);
        $this->assertNull(GameScore::query()->sole()->flagged_at);
        $this->assertSame([], $session->refresh()->meta['flag_reasons']);
        $this->assertSame(0, $session->meta['checks']['missing']);
    }

    public function test_questions_come_only_at_start_and_live_answers_cannot_be_changed(): void
    {
        $session = $this->rankedSession('2400000002');

        // "Başla"dan önce gelen cevap yazılmaz.
        $this->postJson(route('game.answer'), ['token' => $session->token, 'i' => 0, 'c' => 1, 'r' => 400, 'k' => 4900, 'p' => 't'])->assertNoContent();
        $this->assertArrayNotHasKey('stream', $session->refresh()->meta);

        $questions = $this->begin($session);
        $this->assertSame($session->refresh()->meta['questions'], $questions);
        $begunAt = $session->meta['begun_at'];
        $this->travel(2)->seconds();
        $this->assertSame($questions, $this->postJson(route('game.begin'), ['token' => $session->token])->json('questions'));
        $this->assertSame($begunAt, $session->refresh()->meta['begun_at']);

        // Her soruya ilk gelen cevap geçerli: sonradan düzeltilemez.
        $this->postJson(route('game.answer'), ['token' => $session->token, 'i' => 0, 'c' => ($questions[0][1] + 1) % 4, 'r' => 600, 'k' => 5100, 'p' => 't'])->assertNoContent();
        $this->postJson(route('game.answer'), ['token' => $session->token, 'i' => 0, 'c' => $questions[0][1], 'r' => 400, 'k' => 4900, 'p' => 't'])->assertNoContent();
        $this->assertSame(($questions[0][1] + 1) % 4, $session->refresh()->meta['stream'][0][0]);

        // Başkasının anahtarıyla cevap gönderilemez.
        $other = User::factory()->create(['student_number' => '2400000003']);
        $this->withSession(['game.player_id' => $other->id])
            ->postJson(route('game.answer'), ['token' => $session->token, 'i' => 1, 'c' => 0, 'r' => 400, 'k' => 6000, 'p' => 't'])->assertStatus(422);
        $this->withSession(['game.player_id' => $session->user_id]);

        // Biten oyuna cevap yazılmaz.
        $this->postJson(route('game.finish'), ['token' => $session->token, 'answers' => '[]'])->assertOk();
        $this->postJson(route('game.answer'), ['token' => $session->token, 'i' => 1, 'c' => 0, 'r' => 400, 'k' => 6000, 'p' => 't'])->assertStatus(422);
    }

    public function test_made_up_or_edited_answer_lists_are_flagged(): void
    {
        // Oynamadan uydurulmuş liste: hiçbir cevap canlı gelmedi.
        $forged = $this->rankedSession('2400000004');
        $this->playGame($forged, array_fill(0, 30, true), 450, ['live' => false])->assertOk();
        $this->assertContains('canli', $forged->refresh()->meta['flag_reasons']);
        $this->flushSession();

        // Sonda düzeltilmiş liste: canlı kayıt esas, liste yalanı işaretlenir.
        $edited = $this->rankedSession('2400000005');
        $pattern = [...array_fill(0, 10, true), false, ...array_fill(0, 10, true)];
        $ms = array_map(fn (int $i) => 420 + ($i * 53) % 160, range(0, 20));
        $this->playGame($edited, $pattern, $ms, ['list' => function (array $answers) use ($edited): array {
            $answers[10][0] = $edited->refresh()->meta['questions'][10][1];

            return $answers;
        }])->assertOk();

        $this->assertSame(20, $edited->refresh()->score->score);
        $this->assertSame(['liste'], $edited->meta['flag_reasons']);
        $this->assertTrue(app(GameBoard::class)->ranking($this->stroop)->isEmpty());
    }

    public function test_slowing_the_game_down_is_caught_by_the_server_clock(): void
    {
        $ms = array_map(fn (int $i) => 420 + ($i * 53) % 160, range(0, 24));

        // Telefonda süreyi durdurup tepkiyi küçük yazan: oyun saati beklemeyi ele verir.
        $paused = $this->rankedSession('2400000006');
        $this->playGame($paused, array_fill(0, 25, true), $ms, ['think' => 300])->assertOk();
        $this->assertSame(['zaman'], $paused->refresh()->meta['flag_reasons']);
        $this->flushSession();

        // Oyun saatini de uyduran: sunucu saati her cevapta biraz daha geride kalır.
        $faked = $this->rankedSession('2400000007');
        $this->playGame($faked, array_fill(0, 25, true), $ms, ['think' => 300, 'fake_clock' => true])->assertOk();
        $this->assertSame(['zaman'], $faked->refresh()->meta['flag_reasons']);
        $this->assertGreaterThan(3000, $faked->meta['checks']['drift_ms']);
    }

    public function test_bot_like_play_is_flagged_by_human_limits(): void
    {
        // Hep aynı hızda (±3 ms) basan.
        $steady = $this->rankedSession('2400000008');
        $this->playGame($steady, array_fill(0, 20, true), array_map(fn (int $i) => 400 + $i % 4 * 2, range(0, 19)))->assertOk();
        $this->assertSame(['duzen'], $steady->refresh()->meta['flag_reasons']);
        $this->flushSession();

        // Dürüst ~1000 oyunda en yüksek 35: 36 doğru (300 ms altı hiç olmadan) görevliye düşer.
        $ceiling = $this->rankedSession('2400000009');
        $ms = array_map(fn (int $i) => min(GamePlay::timeLimit($i + 1) - 10, 380 + ($i * 71) % 130), range(0, 35));
        $this->playGame($ceiling, array_fill(0, 36, true), $ms)->assertOk();
        $this->assertSame(['sinir'], $ceiling->refresh()->meta['flag_reasons']);
        $this->flushSession();

        // Fareyle (bilgisayardan) oynanmış.
        $mouse = $this->rankedSession('2400000010');
        $this->playGame($mouse, array_fill(0, 10, true), 500, ['input' => 'm'])->assertOk();
        $this->assertSame(['dokunus'], $mouse->refresh()->meta['flag_reasons']);
    }

    public function test_same_phone_for_two_numbers_is_noted_for_staff_not_hidden(): void
    {
        $first = $this->rankedSession('2400000011');
        $this->playGame($first, array_fill(0, 12, true), 600, ['device' => 'telefon-1234-abcd'])->assertOk();
        $this->flushSession();

        $second = $this->rankedSession('2400000012');
        $this->playGame($second, array_fill(0, 14, true), 600, ['device' => 'telefon-1234-abcd'])->assertOk();

        $this->assertSame([], $first->refresh()->meta['notes']);
        $this->assertSame(['cihaz'], $second->refresh()->meta['notes']);
        $this->assertNull($second->score->flagged_at);
        $this->assertCount(2, app(GameBoard::class)->ranking($this->stroop));

        // Görevli konsolu: işaret gerekçeleri ve kazanan notları görünür.
        $flagged = $this->rankedSession('2400000013');
        $this->playGame($flagged, array_fill(0, 40, true), 60)->assertOk();
        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('manage_points', 'web'));

        $this->actingAs($staff, 'web')->get(route('stand.console'))->assertOk()
            ->assertSee(GamePlay::FLAG_REASONS['hiz'])
            ->assertSee(GamePlay::FLAG_REASONS['cihaz']);
    }

    public function test_third_account_on_one_phone_is_flagged_and_fourth_cannot_register(): void
    {
        // Tarayıcı kimliği her hesapta silinse de sunucu çerezi aynı telefonu tanır
        // (telefondaki fetch çerezi gönderir; test istemcisinde withCredentials).
        $this->withCredentials()->withCookie('tk_cihaz', str_repeat('a', 32));

        foreach (['2511500001', '2511500002', '2511500003'] as $i => $number) {
            $session = $this->rankedSession($number);
            $this->playGame($session, array_fill(0, 12, true), array_map(fn (int $j) => 480 + ($j * 37) % 160, range(0, 11)), ['device' => "silinen-kimlik-{$i}"])->assertOk();
            $this->flushSession();
        }

        $sessions = GameSession::query()->orderBy('id')->get();
        $this->assertSame([[], [], ['coklu']], $sessions->map(fn (GameSession $session) => $session->meta['flag_reasons'])->all());
        $this->assertSame([[], ['cihaz'], []], $sessions->map(fn (GameSession $session) => $session->meta['notes'])->all());
        $this->assertCount(2, app(GameBoard::class)->ranking($this->stroop));

        // Dördüncü hesap açılmaz: üye kaydı da oluşmaz.
        $this->post(route('game.register'), ['student_number' => '2511500004', 'phone' => '0555 200 00 04', 'name' => 'Deniz Kara'])
            ->assertSessionHasErrors('device');
        $this->assertFalse(User::query()->where('student_number', '2511500004')->exists());

        // Başka telefon (başka çerez) etkilenmez.
        $this->withCookie('tk_cihaz', str_repeat('b', 32));
        $this->post(route('game.register'), ['student_number' => '2511500004', 'phone' => '0555 200 00 04', 'name' => 'Deniz Kara'])
            ->assertRedirect(route('game.play'));
    }

    public function test_second_account_with_the_same_name_on_one_phone_is_a_retry(): void
    {
        // Arkadaşının telefonundan oynayan başka adla gelir; aynı ad aynı kişinin tekrar denemesidir.
        $this->withCredentials()->withCookie('tk_cihaz', str_repeat('c', 32));

        foreach (['Berkay Demircanlı', 'Berkay Demircanli'] as $i => $name) {
            $player = User::factory()->create(['name' => $name, 'student_number' => "251150800{$i}"]);
            $this->withSession(['game.player_id' => $player->id])->get(route('game.play'))->assertOk();
            $session = GameSession::query()->where('user_id', $player->id)->sole();
            $this->playGame($session, array_fill(0, 12, true), array_map(fn (int $j) => 480 + ($j * 37) % 160, range(0, 11)))->assertOk();
            $this->flushSession();
        }

        $second = GameSession::query()->latest('id')->first();
        $this->assertSame(['tekrar'], $second->meta['flag_reasons']);
        $this->assertSame([], $second->meta['notes']);
        $this->assertCount(1, app(GameBoard::class)->ranking($this->stroop));
    }

    public function test_new_players_need_a_school_number_and_a_real_full_name(): void
    {
        $register = fn (array $fields) => $this->post(route('game.register'), [
            'student_number' => '2511507001', 'phone' => '0555 300 00 01', 'name' => 'Selin Ay', ...$fields,
        ]);

        $register(['student_number' => '64664'])->assertSessionHasErrors(['student_number' => 'Öğrenci numaran 10 haneli olmalı.']);
        $register(['student_number' => '251150700'])->assertSessionHasErrors(['student_number' => 'Öğrenci numaran 10 haneli olmalı.']);
        $register(['student_number' => '4611507001'])->assertSessionHasErrors(['student_number' => 'Öğrenci numaranı kontrol et.']);
        $register(['name' => 'APOYUDOMALTAN'])->assertSessionHasErrors(['name' => 'Adını ve soyadını yaz.']);
        $register(['name' => 'I M God'])->assertSessionHasErrors(['name' => 'Adını ve soyadını yaz.']);
        $register(['name' => 'Apo S.i.k.e.n'])->assertSessionHasErrors(['name' => 'Adını ve soyadını gerçek hâliyle yaz.']);
        $register(['name' => 'deneme123 Bot'])->assertSessionHasErrors(['name' => 'Adında yalnız harf olsun.']);
        $this->assertFalse(User::query()->where('student_number', '2511507001')->exists());

        // Gerçek adlar geçer: Şık ve Işık soyadları, kesme işareti.
        $register(['name' => 'Yusuf Şık'])->assertRedirect(route('game.play'));
        $this->flushSession();
        $register(['student_number' => '2511507002', 'phone' => '0555 300 00 02', 'name' => "Ayşe Nur O'Neil Işık"])->assertRedirect(route('game.play'));
    }

    public function test_desktop_imitation_automation_and_machine_taps_are_flagged(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6.1 Mobile/15E148 Safari/604.1');
        $ms = array_map(fn (int $i) => 480 + ($i * 37) % 160, range(0, 15));
        $finger = fn (int $i) => [200 + ($i * 137) % 600, 150 + ($i * 89) % 700];
        $iphone = ['wd' => '0', 'tp' => 5, 'gpu' => 'Apple GPU', 'scr' => '390x844@3', 'fp' => 'k3x9q1'];
        $cases = [
            // Gerçek iPhone: ekran kartı "Apple GPU", dokunuşlar dağınık.
            '2511509001' => [[], $iphone, $finger],
            // Bilgisayarda Chrome'un iPhone taklidi: Mac'in ekran kartı görünür.
            '2511509002' => [['ortam'], ['gpu' => 'ANGLE (Apple, ANGLE Metal Renderer: Apple M2, Unspecified Version)'] + $iphone, $finger],
            // Otomasyonla (Puppeteer, Selenium) yönetilen tarayıcı.
            '2511509003' => [['otomasyon'], ['wd' => '1'] + $iphone, $finger],
            // Dokunmatik ekranı olmayan cihazdan "parmak" dokunuşu.
            '2511509004' => [['ortam'], ['tp' => 0] + $iphone, $finger],
            // Her seferinde tuşun tam ortasına dokunan.
            '2511509005' => [['parmak'], $iphone, fn (int $i) => [500, 500 + $i % 2]],
        ];

        foreach ($cases as $number => [$expected, $trace, $taps]) {
            $session = $this->rankedSession($number);
            $this->playGame($session, array_fill(0, 16, true), $ms, ['trace' => $trace, 'taps' => $taps])->assertOk();
            $this->assertSame($expected, $session->refresh()->meta['flag_reasons'], $number);
            $this->flushSession();
        }

        $this->assertSame('Apple GPU', GameSession::query()->first()->meta['env']['gpu']);
    }

    public function test_bot_hiding_behind_slower_answers_is_caught_but_fast_humans_are_not(): void
    {
        // Ortanca 315 ms, 300 ms altı hiç yok: 20 Ekim'deki botun ikinci denemesi gibi.
        $bot = $this->rankedSession('2511509101');
        $this->playGame($bot, array_fill(0, 12, true), array_map(fn (int $i) => 305 + ($i * 7) % 21, range(0, 11)))->assertOk();
        $this->assertSame(['hiz'], $bot->refresh()->meta['flag_reasons']);
        $this->flushSession();

        // En hızlı dürüst oyuncu gibi: ortanca ~400 ms, iki şanslı 290 ms.
        $human = $this->rankedSession('2511509102');
        $this->playGame($human, array_fill(0, 14, true), [290, 420, 385, 450, 290, 398, 372, 431, 405, 389, 466, 377, 412, 440])->assertOk();
        $this->assertSame([], $human->refresh()->meta['flag_reasons']);
    }

    public function test_shared_phone_accounts_from_before_the_rule_are_flagged_by_command(): void
    {
        // Kural gelmeden bitmiş oyunlar: aynı telefondan dört hesap, küfürlü ad ve başka
        // telefondan aynı adla iki hesap; hepsi geçerli sayılmış.
        $accounts = ['Ali Bir' => 'eski-telefon', 'Ali İki' => 'eski-telefon', 'Ali Üç' => 'eski-telefon', 'Ali Dört' => 'eski-telefon',
            'APOYUDOMALTAN' => 'baska-telefon', 'Furykan' => 'ucuncu-telefon', 'Furykann' => 'ucuncu-telefon'];

        foreach (array_keys($accounts) as $i => $name) {
            $user = User::factory()->create(['name' => $name]);
            $score = app(GameBoard::class)->recordScore($user, $this->stroop, 20 + $i, tiebreak: 9000);
            GameSession::create([
                'campaign_id' => $this->stroop->id,
                'user_id' => $user->id,
                'token' => Str::random(48),
                'started_at' => now(),
                'finished_at' => now()->addMinutes($i),
                'game_score_id' => $score->id,
                'meta' => ['device' => $accounts[$name], 'flag_reasons' => [], 'notes' => [], 'flagged' => false],
            ]);
        }

        $this->artisan('games:flag-shared-devices')->expectsOutputToContain('4 oyun işaretlenecek')->assertSuccessful();
        $this->assertSame(0, GameScore::query()->whereNotNull('flagged_at')->count());

        $this->artisan('games:flag-shared-devices --apply')->assertSuccessful();
        $sessions = GameSession::query()->orderBy('finished_at')->get();
        $this->assertSame([[], [], ['coklu'], ['coklu'], ['ad'], [], ['tekrar']], $sessions->map(fn (GameSession $session) => $session->meta['flag_reasons'])->all());
        $this->assertSame(['cihaz'], $sessions[1]->meta['notes']);
        $this->assertSame([], $sessions[6]->meta['notes']);
        $this->assertCount(3, app(GameBoard::class)->ranking($this->stroop));

        // İkinci çalıştırma bir şey değiştirmez.
        $this->artisan('games:flag-shared-devices')->expectsOutputToContain('0 oyun işaretlenecek')->assertSuccessful();
    }

    public function test_reloading_a_started_game_finishes_it_with_the_answers_so_far(): void
    {
        $player = User::factory()->create(['student_number' => '7654321']);
        $this->withSession(['game.player_id' => $player->id])->get(route('game.play'))->assertSee('data-resume="0"', false);
        $session = GameSession::query()->sole();
        $answers = $this->playAnswers($session, $this->begin($session), [true, true, true, false]);

        // Yenileme: yeni oyun açılmaz, aynı oturum "yarıda kaldı" olarak gelir.
        $this->get(route('game.play'))->assertSee('data-resume="1"', false)->assertDontSee('data-start', false);
        $this->travel(2)->seconds();
        $this->post(route('game.finish'), ['token' => $session->token, 'answers' => json_encode($answers), 'abandoned' => '1'])
            ->assertRedirect(route('game.result', $session->token));

        $this->assertSame(3, GameScore::query()->sole()->score);
        $this->assertTrue($session->refresh()->meta['abandoned']);
        $this->assertSame([], $session->meta['flag_reasons']);
    }

    public function test_session_opened_before_questions_existed_is_repaired_not_burned(): void
    {
        // Oyun yayına girmeden önceki şablon ekranında açılmış, sorusuz oturum;
        // üstelik "Başla"ya basılmış (geri sayım 1'de takılıyordu).
        $player = User::factory()->create(['student_number' => '5555555']);
        $session = GameSession::create([
            'campaign_id' => $this->stroop->id,
            'user_id' => $player->id,
            'token' => Str::random(48),
            'started_at' => now(),
            'meta' => ['begun_at' => now()->getTimestampMs()],
        ]);

        $this->withSession(['game.player_id' => $player->id])->get(route('game.play'))
            ->assertOk()->assertSee('data-resume="0"', false)->assertSee('data-start', false);

        $this->assertCount(150, $session->refresh()->meta['questions']);
        $this->assertArrayNotHasKey('begun_at', $session->meta);
        $this->assertSame(1, GameSession::query()->count());
    }

    public function test_signed_in_member_plays_with_one_tap_and_tokens_stay_with_their_owner(): void
    {
        $member = User::factory()->create(['name' => 'Deniz Ak', 'phone' => '05551234567', 'student_number' => null]);

        // Hesabı açık üyeden yalnız eksik olan istenir.
        $this->actingAs($member, 'member')->get(route('game.index'))->assertSee('Merhaba Deniz')->assertSee('Öğrenci no')->assertDontSee('Ad soyad');
        $this->post(route('game.register'), ['student_number' => '2511503333'])->assertRedirect(route('game.play'));
        $this->assertSame('2511503333', $member->refresh()->student_number);

        $this->flushSession();
        $this->actingAs($member, 'member')->get(route('game.index'))->assertDontSee('Öğrenci no');
        $this->post(route('game.register'))->assertRedirect(route('game.play'));
        $this->get(route('game.play'))->assertOk();

        // Başkasının oyun anahtarıyla skor gönderilemez.
        $token = GameSession::query()->sole()->token;
        $other = User::factory()->create(['student_number' => '4444444']);
        $this->flushSession();
        $this->withSession(['game.player_id' => $other->id])->postJson(route('game.finish'), ['token' => $token, 'answers' => '[]'])->assertStatus(422);
        $this->assertNull(GameSession::query()->where('token', $token)->value('finished_at'));
    }

    /** Oyun sayfasını açmış, sıralamalı oyuna hazır oyuncu. */
    private function rankedSession(string $studentNumber): GameSession
    {
        $player = User::factory()->create(['student_number' => $studentNumber]);
        $this->withSession(['game.player_id' => $player->id])->get(route('game.play'))->assertOk();

        return GameSession::query()->where('user_id', $player->id)->sole();
    }

    /**
     * "Başla": sorular gelir, sunucu saati durur ve bundan sonra yalnız
     * cevaplarla ilerler.
     *
     * $trace: telefonun "Başla"da gönderdiği ortam izi (wd, tp, gpu, scr, fp).
     *
     * @param  array<string, mixed>  $trace
     * @return list<array{0: int, 1: int}>
     */
    private function begin(GameSession $session, ?string $device = null, array $trace = []): array
    {
        $this->freezeTime();

        return $this->postJson(route('game.begin'), ['token' => $session->token, 'device' => $device, ...$trace])->assertOk()->json('questions');
    }

    /**
     * Telefondaki oyunu taklit eder: her cevap basıldığı anda canlı gider.
     * Oyun saati (telefon) ve sunucu saati tepki + sabit bekleme + 20 ms kare
     * payı kadar ilerler. Hileler: think (tepkiye yazılmayan bekleme),
     * fake_clock (oyun saati de bunu gizler), live false (hiç canlı gönderme),
     * input (dokunuş türü), taps (fn (int $i) => [x, y]: dokunuşun tuştaki yeri,
     * binde). Dürüst aksaklıklar: jank (telefonda takılma, ms), lag (o cevabın
     * ağda gecikmesi, ms).
     *
     * @param  list<bool>  $pattern  true doğru renk, false yanlış
     * @param  int|list<int>  $ms  tepki süreleri
     * @param  array<string, mixed>  $options
     * @return list<array{0: int, 1: int}> telefondaki cevap listesi
     */
    private function playAnswers(GameSession $session, array $questions, array $pattern, int|array $ms = 400, array $options = []): array
    {
        $answers = [];
        $clock = 0;
        $previous = null;

        foreach ($pattern as $i => $correct) {
            $reaction = is_array($ms) ? $ms[$i] : $ms;
            // İlk soru geri sayımdan sonra gelir.
            $wait = ($previous === null ? 4500 : ($previous ? GamePlay::GAP_HIT : GamePlay::GAP_MISS) + 20) + ($options['jank'][$i] ?? 0);
            $think = $options['think'] ?? 0;
            $clock += $wait + $reaction + (($options['fake_clock'] ?? false) ? 0 : $think);
            $this->travelTo(now()->addMilliseconds($wait + $reaction + $think));
            $color = $correct ? $questions[$i][1] : ($questions[$i][1] + 1) % 4;
            $answers[] = [$color, $reaction];

            if ($options['live'] ?? true) {
                $lag = $options['lag'][$i] ?? 0;
                $this->travelTo(now()->addMilliseconds($lag));
                $tap = isset($options['taps']) ? array_combine(['x', 'y'], $options['taps']($i)) : [];
                $this->postJson(route('game.answer'), ['token' => $session->token, 'i' => $i, 'c' => $color, 'r' => $reaction, 'k' => $clock, 'p' => $options['input'] ?? 't', ...$tap])
                    ->assertNoContent();
                $this->travelTo(now()->subMilliseconds($lag));
            }

            $previous = $correct && $reaction <= GamePlay::timeLimit($i + 1);
        }

        return $answers;
    }

    /**
     * Bütün oyun: "Başla", canlı cevaplar, bitiş sahnesi, sondaki liste
     * (options.list ile değiştirilebilir).
     *
     * @param  list<bool>  $pattern
     * @param  int|list<int>  $ms
     * @param  array<string, mixed>  $options
     */
    private function playGame(GameSession $session, array $pattern, int|array $ms = 400, array $options = []): TestResponse
    {
        $answers = $this->playAnswers($session, $this->begin($session, $options['device'] ?? null, $options['trace'] ?? []), $pattern, $ms, $options);
        $this->travelTo(now()->addMilliseconds(1500));

        if (isset($options['list'])) {
            $answers = $options['list']($answers);
        }

        return $this->postJson(route('game.finish'), ['token' => $session->token, 'answers' => json_encode($answers)]);
    }

    public function test_ipad_api_requires_device_key_and_records_duration_score(): void
    {
        $escape = $this->game('Stant Oyunu: Kaçış', 'kacis', 'duration', 1000, 3);

        config(['services.stand_game.key' => '']);
        $this->postJson(route('game.api.player'), ['student_number' => '1'])->assertStatus(503);

        config(['services.stand_game.key' => 'stant-anahtari']);
        $this->postJson(route('game.api.player'), ['student_number' => '1'])->assertForbidden();

        $headers = ['X-Stand-Key' => 'stant-anahtari'];
        $this->postJson(route('game.api.player'), ['student_number' => '2311500209'], $headers)->assertOk()->assertJson(['found' => false]);
        $this->postJson(route('game.api.register'), ['student_number' => '2311500209', 'name' => 'Mert Can', 'phone' => '0555 999 88 77', 'email' => 'mert@example.com'], $headers)->assertOk();
        $this->postJson(route('game.api.player'), ['student_number' => '2311500209'], $headers)->assertJson(['found' => true, 'name' => 'Mert Can']);

        $token = $this->postJson(route('game.api.start', 'kacis'), ['student_number' => '2311500209'], $headers)->assertOk()->json('token');
        $this->travel(43)->seconds();
        $this->postJson(route('game.api.finish'), ['token' => $token, 'score' => 42315], $headers)->assertOk()->assertJson(['display' => '42,315 sn']);
        $this->postJson(route('game.api.finish'), ['token' => $token, 'score' => 50000], $headers)->assertStatus(422);
        $this->postJson(route('game.api.start', 'kacis'), ['student_number' => '2311500209'], $headers)->assertStatus(422);

        $this->assertSame(42315, app(GameBoard::class)->ranking($escape)->first()->best);
    }

    private function game(string $title, string $key, string $scoreType, int $points, int $winners): Campaign
    {
        return Campaign::create([
            'title' => $title,
            'description' => 'Stant oyunu',
            'is_active' => true,
            'campaign_type' => 'game',
            'winner_points' => $points,
            'winner_count' => $winners,
            'score_type' => $scoreType,
            'game_key' => $key,
            'prize' => 'İlk '.$winners.' kişiye ödül',
        ]);
    }
}
