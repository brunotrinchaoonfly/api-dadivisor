<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Centraliza todas as chamadas HTTP ao gateway de viagens.
 *
 * Base URL: ONFLY_GATEWAY_URL (ex: https://gateway.viagens.dev)
 * Autenticação: Bearer token do usuário Onfly, repassado a cada chamada.
 */
class GatewayService
{
    private string $baseUrl;

    private array $defaultHeaders = [
        'accept'  => 'application/json',
        'origin'  => 'https://onfly-dev.viagens.dev',
        'referer' => 'https://onfly-dev.viagens.dev/',
    ];

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.onfly.gateway_url'), '/');
    }

    // -------------------------------------------------------------------------
    // Busca de destinos por modalidade
    // -------------------------------------------------------------------------

    public function searchAirports(string $token, string $search): array
    {
        return $this->get($token, '/bff/destination/airports', [
            'lang'   => 'pt-br',
            'search' => $search,
        ]);
    }

    public function searchHotelCities(string $token, string $search): array
    {
        return $this->get($token, '/bff/destination/cities/autocomplete', [
            'lang'   => 'pt-br',
            'search' => $search,
        ]);
    }

    public function searchCarCities(string $token, string $name): array
    {
        return $this->get($token, '/bff/destination/cities', ['name' => $name]);
    }

    public function searchBusDestinations(string $token, string $search): array
    {
        return $this->get($token, '/bff/destination/bus-destinations', ['search' => $search]);
    }

    // -------------------------------------------------------------------------
    // Cotação de preços — Http::pool() para requisições paralelas
    //
    // O gateway retorna timeout quando recebe múltiplas combinações em batch.
    // Solução: uma requisição por item, enviadas em paralelo via Http::pool().
    // -------------------------------------------------------------------------

    /**
     * ✈️ Cota voos — uma requisição por combinação, em paralelo.
     */
    public function quoteFlights(string $token, array $flights): array
    {
        return $this->quotePool($token, 'flights', $flights, [
            'owners'       => [null],
            'groupFlights' => true,
        ]);
    }

    /**
     * 🏨 Cota hotéis — uma requisição por combinação, em paralelo.
     */
    public function quoteHotels(string $token, array $hotels): array
    {
        return $this->quotePool($token, 'hotels', $hotels, ['owners' => [null]]);
    }

    /**
     * 🚌 Cota ônibus — uma requisição por data, em paralelo.
     */
    public function quoteBuses(string $token, array $buses): array
    {
        return $this->quotePool($token, 'buses', $buses, ['owners' => [null]]);
    }

    /**
     * 🚗 Cota carros — uma requisição por combinação, em paralelo.
     */
    public function quoteCars(string $token, array $cars): array
    {
        return $this->quotePool($token, 'cars', $cars, ['owners' => [null]]);
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Envia uma requisição por item via Http::pool() (paralelo).
     * Cada requisição contém exatamente UM item — evita timeout do gateway com batch.
     */
    private function quotePool(string $token, string $key, array $items, array $basePayload = []): array
    {
        if (empty($items)) {
            return [];
        }

        try {
            $url = $this->baseUrl . '/bff/quote/create';

            $responses = Http::pool(function ($pool) use ($token, $key, $items, $basePayload, $url) {
                return array_map(
                    fn (array $item) => $pool
                        ->withHeaders($this->headers($token))
                        ->timeout(20)
                        ->post($url, array_merge($basePayload, [$key => [$item]])),
                    $items
                );
            });

            $results = [];
            foreach ($responses as $i => $response) {
                if (! $response instanceof Response || $response->failed()) {
                    Log::debug('GatewayService: pool item ignorado', [
                        'index'  => $i,
                        'status' => $response instanceof Response ? $response->status() : 'exception',
                    ]);
                    continue;
                }

                $json = $response->json();
                if (is_array($json)) {
                    array_push($results, ...$json);
                }
            }

            return $results;

        } catch (\Throwable $e) {
            Log::error('GatewayService: quotePool exception', [
                'key'     => $key,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function get(string $token, string $path, array $query = []): array
    {
        try {
            $response = Http::withHeaders($this->headers($token))
                ->timeout(10)
                ->get($this->baseUrl . $path, $query);

            if ($response->failed()) {
                Log::warning('GatewayService: GET failed', [
                    'path'   => $path,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return [];
            }

            return $response->json() ?? [];

        } catch (\Throwable $e) {
            Log::error('GatewayService: GET exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function headers(string $token): array
    {
        return array_merge($this->defaultHeaders, [
            'authorization' => "Bearer {$token}",
        ]);
    }
}
