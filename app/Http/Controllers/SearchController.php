<?php

namespace App\Http\Controllers;

use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    public function __construct(private readonly GatewayService $gateway) {}

    /**
     * Busca aeroportos por nome ou código IATA.
     * Modalidade: aéreo
     *
     * GET /api/search/airports?search=gru
     *
     * Gateway retorna: { data: [ { code, name, city: { name, countryCode, stateCode } } ] }
     * Frontend espera: [ { iata, name, city (string), country } ]
     */
    public function airports(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'required|string|min:2|max:100',
        ]);

        $raw = $this->cached(
            key: 'search_airports_' . strtolower($request->search),
            ttl: 60,
            fn: fn() => $this->gateway->searchAirports(
                $request->bearerToken(),
                $request->search
            )
        );

        $items = $raw['data'] ?? $raw;

        $normalized = array_map(fn(array $a) => [
            'iata'    => $a['code'] ?? '',
            'name'    => $a['name'] ?? '',
            'city'    => $a['city']['name'] ?? '',
            'country' => $a['city']['countryCode'] ?? '',
        ], array_values((array) $items));

        return response()->json($normalized);
    }

    /**
     * Busca cidades para hospedagem com autocomplete.
     * Modalidade: hotel
     *
     * GET /api/search/hotel-cities?search=salvador
     *
     * Gateway retorna: { data: { cities: [ { id, name, stateCode, countryCode } ] } }
     * Frontend espera: [ { id, name, state, country } ]
     */
    public function hotelCities(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'required|string|min:2|max:100',
        ]);

        $raw = $this->cached(
            key: 'search_hotel_cities_' . strtolower($request->search),
            ttl: 60,
            fn: fn() => $this->gateway->searchHotelCities(
                $request->bearerToken(),
                $request->search
            )
        );

        // Gateway pode retornar { data: { cities: [...] } } ou { data: [...] } ou [...]
        $items = $raw['data']['cities'] ?? $raw['data'] ?? $raw;

        $normalized = array_map(fn(array $c) => [
            'id'      => $c['id'] ?? '',
            'name'    => $c['name'] ?? '',
            'state'   => $c['stateCode'] ?? '',
            'country' => $c['countryCode'] ?? '',
        ], array_values((array) $items));

        return response()->json($normalized);
    }

    /**
     * Busca cidades para retirada de carro.
     * Modalidade: carro
     *
     * GET /api/search/car-cities?name=salvador
     *
     * Gateway retorna: { data: [ { id, name, stateCode, countryCode } ] }
     * Frontend espera: [ { id, name, state, country } ]
     */
    public function carCities(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|min:2|max:100',
        ]);

        $raw = $this->cached(
            key: 'search_car_cities_' . strtolower($request->name),
            ttl: 60,
            fn: fn() => $this->gateway->searchCarCities(
                $request->bearerToken(),
                $request->name
            )
        );

        $items = $raw['data'] ?? $raw;

        $normalized = array_map(fn(array $c) => [
            'id'      => $c['id'] ?? '',
            'name'    => $c['name'] ?? '',
            'state'   => $c['stateCode'] ?? '',
            'country' => $c['countryCode'] ?? '',
        ], array_values((array) $items));

        return response()->json($normalized);
    }

    /**
     * Busca terminais de ônibus por nome (origem ou destino).
     * Modalidade: ônibus
     *
     * GET /api/search/bus-destinations?search=salvador
     *
     * Gateway retorna: [ { id, displayName, type, city: { name, stateCode, countryCode } } ]
     * Frontend espera: [ { id, name, state, country } ]
     */
    public function busDestinations(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'required|string|min:2|max:100',
        ]);

        $raw = $this->cached(
            key: 'search_bus_' . strtolower($request->search),
            ttl: 60,
            fn: fn() => $this->gateway->searchBusDestinations(
                $request->bearerToken(),
                $request->search
            )
        );

        $items = $raw['data'] ?? $raw;

        $normalized = array_map(fn(array $b) => [
            'id'      => $b['id'] ?? '',
            'name'    => $b['city']['name'] ?? $b['name'] ?? $b['displayName'] ?? '',
            'state'   => $b['city']['stateCode'] ?? $b['stateCode'] ?? '',
            'country' => $b['city']['countryCode'] ?? $b['countryCode'] ?? '',
        ], array_values((array) $items));

        return response()->json($normalized);
    }

    /**
     * Cache helper com fallback para quando Redis está offline.
     * TTL em minutos. Dados de destino mudam pouco, 60 min é seguro.
     */
    private function cached(string $key, int $ttl, \Closure $fn): mixed
    {
        try {
            return Cache::remember($key, now()->addMinutes($ttl), $fn);
        } catch (\Throwable $e) {
            Log::warning('SearchController: cache unavailable, querying directly', [
                'key'     => $key,
                'message' => $e->getMessage(),
            ]);
            return $fn();
        }
    }
}
