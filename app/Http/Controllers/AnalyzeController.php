<?php

namespace App\Http\Controllers;

use App\Services\ClaudeService;
use App\Services\CombinationGeneratorService;
use App\Services\GatewayService;
use App\Services\OnflyHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AnalyzeController extends Controller
{
    public function __construct(
        private readonly ClaudeService $claude,
        private readonly OnflyHistoryService $history,
        private readonly GatewayService $gateway,
        private readonly CombinationGeneratorService $combinations,
    ) {}

    /**
     * Analisa histórico de reservas e retorna as 4 melhores combinações de datas.
     *
     * Lógica:
     *  1. Valida o request
     *  2. Verifica cache Redis (TTL 30 min)
     *  3. Chama ClaudeService::analyze()
     *  4. Persiste no cache e retorna
     */
    public function analyze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'modality'          => 'required|in:aereo,hotel,carro,onibus',
            'origin'            => 'required|string',
            'destination'       => 'required|string',
            'date_from'         => 'required|date',
            'date_to'           => 'required|date',
            'flexibility_from'  => 'required|integer|min:0|max:7',
            'flexibility_to'    => 'required|integer|min:0|max:7',
            'travelers'         => 'required|integer|min:1',
            'historical_data'   => 'nullable|array',   // opcional — buscamos na API se omitido
            'historical_data.*' => 'array',
        ]);

        $cacheKey = 'analyze_' . md5(json_encode([
            $validated['modality'],
            $validated['origin'],
            $validated['destination'],
            $validated['date_from'],
            $validated['date_to'],
            $validated['flexibility_from'],
            $validated['flexibility_to'],
        ]));

        // if ($cached = Cache::get($cacheKey)) {
        //     Log::info('AnalyzeController: cache hit', ['key' => $cacheKey]);
        //     return response()->json($cached);
        // }

        // Cotações reais via /bff/quote/create para todas as modalidades.
        // historical_data do body é aceito como override (testes / fallback manual).
        // if (! empty($validated['historical_data'])) {
        //     $priceData = $validated['historical_data'];
        //     $validated['data_source'] = 'history';
        // } else {
            $token = $request->bearerToken() ?? config('services.onfly.token_dev', '');
        $priceData = $this->fetchQuotes($token, $validated);
            $validated['data_source'] = 'quote';
        // }
        $result = $this->claude->analyze($validated, $priceData);

        $result['combinations_analyzed'] = $this->countCombinations(
            (int) $validated['flexibility_from'],
            (int) $validated['flexibility_to']
        );

        Cache::put($cacheKey, $result, now()->addMinutes(30));

        return response()->json($result);
    }

    /**
     * Health check simples — usado pelo Docker e monitoramento.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status'    => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Gera combinações de datas e busca cotações reais via /bff/quote/create.
     * Suporta todas as modalidades: aereo, hotel, onibus, carro.
     * Cache de 15 min — preços mudam pouco nessa janela.
     */
    private function fetchQuotes(?string $token, array $params): array
    {


        [$items, $quoteFn] = match ($params['modality']) {

            'aereo' => [
                $this->combinations->forFlight(
                    dateFrom:    $params['date_from'],
                    dateTo:      $params['date_to'],
                    origin:      $params['origin'],
                    destination: $params['destination'],
                    flexFrom:    (int) $params['flexibility_from'],
                    flexTo:      (int) $params['flexibility_to'],
                    travelers:   (int) $params['travelers'],
                ),
                fn (array $i) => $this->gateway->quoteFlights($token, $i),
            ],

            'hotel' => [
                $this->combinations->forHotel(
                    checkIn:  $params['date_from'],
                    checkOut: $params['date_to'],
                    cityId:   $params['destination'],   // frontend envia o city_id em destination
                    flexFrom: (int) $params['flexibility_from'],
                    flexTo:   (int) $params['flexibility_to'],
                    rooms:    1,
                    guests:   (int) $params['travelers'],
                ),
                fn (array $i) => $this->gateway->quoteHotels($token, $i),
            ],

            'onibus' => [
                $this->combinations->forBus(
                    departureDate: $params['date_from'],
                    origin:        $params['origin'],
                    destination:   $params['destination'],
                    flexFrom:      (int) $params['flexibility_from'],
                ),
                fn (array $i) => $this->gateway->quoteBuses($token, $i),
            ],

            'carro' => [
                $this->combinations->forCar(
                    pickupDate:     $params['date_from'],
                    returnDate:     $params['date_to'],
                    pickupLocation: $params['origin'],   // frontend envia o location em origin
                    flexFrom:       (int) $params['flexibility_from'],
                    flexTo:         (int) $params['flexibility_to'],
                ),
                fn (array $i) => $this->gateway->quoteCars($token, $i),
            ],
        };

        $raw = $quoteFn($items);

        Log::debug('fetchQuotes: gateway response', [
            'modality' => $params['modality'],
            'items_sent' => count($items),
            'items_returned' => is_array($raw) ? count($raw) : 0,
        ]);

        return $this->normalizeQuotes($raw, $params['modality']);
    }

    /**
     * Busca histórico de reservas na API Onfly com cache de 10 min.
     * Evita múltiplas chamadas para o mesmo trecho dentro da janela de rate limit
     * (Onfly: 200 req / 30 min).
     */
    private function fetchHistory(string $token, string $modality, string $origin, string $destination): array
    {
        $key = 'history_' . md5("{$modality}|{$origin}|{$destination}");

        try {
            return Cache::remember(
                $key,
                now()->addMinutes(10),
                fn () => $this->history->fetch($token, $modality, $origin, $destination)
            ) ?? [];
        } catch (\Throwable $e) {
            Log::warning('AnalyzeController: history cache unavailable, querying directly', [
                'message' => $e->getMessage(),
            ]);
            return $this->history->fetch($token, $modality, $origin, $destination);
        }
    }

    /**
     * Normaliza a resposta bruta do /bff/quote/create em um array simples
     * que o Claude consegue interpretar sem ambiguidade.
     *
     * Converte centavos → reais e extrai apenas os campos relevantes.
     * Estrutura de entrada (flights):
     *   [ { item: { departure, return, from, to }, response: { data: [{ cheapestPrice, cheapestTotalPrice }] } } ]
     */
    private function normalizeQuotes(array $raw, string $modality): array
    {
        if (empty($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(function (array $quote) use ($modality) {
            $item     = $quote['item']     ?? [];
            $data     = $quote['response']['data'][0] ?? [];

            if (empty($data) || ($data['status'] ?? '') !== 'Available') {
                return null;
            }

            $cheapest = (int) ($data['cheapestPrice']      ?? 0);
            $total    = (int) ($data['cheapestTotalPrice'] ?? 0);

            return match ($modality) {
                'aereo' => [
                    'departure'       => $item['departure'] ?? null,
                    'return'          => $item['return']    ?? null,
                    'from'            => $item['from']      ?? null,
                    'to'              => $item['to']        ?? null,
                    'price_brl'       => round($cheapest / 100, 2),
                    'total_price_brl' => round($total    / 100, 2),
                ],
                'hotel' => [
                    'check_in'        => $item['checkIn']  ?? $item['departure'] ?? null,
                    'check_out'       => $item['checkOut'] ?? $item['return']    ?? null,
                    'city'            => $item['cityId']   ?? $item['to']        ?? null,
                    'price_brl'       => round($cheapest / 100, 2),
                    'total_price_brl' => round($total    / 100, 2),
                ],
                'onibus' => [
                    'departure'       => $item['departure'] ?? null,
                    'from'            => $item['from']      ?? null,
                    'to'              => $item['to']        ?? null,
                    'price_brl'       => round($cheapest / 100, 2),
                    'total_price_brl' => round($total    / 100, 2),
                ],
                'carro' => [
                    'pickup_date'     => $item['pickupDate']    ?? $item['departure'] ?? null,
                    'return_date'     => $item['returnDate']    ?? $item['return']    ?? null,
                    'location'        => $item['pickupLocation'] ?? $item['from']     ?? null,
                    'price_brl'       => round($cheapest / 100, 2),
                    'total_price_brl' => round($total    / 100, 2),
                ],
                default => null,
            };
        }, $raw)));
    }

    /**
     * Calcula o número total de combinações possíveis dada a flexibilidade.
     * Fórmula: (2 * flex_from + 1) * (2 * flex_to + 1)
     */
    private function countCombinations(int $flex_from, int $flex_to): int
    {
        return (2 * $flex_from + 1) * (2 * $flex_to + 1);
    }
}
