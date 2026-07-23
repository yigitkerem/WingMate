<?php

namespace App\Actions;

use App\Models\Availability;
use App\Models\Flight;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SearchFlights
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(int $originAirportId, int $destinationAirportId, string $date, string $mode, int $seatPassengers, string $tripType): array
    {
        $fareType = $tripType === 'round_trip' ? 'round_trip' : 'one_way';

        $flights = Flight::query()
            ->select([
                'id',
                'date',
                'hour',
                'origin_airport_id',
                'destination_airport_id',
                'flight_number',
                'plane_model',
            ])
            ->forRouteOnDate($originAirportId, $destinationAirportId, $date)
            ->with([
                'originAirport:id,name,code',
                'destinationAirport:id,name,code',
                'availabilities' => fn ($query) => $query
                    ->select([
                        'id',
                        'flight_id',
                        'class',
                        'checked_baggage_kg',
                        'cabin_baggage_kg',
                        'change_fee_usd',
                        'refund_fee_usd',
                        'latest_refund_hours',
                        'latest_change_hours',
                        'class_letters',
                        'fare_type',
                        'base_price_usd',
                        'count_available',
                    ])
                    ->where('fare_type', $fareType)
                    ->orderBy('base_price_usd'),
            ])
            ->orderBy('hour')
            ->get();

        return $flights
            ->map(fn (Flight $flight): array => $this->formatFlight($flight, $mode, $seatPassengers, $fareType))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatFlight(Flight $flight, string $mode, int $seatPassengers, string $fareType): array
    {
        return [
            'id' => $flight->id,
            'flight_number' => $flight->flight_number,
            'plane_model' => $flight->plane_model,
            'date' => $flight->date->toDateString(),
            'hour' => substr($flight->hour, 0, 5),
            'origin' => [
                'id' => $flight->originAirport->id,
                'name' => $flight->originAirport->name,
                'code' => $flight->originAirport->code,
            ],
            'destination' => [
                'id' => $flight->destinationAirport->id,
                'name' => $flight->destinationAirport->name,
                'code' => $flight->destinationAirport->code,
            ],
            'fare_type' => $fareType,
            'fares' => $mode === 'basic'
                ? $this->formatBasicFares($flight->availabilities, $seatPassengers)
                : $this->formatFullFares($flight->availabilities, $seatPassengers),
        ];
    }

    /**
     * @param  EloquentCollection<int, Availability>  $availabilities
     * @return array<string, array<string, mixed>>
     */
    private function formatBasicFares(EloquentCollection $availabilities, int $seatPassengers): array
    {
        return collect(['A', 'B', 'C'])
            ->mapWithKeys(function (string $classLetter) use ($availabilities, $seatPassengers): array {
                $availability = $availabilities
                    ->filter(fn (Availability $availability): bool => str_contains($availability->class_letters, $classLetter))
                    ->filter(fn (Availability $availability): bool => $availability->count_available >= $seatPassengers)
                    ->sortBy('base_price_usd')
                    ->first();

                return [
                    $classLetter => $availability instanceof Availability
                        ? $this->formatAvailability($availability, $seatPassengers)
                        : ['available' => false],
                ];
            })
            ->all();
    }

    /**
     * @param  EloquentCollection<int, Availability>  $availabilities
     * @return array<int, array<string, mixed>>
     */
    private function formatFullFares(EloquentCollection $availabilities, int $seatPassengers): array
    {
        return $availabilities
            ->map(fn (Availability $availability): array => $this->formatAvailability($availability, $seatPassengers))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAvailability(Availability $availability, int $seatPassengers): array
    {
        return [
            'id' => $availability->id,
            'class' => $availability->class,
            'class_letters' => $availability->class_letters,
            'fare_type' => $availability->fare_type,
            'checked_baggage_kg' => $availability->checked_baggage_kg,
            'cabin_baggage_kg' => $availability->cabin_baggage_kg,
            'change_fee_usd' => $availability->change_fee_usd,
            'refund_fee_usd' => $availability->refund_fee_usd,
            'latest_refund_hours' => $availability->latest_refund_hours,
            'latest_change_hours' => $availability->latest_change_hours,
            'base_price_usd' => $availability->base_price_usd,
            'count_available' => $availability->count_available,
            'available' => $availability->count_available >= $seatPassengers,
        ];
    }
}
