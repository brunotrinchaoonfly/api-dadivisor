<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Gera combinações de datas para cada modalidade dentro da janela de flexibilidade.
 * Os arrays retornados são usados diretamente no payload do POST /bff/quote/create.
 *
 * Fórmula: (2 × flex_from + 1) × (2 × flex_to + 1) combinações.
 * Combinações inválidas (volta < ida) são descartadas automaticamente.
 */
class CombinationGeneratorService
{
    // -------------------------------------------------------------------------
    //  ✈️  Voos
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array{departure: string, from: string, return: string, to: string, travelers: int}>
     */
    public function forFlight(
        string $dateFrom,
        string $dateTo,
        string $origin,
        string $destination,
        int $flexFrom,
        int $flexTo,
        int $travelers
    ): array {
        $combinations = [];
        $baseFrom = Carbon::parse($dateFrom);
        $baseTo   = Carbon::parse($dateTo);

        for ($i = -$flexFrom; $i <= $flexFrom; $i++) {
            $departure = $baseFrom->copy()->addDays($i)->format('Y-m-d');

            for ($j = -$flexTo; $j <= $flexTo; $j++) {
                $return = $baseTo->copy()->addDays($j)->format('Y-m-d');

                if ($return < $departure) {
                    continue;
                }

                $combinations[] = [
                    'departure' => $departure,
                    'from'      => strtoupper($origin),
                    'return'    => $return,
                    'to'        => strtoupper($destination),
                    'travelers' => $travelers,
                ];
            }
        }

        return $combinations;
    }

    // -------------------------------------------------------------------------
    //  🏨  Hotéis
    // -------------------------------------------------------------------------

    /**
     * @param  string $cityId     ID da cidade (obtido via /search/hotel-cities)
     * @return array<int, array{cityId: string, checkIn: string, checkOut: string, rooms: int, guests: int}>
     */
    public function forHotel(
        string $checkIn,
        string $checkOut,
        string $cityId,
        int $flexFrom,
        int $flexTo,
        int $rooms = 1,
        int $guests = 1
    ): array {
        $combinations = [];
        $baseFrom = Carbon::parse($checkIn);
        $baseTo   = Carbon::parse($checkOut);

        for ($i = -$flexFrom; $i <= $flexFrom; $i++) {
            $ciDate = $baseFrom->copy()->addDays($i)->format('Y-m-d');

            for ($j = -$flexTo; $j <= $flexTo; $j++) {
                $coDate = $baseTo->copy()->addDays($j)->format('Y-m-d');

                // Check-out deve ser depois do check-in
                if ($coDate <= $ciDate) {
                    continue;
                }

                $combinations[] = [
                    'cityId'   => $cityId,
                    'checkIn'  => $ciDate,
                    'checkOut' => $coDate,
                    'rooms'    => $rooms,
                    'guests'   => $guests,
                ];
            }
        }

        return $combinations;
    }

    // -------------------------------------------------------------------------
    //  🚌  Ônibus
    // -------------------------------------------------------------------------

    /**
     * Flexibilidade aplicada apenas na data de partida (ida simples).
     *
     * @return array<int, array{from: string, to: string, departure: string}>
     */
    public function forBus(
        string $departureDate,
        string $origin,
        string $destination,
        int $flexFrom
    ): array {
        $combinations = [];
        $base = Carbon::parse($departureDate);

        for ($i = -$flexFrom; $i <= $flexFrom; $i++) {
            $combinations[] = [
                'from'      => strtoupper($origin),
                'to'        => strtoupper($destination),
                'departure' => $base->copy()->addDays($i)->format('Y-m-d'),
            ];
        }

        return $combinations;
    }

    // -------------------------------------------------------------------------
    //  🚗  Aluguel de Carro
    // -------------------------------------------------------------------------

    /**
     * @param  string $pickupLocation  Local de retirada (obtido via /search/car-cities)
     * @return array<int, array{pickupLocation: string, pickupDate: string, returnDate: string}>
     */
    public function forCar(
        string $pickupDate,
        string $returnDate,
        string $pickupLocation,
        int $flexFrom,
        int $flexTo
    ): array {
        $combinations = [];
        $baseFrom = Carbon::parse($pickupDate);
        $baseTo   = Carbon::parse($returnDate);

        for ($i = -$flexFrom; $i <= $flexFrom; $i++) {
            $pickup = $baseFrom->copy()->addDays($i)->format('Y-m-d');

            for ($j = -$flexTo; $j <= $flexTo; $j++) {
                $return = $baseTo->copy()->addDays($j)->format('Y-m-d');

                // Devolução deve ser depois da retirada
                if ($return <= $pickup) {
                    continue;
                }

                $combinations[] = [
                    'pickupLocation' => $pickupLocation,
                    'pickupDate'     => $pickup,
                    'returnDate'     => $return,
                ];
            }
        }

        return $combinations;
    }
}
