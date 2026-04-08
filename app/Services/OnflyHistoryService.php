<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Busca o histórico de reservas dos últimos 90 dias na API Onfly
 * e filtra pelo trecho/cidade solicitado.
 *
 * Endpoints por modalidade (ONFLY_API_BASE):
 *   aéreo  → GET /travel/order/fly-order
 *   hotel  → GET /travel/order/hotel-order
 *   ônibus → GET /travel/order/bus-order
 *   carro  → GET /travel/order/auto-order
 */
class OnflyHistoryService
{
    private string $baseUrl;

    private const ENDPOINTS = [
        'aereo'  => '/travel/order/fly-order',
        'hotel'  => '/travel/order/hotel-order',
        'onibus' => '/travel/order/bus-order',
        'carro'  => '/travel/order/auto-order',
    ];

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.onfly.base_url'), '/');
    }

    /**
     * Busca e filtra o histórico de reservas dos últimos 90 dias
     * de acordo com a modalidade e o trecho/cidade solicitado.
     *
     * @param  string $token       Bearer token do usuário Onfly
     * @param  string $modality    aereo | hotel | onibus | carro
     * @param  string $origin      IATA de origem ou cidade (aéreo/ônibus)
     * @param  string $destination IATA de destino ou cidade (hotel/carro)
     * @return array<int, array>   Registros filtrados (máx 90 dias)
     */
    public function fetch(string $token, string $modality, string $origin, string $destination): array
    {
        $raw = $this->get($token, self::ENDPOINTS[$modality] ?? '');

        if (empty($raw)) {
            return [];
        }

        return $this->filter($modality, $raw, $origin, $destination);
    }

    // -------------------------------------------------------------------------
    //  HTTP
    // -------------------------------------------------------------------------

    private function get(string $token, string $path): array
    {
        if (! $path) {
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get($this->baseUrl . $path);

            if ($response->failed()) {
                Log::warning('OnflyHistoryService: request failed', [
                    'path'   => $path,
                    'status' => $response->status(),
                ]);
                return [];
            }

            $data = $response->json();

            // Normaliza { data: [...] } ou array direto
            return is_array($data['data'] ?? null) ? $data['data'] : (array) $data;

        } catch (\Throwable $e) {
            Log::error('OnflyHistoryService: exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    //  Filtros por modalidade
    // -------------------------------------------------------------------------

    private function filter(string $modality, array $records, string $origin, string $destination): array
    {
        $cutoff = now()->subDays(90)->toDateString();

        return match ($modality) {
            'aereo'  => $this->filterAereo($records, $origin, $destination, $cutoff),
            'hotel'  => $this->filterHotel($records, $destination, $cutoff),
            'onibus' => $this->filterBus($records, $origin, $destination, $cutoff),
            'carro'  => $this->filterCar($records, $origin, $cutoff),
            default  => [],
        };
    }

    /**
     * Filtra reservas aéreas por código IATA origem/destino e data de criação.
     */
    private function filterAereo(array $records, string $origin, string $destination, string $cutoff): array
    {
        return array_values(array_filter($records, function (array $r) use ($origin, $destination, $cutoff) {
            $from = strtoupper($r['outbound']['from'] ?? '');
            $to   = strtoupper($r['outbound']['to'] ?? '');
            $date = substr($r['createdAt'] ?? '', 0, 10);

            return $from === strtoupper($origin)
                && $to   === strtoupper($destination)
                && $date >= $cutoff;
        }));
    }

    /**
     * Filtra reservas de hotel por cidade (match parcial, case-insensitive).
     */
    private function filterHotel(array $records, string $destination, string $cutoff): array
    {
        return array_values(array_filter($records, function (array $r) use ($destination, $cutoff) {
            $city = $r['city'] ?? '';
            $date = substr($r['checkin'] ?? $r['createdAt'] ?? '', 0, 10);

            return stripos($city, $destination) !== false && $date >= $cutoff;
        }));
    }

    /**
     * Filtra reservas de ônibus por código de terminal origem/destino.
     */
    private function filterBus(array $records, string $origin, string $destination, string $cutoff): array
    {
        return array_values(array_filter($records, function (array $r) use ($origin, $destination, $cutoff) {
            $from = strtoupper($r['outbound']['from'] ?? '');
            $to   = strtoupper($r['outbound']['to'] ?? '');
            $date = substr($r['outbound']['departureDate'] ?? $r['createdAt'] ?? '', 0, 10);

            return $from === strtoupper($origin)
                && $to   === strtoupper($destination)
                && $date >= $cutoff;
        }));
    }

    /**
     * Filtra reservas de carro por cidade de retirada (match parcial).
     */
    private function filterCar(array $records, string $city, string $cutoff): array
    {
        return array_values(array_filter($records, function (array $r) use ($city, $cutoff) {
            $recordCity = $r['city'] ?? '';
            $date       = substr($r['withdrawDate'] ?? $r['createdAt'] ?? '', 0, 10);

            return stripos($recordCity, $city) !== false && $date >= $cutoff;
        }));
    }
}
