<?php

namespace App\Http\Controllers;

use App\Services\Members\GamePlay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Standdaki iPad uygulamasının (Kaçış) kullandığı API. Uygulama her istekte
 * X-Stand-Key başlığıyla gelir; anahtar .env'deki STAND_GAME_KEY'dir.
 */
class GameApiController extends Controller
{
    public function __construct(private readonly GamePlay $games) {}

    /** Öğrenci numarasıyla oyuncuyu tanı ("Hoş geldin Elif"). */
    public function player(Request $request): JsonResponse
    {
        $this->authorizeDevice($request);
        $player = $this->games->findPlayer((string) $request->input('student_number'));

        return response()->json($player
            ? ['found' => true, 'name' => $player->name]
            : ['found' => false]);
    }

    public function register(Request $request): JsonResponse
    {
        $this->authorizeDevice($request);
        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', new \App\Rules\EmailTypo],
        ]);

        $player = $this->games->register($data);

        return response()->json(['name' => $player->name]);
    }

    public function start(Request $request, string $game): JsonResponse
    {
        $this->authorizeDevice($request);
        $campaign = $this->games->game($game);
        $player = $this->games->findPlayer((string) $request->input('student_number'));

        if (! $campaign) {
            return response()->json(['message' => 'Bu oyun şu an açık değil.'], 404);
        }

        if (! $player) {
            return response()->json(['message' => 'Önce kayıt ol.'], 404);
        }

        $session = $this->games->start($player, $campaign, $request->ip(), $request->userAgent());

        return response()->json(['token' => $session->token, 'started_at' => $session->started_at->toIso8601String()]);
    }

    public function finish(Request $request): JsonResponse
    {
        $this->authorizeDevice($request);
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'score' => ['required', 'integer', 'min:0'],
        ]);

        $session = $this->games->finish($data['token'], (int) $data['score']);

        return response()->json(['display' => $session->campaign->formatScore($session->score->score)]);
    }

    private function authorizeDevice(Request $request): void
    {
        $key = (string) config('services.stand_game.key');

        abort_if($key === '', 503, 'Oyun API anahtarı tanımlı değil.');
        abort_unless(hash_equals($key, (string) $request->header('X-Stand-Key')), 403);
    }
}
