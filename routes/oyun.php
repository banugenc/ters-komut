<?php

// BanüGENÇ panelindeki routes/web.php dosyasından oyun rotaları.

use App\Http\Controllers\GameApiController;
use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

// Stant oyunları: telefondan oynanan Ters Komut akışı ve iPad uygulamasının API'si.
Route::prefix('oyun')->name('game.')->controller(GameController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('/qr.{format}', 'qr')->where('format', 'png|svg')->name('qr');
    // Stantta oyuncular aynı ağdan (tek IP) gelir: her rotanın kendi sayacı
    // var (üçüncü değer), sınırlar ona göre geniş.
    Route::post('/kayit', 'register')->middleware('throttle:30,1,oyun-kayit')->name('register');
    Route::post('/cikis', 'leave')->name('leave');
    Route::get('/oyna', 'play')->name('play');
    Route::get('/misafir', 'guest')->name('guest');
    Route::post('/basla', 'begin')->middleware('throttle:60,1,oyun-basla')->name('begin');
    Route::post('/cevap', 'answer')->middleware('throttle:3000,1,oyun-cevap')->name('answer');
    Route::post('/bitir', 'finish')->middleware('throttle:60,1,oyun-bitir')->name('finish');
    Route::get('/sonuc/{token}', 'result')->where('token', '[A-Za-z0-9]+')->name('result');
    Route::get('/sonuc/{token}/sira', 'standing')->where('token', '[A-Za-z0-9]+')->middleware('throttle:120,1,oyun-sira')->name('standing');
    Route::post('/sonuc/{token}/uye-ol', 'join')->where('token', '[A-Za-z0-9]+')->middleware('throttle:30,1,oyun-uye')->name('join');
});
Route::prefix('oyun/api')->name('game.api.')->controller(GameApiController::class)
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
    ->middleware('throttle:120,1')
    ->group(function (): void {
        Route::post('/oyuncu', 'player')->name('player');
        Route::post('/kayit', 'register')->name('register');
        Route::post('/{game}/basla', 'start')->where('game', '[a-z-]+')->name('start');
        Route::post('/bitir', 'finish')->name('finish');
    });
