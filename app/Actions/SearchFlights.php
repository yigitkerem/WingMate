<?php

namespace App\Actions;

use App\Models\BaseFare;
use App\Models\Flight;
use App\Models\FlightInventory;
use App\Models\Offer;
use App\Models\User;
use App\Pricing\OfferBuilder;
use App\Support\ServiceValue;
use Illuminate\Support\Collection;

class SearchFlights
{
    public function __construct(private readonly OfferBuilder $offerBuilder) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(int $originAirportId, int $destinationAirportId, string $date, string $mode, int $seatPassengers, string $tripType, int $adults = 1, int $children = 0, int $infants = 0, ?User $user = null, int $legIndex = 1): array
    {
        $flights = Flight::query()
            ->select(['id', 'flight_number', 'origin_airport_id', 'destination_airport_id', 'departure_at', 'arrival_at', 'duration_minutes', 'aircraft_type', 'status'])
            ->forRouteOnDate($originAirportId, $destinationAirportId, $date)
            ->with([
                'originAirport:id,name,iata_code',
                'destinationAirport:id,name,iata_code',
                'baseFares' => fn ($query) => $query
                    ->current()
                    ->where('trip_type', $tripType === 'round_trip' ? 'round_trip' : 'one_way')
                    ->where('leg_index', $tripType === 'round_trip' ? $legIndex : 1)
                    ->with(['bundle.product', 'bookingClass.cabin'])
                    ->orderBy('base_price'),
            ])
            ->orderBy('departure_at')
            ->get();

        return $flights
            ->map(fn (Flight $flight): array => $this->formatFlight($flight, $mode, $seatPassengers, $adults, $children, $infants, $user))
            ->filter(fn (array $flight): bool => count($flight['fares']) > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatFlight(Flight $flight, string $mode, int $seatPassengers, int $adults, int $children, int $infants, ?User $user): array
    {
        $fares = $flight->baseFares
            ->filter(fn (BaseFare $baseFare): bool => $this->hasSeats($baseFare, $seatPassengers))
            ->when($mode === 'basic', fn (Collection $fares): Collection => $fares
                ->filter(fn (BaseFare $baseFare): bool => (bool) $baseFare->bundle->public)
                ->sortBy('bundle.display_order')
                ->take(5))
            ->map(fn (BaseFare $baseFare): array => $this->formatOffer(
                $this->offerBuilder->build($baseFare, $adults, $children, $infants, $user),
                $seatPassengers,
            ))
            ->values()
            ->all();

        $firstFare = $flight->baseFares->first();

        return [
            'id' => $flight->id,
            'flight_number' => $flight->flight_number,
            'plane_model' => $flight->aircraft_type,
            'date' => $flight->departure_at->toDateString(),
            'hour' => $flight->departure_at->format('H:i'),
            'duration_minutes' => $flight->duration_minutes,
            'duration' => Flight::formatDuration($flight->duration_minutes),
            'arrival_time' => $flight->arrival_at->format('H:i'),
            'origin' => [
                'id' => $flight->originAirport->id,
                'name' => $flight->originAirport->name,
                'code' => $flight->originAirport->iata_code,
            ],
            'destination' => [
                'id' => $flight->destinationAirport->id,
                'name' => $flight->destinationAirport->name,
                'code' => $flight->destinationAirport->iata_code,
            ],
            'fare_type' => $firstFare instanceof BaseFare ? $firstFare->trip_type : 'one_way',
            'fares' => $fares,
        ];
    }

    private function hasSeats(BaseFare $baseFare, int $seatPassengers): bool
    {
        return FlightInventory::query()
            ->where('flight_id', $baseFare->flight_id)
            ->where('booking_class_id', $baseFare->booking_class_id)
            ->where('available', '>=', $seatPassengers)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOffer(Offer $offer, int $seatPassengers): array
    {
        $services = $offer->offerServices
            ->map(fn ($offerService): array => [
                'code' => $offerService->service->code,
                'name' => $offerService->service->name,
                'category' => $offerService->service->category,
                'value' => $offerService->value,
                'quantity' => $offerService->quantity,
                'included' => $offerService->included,
                'price' => (float) $offerService->price,
                'source' => $offerService->source,
            ])
            ->values();
        $serviceValue = function (string $code, mixed $default = null) use ($services): mixed {
            $service = $services->firstWhere('code', $code);

            if (! is_array($service)) {
                return $default;
            }

            $value = $service['value'] ?? $default;

            return ServiceValue::amount($value, $default);
        };
        $changePolicy = $this->flexibilityPolicy($services, 'CHANGE_ALLOWED', 'CHANGE_FEE');
        $refundPolicy = $this->flexibilityPolicy($services, 'REFUNDABLE', 'REFUND_FEE');

        return [
            'id' => $offer->id,
            'uuid' => $offer->uuid,
            'base_fare_id' => $offer->base_fare_id,
            'class' => $offer->bundle->name,
            'package_code' => $offer->bundle->code,
            'product' => $offer->bundle->product->name,
            'public' => (bool) $offer->bundle->public,
            'class_letters' => $offer->class_letters,
            'fare_basis_code' => $offer->fare_basis_code,
            'fare_type' => $offer->trip_type,
            'leg_index' => $offer->leg_index,
            'base_price_usd' => (float) $offer->total_price,
            'per_passenger_price_usd' => $seatPassengers > 0 ? round((float) $offer->total_price / $seatPassengers, 2) : (float) $offer->total_price,
            'checked_baggage_kg' => (int) $serviceValue('CHECKED_BAG', 0),
            'cabin_baggage_kg' => (int) $serviceValue('CABIN_BAG', 0),
            'seat_selection_free' => (bool) $serviceValue('SEAT_SELECTION', false),
            'change_fee_usd' => $this->fixedFee($changePolicy),
            'change_fee_percent' => $this->percentFee($changePolicy),
            'refund_fee_usd' => $this->fixedFee($refundPolicy),
            'refund_fee_percent' => $this->percentFee($refundPolicy),
            'latest_change_hours' => $changePolicy['window_hours'] ?? null,
            'latest_refund_hours' => $refundPolicy['window_hours'] ?? null,
            'change_rule' => $changePolicy,
            'refund_rule' => $refundPolicy,
            'count_available' => FlightInventory::query()
                ->where('flight_id', $offer->flight_id)
                ->where('booking_class_id', $offer->booking_class_id)
                ->value('available') ?? 0,
            'available' => true,
            'expires_at' => $offer->expires_at->toIso8601String(),
            'services' => $services->all(),
            'price_components' => $offer->priceComponents
                ->map(fn ($component): array => [
                    'code' => $component->code,
                    'label' => $component->label,
                    'type' => $component->type,
                    'amount' => (float) $component->amount,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $services
     * @return array{allowed: bool, window_hours: int, fee_type: string, fee_amount: float}|null
     */
    private function flexibilityPolicy(Collection $services, string $ruleCode, string $feeCode): ?array
    {
        $ruleService = $services->firstWhere('code', $ruleCode);

        if (! is_array($ruleService) || ! ServiceValue::isEnabled($ruleService['value'] ?? null)) {
            return null;
        }

        $feeService = $services->firstWhere('code', $feeCode);
        $feeValue = is_array($feeService) ? ($feeService['value'] ?? null) : null;
        $value = $ruleService['value'] ?? true;
        $windowHours = 24;
        $feeType = is_array($feeValue) ? (string) ($feeValue['fee_type'] ?? 'fixed') : 'fixed';
        $feeAmount = (float) ServiceValue::amount($feeValue, 0);

        if (is_array($value)) {
            $windowHours = (int) ($value['window_hours'] ?? ServiceValue::amount($value, 24));
            $feeType = (string) ($value['fee_type'] ?? $feeType);
            $feeAmount = (float) ($value['fee_amount'] ?? $feeAmount);
        } elseif (is_numeric($value)) {
            $windowHours = (int) $value;
        }

        return [
            'allowed' => true,
            'window_hours' => max(1, $windowHours),
            'fee_type' => $feeType === 'percent' ? 'percent' : 'fixed',
            'fee_amount' => $feeAmount,
        ];
    }

    /**
     * @param  array{fee_type: string, fee_amount: float}|null  $policy
     */
    private function fixedFee(?array $policy): ?int
    {
        if ($policy === null) {
            return 0;
        }

        return $policy['fee_type'] === 'fixed' ? (int) $policy['fee_amount'] : null;
    }

    /**
     * @param  array{fee_type: string, fee_amount: float}|null  $policy
     */
    private function percentFee(?array $policy): ?int
    {
        return $policy !== null && $policy['fee_type'] === 'percent' ? (int) $policy['fee_amount'] : null;
    }
}
