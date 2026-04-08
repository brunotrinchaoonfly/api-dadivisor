<?php

use App\Http\Controllers\AnalyzeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Smart Data Advisor
|--------------------------------------------------------------------------
|
| GET  /api/health                      → health check público
| POST /api/auth/login                  → autenticação via Onfly OAuth2
|
| POST /api/analyze                     → análise de datas (requer token)
|
| GET  /api/search/airports             → busca aeroportos        (aéreo)
| GET  /api/search/hotel-cities         → busca cidades           (hotel)
| GET  /api/search/car-cities           → busca cidades           (carro)
| GET  /api/search/bus-destinations     → busca terminais         (ônibus)
|
*/

Route::get('/health', [AnalyzeController::class, 'health']);

// ── Debug (remover antes do deploy) ──────────────────────────────────────────
Route::get('/debug/quote', function () {
    $token   = env('TOKEN_DEV');
    $gateway = rtrim(env('ONFLY_GATEWAY_URL'), '/');

    $payload = [
        'owners'       => [null],
        'flights'      => [[
            'departure' => '2026-05-05',
            'from'      => 'CNF',
            'return'    => '2026-05-10',
            'to'        => 'GRU',
            'travelers' => 1,
        ]],
        'groupFlights' => true,
    ];

    $response = Http::withHeaders([
        'accept'        => 'application/json',
        'authorization' => "Bearer {$token}",
        'content-type'  => 'application/json',
        'origin'        => 'https://onfly-dev.viagens.dev',
        'referer'       => 'https://onfly-dev.viagens.dev/',
    ])->timeout(30)->post("{$gateway}/bff/quote/create", $payload);

    return response()->json([
        'status'    => $response->status(),
        'ok'        => $response->ok(),
        'body'      => $response->json() ?? $response->body(),
        'payload_sent' => $payload,
    ]);
});
// ─────────────────────────────────────────────────────────────────────────────

Route::post('/auth/login', [AuthController::class, 'login']);

// Route::middleware('onfly.auth')->group(function () {
    Route::post('/analyze', [AnalyzeController::class, 'analyze']);

    Route::prefix('search')->group(function () {
        Route::get('/airports',          [SearchController::class, 'airports']);
        Route::get('/hotel-cities',      [SearchController::class, 'hotelCities']);
        Route::get('/car-cities',        [SearchController::class, 'carCities']);
        Route::get('/bus-destinations',  [SearchController::class, 'busDestinations']);
    });
// });
