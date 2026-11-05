<?php

namespace App\Services\Members;

use App\Models\Campaign;
use App\Models\GameScore;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Stant oyunlarının sıralaması ve gün sonu kazananları. Kural yarışma
 * planındaki gibi: büyük ödüllü oyunun kazananları önce belirlenir, sonraki
 * oyunda daha önce ödül almış kişi atlanır (kişi başı tek ödül).
 */
class GameBoard
{
    public function __construct(private readonly PointService $points) {}

    public function recordScore(User $user, Campaign $game, int $score, ?User $by = null, ?int $tiebreak = null): GameScore
    {
        return GameScore::create([
            'campaign_id' => $game->getKey(),
            'user_id' => $user->getKey(),
            'score' => $score,
            'tiebreak' => $tiebreak,
            'created_by' => $by?->getKey(),
        ]);
    }

    /**
     * Kişi başına en iyi geçerli skor; eşitlikte toplam tepki süresi kısa
     * olan (Ters Komut), o da yoksa önce yapan üstte.
     *
     * @return Collection<int, object{user_id: int, best: int, tiebreak: ?int, name: string}>
     */
    public function ranking(Campaign $game, ?int $limit = null): Collection
    {
        $rows = GameScore::query()
            ->valid()
            ->where('campaign_id', $game->getKey())
            ->selectRaw('user_id, MAX(score) as best, MIN(tiebreak) as best_tiebreak, MIN(id) as first_id')
            ->groupBy('user_id')
            ->orderByDesc('best')
            ->orderByRaw('MIN(tiebreak) IS NULL')
            ->orderByRaw('MIN(tiebreak)')
            ->orderBy('first_id')
            ->when($limit, fn ($query) => $query->limit($limit))
            ->get();

        $names = User::query()->whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');

        return $rows->map(fn (GameScore $row) => (object) [
            'user_id' => (int) $row->user_id,
            'best' => (int) $row->best,
            'tiebreak' => $row->best_tiebreak !== null ? (int) $row->best_tiebreak : null,
            'name' => self::shortName((string) ($names[$row->user_id] ?? '')),
        ])->values();
    }

    /**
     * Her oyun için ödül alan/alacak kişiler: daha önce işlenmiş kazananlar
     * ve sıralamaya göre boş yerlere düşenler.
     *
     * @return Collection<int, array{game: Campaign, awarded: array<int, int>, picks: Collection}>
     */
    public function plan(): Collection
    {
        $games = Campaign::query()->games()->get();
        $wins = PointTransaction::query()
            ->where('type', PointTransaction::TYPE_CAMPAIGN_WIN)
            ->where('source_type', (new Campaign)->getMorphClass())
            ->whereIn('source_id', $games->modelKeys())
            ->get(['user_id', 'source_id']);
        $taken = $wins->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return $games->map(function (Campaign $game) use ($wins, &$taken): array {
            $awarded = $wins->where('source_id', $game->getKey())->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
            $slots = max(0, (int) $game->winner_count - count($awarded));
            $picks = collect();

            foreach ($this->ranking($game) as $row) {
                if ($slots <= 0) {
                    break;
                }

                if (in_array($row->user_id, $taken, true)) {
                    continue;
                }

                $picks->push($row);
                $taken[] = $row->user_id;
                $slots--;
            }

            return ['game' => $game, 'awarded' => $awarded, 'picks' => $picks];
        });
    }

    /**
     * Planı uygular: seçilenlere kazanan puanı yüklenir.
     *
     * @return array<string, array<int, string>> oyun başlığı => kazanan adları
     */
    public function decide(?User $by = null): array
    {
        $result = [];

        foreach ($this->plan() as $entry) {
            foreach ($entry['picks'] as $row) {
                $user = User::query()->find($row->user_id);

                try {
                    if ($user) {
                        $this->points->awardCampaignWin($user, $entry['game'], $by);
                        $result[$entry['game']->title][] = $row->name;
                    }
                } catch (ValidationException) {
                    // Arada elle işlenmiş olabilir; kural servis içinde yeniden denetlenir.
                }
            }
        }

        return $result;
    }

    /**
     * Stant ekranı: oyun başına ilk N ve ödül sırasına girenler.
     *
     * Ödülünü başka oyundan alan kişi sırasında kalır ama ayrıca belirtilir.
     *
     * @return Collection<int, array{game: Campaign, rows: Collection, players: int, prizeUserIds: array<int, int>, elsewhereUserIds: array<int, int>}>
     */
    public function standings(int $limit = 10): Collection
    {
        $plan = $this->plan()->map(fn (array $entry): array => $entry + [
            'prizeUserIds' => array_merge($entry['awarded'], $entry['picks']->pluck('user_id')->all()),
        ]);

        return $plan->map(fn (array $entry): array => [
            'game' => $entry['game'],
            'rows' => $this->ranking($entry['game'], $limit),
            'players' => GameScore::query()->valid()->where('campaign_id', $entry['game']->getKey())->distinct()->count('user_id'),
            'prizeUserIds' => $entry['prizeUserIds'],
            'elsewhereUserIds' => $plan
                ->reject(fn (array $other) => $other['game']->is($entry['game']))
                ->flatMap(fn (array $other) => $other['prizeUserIds'])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Skor tablosu ekranı için düz veri: oyunlar, sıralamalar ve son skorlar.
     *
     * $only verilirse ekranda yalnız o oyun (game_key) durur; ödül planı yine bütün oyunlara göre kurulur.
     *
     * @return array{games: array<int, array<string, mixed>>, latest: array<int, array<string, string>>, updatedAt: string}
     */
    public function screenPayload(int $limit = 7, ?string $only = null): array
    {
        $standings = $this->standings($limit)
            ->when($only !== null, fn (Collection $entries) => $entries->filter(fn (array $entry): bool => $entry['game']->game_key === $only));

        $games = $standings->map(function (array $entry): array {
            $game = $entry['game'];

            return [
                'id' => $game->getKey(),
                'title' => str_contains($game->title, ': ') ? \Illuminate\Support\Str::after($game->title, ': ') : $game->title,
                'mode' => $game->score_type === 'duration' ? 'iPad · standda' : 'Telefon · QR ile',
                'prize' => (string) $game->prize,
                'rule' => $game->score_type === 'duration' ? '' : 'Eşit skorda hızlı olan önde',
                'winners' => (int) $game->winner_count,
                'players' => $entry['players'],
                'rows' => $entry['rows']->map(fn (object $row, int $index) => [
                    'key' => $row->user_id,
                    'rank' => $index + 1,
                    'name' => $row->name,
                    'score' => $row->best,
                    'display' => $game->formatScore($row->best),
                    // Eşitliği bozan toplam tepki süresi (Ters Komut): ekranda puanın yanında.
                    'time' => $row->tiebreak !== null && $game->score_type !== 'duration' ? number_format($row->tiebreak / 1000, 2, ',', '').' sn' : null,
                    'prize' => in_array($row->user_id, $entry['prizeUserIds'], true),
                    'elsewhere' => ! in_array($row->user_id, $entry['prizeUserIds'], true) && in_array($row->user_id, $entry['elsewhereUserIds'], true),
                ])->values()->all(),
            ];
        })->values()->all();

        $latest = GameScore::query()
            ->valid()
            ->whereIn('campaign_id', collect($games)->pluck('id'))
            ->with(['user:id,name', 'campaign'])
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (GameScore $score) => [
                'name' => self::shortName((string) $score->user?->name),
                'game' => str_contains((string) $score->campaign?->title, ': ') ? \Illuminate\Support\Str::after($score->campaign->title, ': ') : (string) $score->campaign?->title,
                'display' => $score->campaign?->formatScore($score->score) ?? (string) $score->score,
            ])
            ->all();

        return ['games' => $games, 'latest' => $latest, 'updatedAt' => now()->format('H:i:s')];
    }

    /**
     * Oyuncunun sıralamadaki yeri (sonuç ekranı için): sırası, toplam oyuncu,
     * ilk N, ödül sınırı (ilk 3'ün sonuncusunun skoru) ve seviye etiketi.
     * İncelemedeki (işaretli) skor sıralamada olmadığından sırası boş döner.
     *
     * @return array{rank: int|null, total: int, winners: int, cut: int|null, tier: string|null, top: Collection, me: object|null}
     */
    public function standing(Campaign $game, User $user, int $top = 5): array
    {
        $ranking = $this->ranking($game)->values()->map(fn (object $row, int $i) => (object) [...(array) $row, 'rank' => $i + 1]);
        $me = $ranking->first(fn (object $row) => $row->user_id === (int) $user->getKey());
        $winners = max(1, (int) $game->winner_count);

        return [
            'rank' => $me?->rank,
            'total' => $ranking->count(),
            'winners' => $winners,
            'cut' => $ranking->get($winners - 1)?->best,
            'tier' => $me ? self::tier($me->rank, $ranking->count(), $winners) : null,
            'top' => $ranking->take($top)->values(),
            'me' => $me,
        ];
    }

    /** Sıraya göre seviye: ilk 3 ödül bölgesi, sonra yüzdelik dilimler. */
    public static function tier(int $rank, int $total, int $winners = 3): string
    {
        if ($rank <= $winners) {
            return 'Ödül bölgesi';
        }

        $share = $rank / max($total, 1);

        return match (true) {
            $share <= 0.1 => 'Efsane',
            $share <= 0.25 => 'Usta',
            $share <= 0.5 => 'Keskin göz',
            default => 'Çaylak',
        };
    }

    public static function shortName(string $name): string
    {
        $parts = str($name)->squish()->explode(' ')->filter()->values();

        return $parts->count() > 1
            ? $parts->first().' '.mb_substr((string) $parts->last(), 0, 1).'.'
            : (string) $parts->first();
    }
}
