<?php

namespace App\Chatbot;

use App\Actions\PurchaseTickets;
use App\Actions\SearchFlights;
use App\Models\Airport;
use App\Models\BaseFare;
use App\Models\FlightInventory;
use App\Models\Offer;
use App\Models\Order;
use App\Pricing\OfferBuilder;
use App\Support\ServiceValue;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatbotToolbox
{
    public function __construct(
        private readonly SearchFlights $searchFlights,
        private readonly PurchaseTickets $purchaseTickets,
        private readonly KnowledgeBaseSearch $knowledgeBaseSearch,
        private readonly OfferBuilder $offerBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $toolInput
     * @param  array<string, mixed>|null  $pendingPurchase
     * @return array{result: array<string, mixed>, pending_purchase: array<string, mixed>|null}
     */
    public function dispatch(string $name, array $toolInput, ?array $pendingPurchase, bool $userConfirmed): array
    {
        $result = match ($name) {
            'list_airports' => $this->listAirports(),
            'search_flights' => $this->search($toolInput),
            'quote_purchase' => $this->quotePurchase($toolInput),
            'commit_purchase' => $this->commitPurchase($pendingPurchase, $userConfirmed),
            'search_knowledge_base' => $this->knowledgeBaseSearch->search((string) $toolInput['query'], (int) ($toolInput['k'] ?? 3)),
            'build_dynamic_bundles' => $this->buildDynamicBundles($toolInput),
            default => ['error' => 'unknown_tool', 'name' => $name],
        };

        if ($name === 'quote_purchase' && isset($result['pending_purchase']) && is_array($result['pending_purchase'])) {
            $pendingPurchase = $result['pending_purchase'];
            unset($result['pending_purchase']);
        }

        if ($name === 'commit_purchase' && ($result['success'] ?? false) === true) {
            $pendingPurchase = null;
        }

        return [
            'result' => $result,
            'pending_purchase' => $pendingPurchase,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listAirports(): array
    {
        return [
            'airports' => Airport::query()
                ->select(['id', 'iata_code', 'name'])
                ->orderBy('iata_code')
                ->get()
                ->map(fn (Airport $airport): array => [
                    'id' => $airport->id,
                    'code' => $airport->iata_code,
                    'name' => $airport->name,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $toolInput
     * @return array<string, mixed>
     */
    private function search(array $toolInput): array
    {
        return $this->searchLeg($toolInput, 1, 'full');
    }

    /**
     * @param  array<string, mixed>  $toolInput
     * @return array<string, mixed>
     */
    private function searchLeg(array $toolInput, int $legIndex, string $mode = 'full'): array
    {
        $origin = $this->airport((string) $toolInput['origin']);
        $destination = $this->airport((string) $toolInput['destination']);

        if (! $origin instanceof Airport || ! $destination instanceof Airport) {
            return [
                'error' => 'unknown_airport',
                'message' => 'Unknown airport code.',
                'known_airports' => Airport::query()->orderBy('iata_code')->pluck('iata_code')->all(),
            ];
        }

        $adults = (int) ($toolInput['adults'] ?? 1);
        $children = (int) ($toolInput['children'] ?? 0);
        $babies = (int) ($toolInput['babies'] ?? 0);
        $tripType = (string) ($toolInput['trip_type'] ?? 'one_way');
        $seatPassengers = $adults + $children;

        return [
            'origin' => $origin->iata_code,
            'destination' => $destination->iata_code,
            'date' => (string) $toolInput['date'],
            'trip_type' => $tripType === 'round_trip' ? 'round_trip' : 'one_way',
            'passengers' => ['adults' => $adults, 'children' => $children, 'babies' => $babies],
            'seat_passengers' => $seatPassengers,
            'flights' => $flights = $this->searchFlights->execute(
                $origin->id,
                $destination->id,
                (string) $toolInput['date'],
                $mode,
                $seatPassengers,
                $tripType,
                $adults,
                $children,
                $babies,
                null,
                $legIndex,
            ),
            'flight_count' => count($flights),
        ];
    }

    private function airport(string $code): ?Airport
    {
        return Airport::query()
            ->whereRaw('upper(iata_code) = ?', [strtoupper(trim($code))])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $toolInput
     * @return array<string, mixed>
     */
    private function quotePurchase(array $toolInput): array
    {
        $offer = Offer::query()
            ->with(['flight.originAirport', 'flight.destinationAirport', 'bundle', 'priceComponents'])
            ->find((int) $toolInput['offer_id']);

        if (! $offer instanceof Offer) {
            return ['error' => 'not_found', 'message' => 'Offer not found.'];
        }

        return [
            'status' => 'confirmation_required',
            'message' => 'This requires approval. Show the summary to the user and wait for explicit consent.',
            'offer_id' => $offer->id,
            'flight_number' => $offer->flight->flight_number,
            'route' => $offer->flight->originAirport->iata_code.'-'.$offer->flight->destinationAirport->iata_code,
            'date' => $offer->flight->departure_at->toDateString(),
            'package' => $offer->bundle->name,
            'total_usd' => (float) $offer->total_price,
            'price_components' => $offer->priceComponents->map(fn ($component): array => [
                'label' => $component->label,
                'amount' => (float) $component->amount,
            ])->all(),
            'pending_purchase' => [
                'offer_ids' => [(int) $toolInput['offer_id']],
                'first_name' => (string) $toolInput['first_name'],
                'last_name' => (string) $toolInput['last_name'],
                'email' => $toolInput['email'] ?? null,
                'passport_number' => $toolInput['passport_number'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $pendingPurchase
     * @return array<string, mixed>
     */
    private function commitPurchase(?array $pendingPurchase, bool $userConfirmed): array
    {
        if ($pendingPurchase === null) {
            return ['success' => false, 'error' => 'no_pending_quote', 'message' => 'A quote must be prepared first.'];
        }

        if (! $userConfirmed) {
            return ['success' => false, 'error' => 'confirmation_missing', 'message' => 'Cannot purchase without explicit user approval.'];
        }

        try {
            $order = $this->purchaseTickets->execute([
                'offer_ids' => array_map('intval', is_array($pendingPurchase['offer_ids'] ?? null) ? $pendingPurchase['offer_ids'] : []),
                'first_name' => (string) ($pendingPurchase['first_name'] ?? ''),
                'last_name' => (string) ($pendingPurchase['last_name'] ?? ''),
                'email' => isset($pendingPurchase['email']) ? (string) $pendingPurchase['email'] : null,
                'passport_number' => isset($pendingPurchase['passport_number']) ? (string) $pendingPurchase['passport_number'] : null,
            ]);
        } catch (ValidationException $exception) {
            return [
                'success' => false,
                'error' => 'purchase_validation_failed',
                'message' => collect($exception->errors())->flatten()->first() ?? 'Purchase could not be completed.',
            ];
        }

        return $this->orderSummary($order);
    }

    /**
     * @param  array<string, mixed>  $toolInput
     * @return array<string, mixed>
     */
    private function buildDynamicBundles(array $toolInput): array
    {
        $search = $this->searchLeg($toolInput, 1, 'full');
        $flights = collect(is_array($search['flights'] ?? null) ? $search['flights'] : []);
        $firstFlight = $flights->first();

        if (! is_array($firstFlight)) {
            return ['error' => 'no_flights', 'message' => 'No flights found for this route/date.'];
        }

        $preferredServices = collect(is_array($toolInput['service_codes'] ?? null) ? $toolInput['service_codes'] : [])
            ->filter(fn (mixed $serviceCode): bool => is_string($serviceCode))
            ->values()
            ->all();
        $excludedServices = collect(is_array($toolInput['excluded_service_codes'] ?? null) ? $toolInput['excluded_service_codes'] : [])
            ->filter(fn (mixed $serviceCode): bool => is_string($serviceCode))
            ->values()
            ->all();
        $serviceSpecs = collect(is_array($toolInput['service_specs'] ?? null) ? $toolInput['service_specs'] : [])
            ->filter(fn (mixed $serviceSpec): bool => is_array($serviceSpec) && is_string($serviceSpec['service_code'] ?? null))
            ->values()
            ->all();

        $picks = collect(is_array($firstFlight['fares'] ?? null) ? $firstFlight['fares'] : [])
            ->filter(fn (array $fare): bool => ($fare['available'] ?? false) === true)
            ->map(fn (array $fare): array => $this->customizeFareForDemand($fare, $toolInput, $preferredServices, $excludedServices, $serviceSpecs))
            ->sort(function (array $firstFare, array $secondFare) use ($preferredServices, $excludedServices): int {
                $firstExcludedCount = $this->includedExcludedServiceCount($firstFare, $excludedServices);
                $secondExcludedCount = $this->includedExcludedServiceCount($secondFare, $excludedServices);

                if ($firstExcludedCount !== $secondExcludedCount) {
                    return $firstExcludedCount <=> $secondExcludedCount;
                }

                $firstMissingCount = $this->missingPreferredServiceCount($firstFare, $preferredServices);
                $secondMissingCount = $this->missingPreferredServiceCount($secondFare, $preferredServices);

                if ($firstMissingCount !== $secondMissingCount) {
                    return $firstMissingCount <=> $secondMissingCount;
                }

                $firstIsCustomized = ($firstFare['customized'] ?? false) === true;
                $secondIsCustomized = ($secondFare['customized'] ?? false) === true;

                if ($firstIsCustomized !== $secondIsCustomized) {
                    return $firstIsCustomized <=> $secondIsCustomized;
                }

                return ((float) ($firstFare['base_price_usd'] ?? PHP_FLOAT_MAX))
                    <=> ((float) ($secondFare['base_price_usd'] ?? PHP_FLOAT_MAX));
            })
            ->map(fn (array $fare): array => [
                ...$fare,
                'offer_ids' => [(int) $fare['id']],
                'segments' => [$this->flightSegment($firstFlight, $search)],
            ])
            ->values();

        if (($search['trip_type'] ?? 'one_way') === 'round_trip' && isset($toolInput['return_date'])) {
            $returnSearch = $this->searchLeg([
                ...$toolInput,
                'origin' => (string) $toolInput['destination'],
                'destination' => (string) $toolInput['origin'],
                'date' => (string) $toolInput['return_date'],
            ], 2, 'full');
            $returnFlight = collect(is_array($returnSearch['flights'] ?? null) ? $returnSearch['flights'] : [])->first();

            if (! is_array($returnFlight)) {
                return ['error' => 'no_flights', 'message' => 'No return flights found for this route/date.'];
            }

            $returnPicks = collect(is_array($returnFlight['fares'] ?? null) ? $returnFlight['fares'] : [])
                ->filter(fn (array $fare): bool => ($fare['available'] ?? false) === true)
                ->map(fn (array $fare): array => $this->customizeFareForDemand($fare, [
                    ...$toolInput,
                    'date' => (string) $toolInput['return_date'],
                ], $preferredServices, $excludedServices, $serviceSpecs))
                ->sort(function (array $firstFare, array $secondFare) use ($preferredServices, $excludedServices): int {
                    $firstExcludedCount = $this->includedExcludedServiceCount($firstFare, $excludedServices);
                    $secondExcludedCount = $this->includedExcludedServiceCount($secondFare, $excludedServices);

                    if ($firstExcludedCount !== $secondExcludedCount) {
                        return $firstExcludedCount <=> $secondExcludedCount;
                    }

                    $firstMissingCount = $this->missingPreferredServiceCount($firstFare, $preferredServices);
                    $secondMissingCount = $this->missingPreferredServiceCount($secondFare, $preferredServices);

                    if ($firstMissingCount !== $secondMissingCount) {
                        return $firstMissingCount <=> $secondMissingCount;
                    }

                    $firstIsCustomized = ($firstFare['customized'] ?? false) === true;
                    $secondIsCustomized = ($secondFare['customized'] ?? false) === true;

                    if ($firstIsCustomized !== $secondIsCustomized) {
                        return $firstIsCustomized <=> $secondIsCustomized;
                    }

                    return ((float) ($firstFare['base_price_usd'] ?? PHP_FLOAT_MAX))
                        <=> ((float) ($secondFare['base_price_usd'] ?? PHP_FLOAT_MAX));
                });
            $picks = $picks
                ->map(function (array $outboundFare) use ($returnPicks, $returnFlight, $returnSearch): ?array {
                    $returnFare = $returnPicks->firstWhere('package_code', $outboundFare['package_code'])
                        ?? $returnPicks->first();

                    if (! is_array($returnFare)) {
                        return null;
                    }

                    return [
                        ...$outboundFare,
                        'id' => null,
                        'base_price_usd' => round((float) $outboundFare['base_price_usd'] + (float) $returnFare['base_price_usd'], 2),
                        'offer_ids' => [(int) $outboundFare['id'], (int) $returnFare['id']],
                        'segments' => [
                            ...$outboundFare['segments'],
                            $this->flightSegment($returnFlight, $returnSearch),
                        ],
                    ];
                })
                ->filter()
                ->values();
        }

        return [
            'flight' => [
                'flight_number' => $firstFlight['flight_number'],
                'date' => $firstFlight['date'],
                'hour' => $firstFlight['hour'],
                'arrival_hour' => $firstFlight['arrival_time'],
                'duration_str' => $firstFlight['duration'],
                'origin' => $search['origin'],
                'destination' => $search['destination'],
                'trip_type' => $search['trip_type'],
            ],
            'passengers' => $search['passengers'],
            'seat_passengers' => $search['seat_passengers'],
            'recommended_picks' => $picks->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $fare
     * @param  array<string, mixed>  $toolInput
     * @param  array<int, string>  $preferredServices
     * @param  array<int, string>  $excludedServices
     * @param  array<int, array<string, mixed>>  $serviceSpecs
     * @return array<string, mixed>
     */
    private function customizeFareForDemand(array $fare, array $toolInput, array $preferredServices, array $excludedServices, array $serviceSpecs): array
    {
        $selectedServices = $this->selectedServicesForDemand($fare, $preferredServices, $excludedServices, $serviceSpecs);

        if ($selectedServices === []) {
            return $fare;
        }

        $baseFare = BaseFare::query()
            ->with(['flight.originAirport', 'flight.destinationAirport', 'bookingClass.cabin', 'bundle.product'])
            ->find((int) ($fare['base_fare_id'] ?? 0));

        if (! $baseFare instanceof BaseFare) {
            return $fare;
        }

        try {
            $offer = $this->offerBuilder->build(
                baseFare: $baseFare,
                adults: (int) ($toolInput['adults'] ?? 1),
                children: (int) ($toolInput['children'] ?? 0),
                infants: (int) ($toolInput['babies'] ?? 0),
                selectedServices: $selectedServices,
            );
        } catch (Throwable) {
            return $fare;
        }

        return [
            ...$this->offerFare($offer, max(1, (int) ($toolInput['adults'] ?? 1) + (int) ($toolInput['children'] ?? 0))),
            'class' => 'Custom offer',
            'customized' => true,
            'customized_service_codes' => collect($selectedServices)
                ->pluck('service_code')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $fare
     * @param  array<int, string>  $preferredServices
     * @param  array<int, string>  $excludedServices
     * @param  array<int, array<string, mixed>>  $serviceSpecs
     * @return array<int, array{service_code: string, quantity?: int, value?: mixed}>
     */
    private function selectedServicesForDemand(array $fare, array $preferredServices, array $excludedServices, array $serviceSpecs): array
    {
        $selected = [];

        foreach ($excludedServices as $serviceCode) {
            if (! $this->fareIncludesServiceCode($fare, $serviceCode)) {
                continue;
            }

            $selected[] = [
                'service_code' => $serviceCode,
                'value' => false,
            ];
        }

        foreach ($serviceSpecs as $serviceSpec) {
            $selected[] = [
                'service_code' => (string) $serviceSpec['service_code'],
                'quantity' => max(1, (int) ($serviceSpec['quantity'] ?? 1)),
                'value' => $serviceSpec['value'] ?? $this->defaultSelectedServiceValue((string) $serviceSpec['service_code']),
            ];
        }

        foreach ($preferredServices as $serviceCode) {
            if (collect($serviceSpecs)->contains(fn (array $serviceSpec): bool => ($serviceSpec['service_code'] ?? null) === $serviceCode)) {
                continue;
            }

            if ($this->fareIncludesServiceCode($fare, $serviceCode)) {
                continue;
            }

            if ($serviceCode === 'CHECKED_BAG' && ! $this->fareIncludesServiceCode($fare, 'CABIN_BAG')) {
                $selected[] = ['service_code' => 'CABIN_BAG', 'value' => ['amount' => 8]];
            }

            $selected[] = [
                'service_code' => $serviceCode,
                'value' => $this->defaultSelectedServiceValue($serviceCode),
            ];
        }

        return collect($selected)
            ->unique(fn (array $selection): string => $selection['service_code'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $fare
     */
    private function fareIncludesServiceCode(array $fare, string $serviceCode): bool
    {
        return collect(is_array($fare['services'] ?? null) ? $fare['services'] : [])
            ->contains(fn (array $service): bool => ($service['code'] ?? null) === $serviceCode && $this->serviceIsIncluded($service));
    }

    private function defaultSelectedServiceValue(string $serviceCode): mixed
    {
        return match ($serviceCode) {
            'CHECKED_BAG' => ['amount' => 23],
            'CABIN_BAG' => ['amount' => 8],
            'CHANGE_ALLOWED' => ['amount' => 24, 'allowed' => true, 'window_hours' => 24, 'fee_type' => 'fixed', 'fee_amount' => 55],
            'REFUNDABLE' => ['amount' => 24, 'allowed' => true, 'window_hours' => 24, 'fee_type' => 'percent', 'fee_amount' => 25],
            'WIFI' => ['amount' => 250, 'data_mb' => 250],
            'WIFI_1GB' => ['amount' => 1024, 'data_mb' => 1024],
            'WIFI_5GB' => ['amount' => 5120, 'data_mb' => 5120],
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $fare
     * @param  array<int, string>  $preferredServices
     */
    private function missingPreferredServiceCount(array $fare, array $preferredServices): int
    {
        $codes = collect(is_array($fare['services'] ?? null) ? $fare['services'] : [])
            ->filter(fn (array $service): bool => $this->serviceIsIncluded($service))
            ->pluck('code')
            ->all();

        return count(array_diff($preferredServices, $codes));
    }

    /**
     * @param  array<string, mixed>  $fare
     * @param  array<int, string>  $excludedServices
     */
    private function includedExcludedServiceCount(array $fare, array $excludedServices): int
    {
        $codes = collect(is_array($fare['services'] ?? null) ? $fare['services'] : [])
            ->filter(fn (array $service): bool => $this->serviceIsIncluded($service))
            ->pluck('code')
            ->all();

        return count(array_intersect($excludedServices, $codes));
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function serviceIsIncluded(array $service): bool
    {
        return ServiceValue::isEnabled($service['value'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $flight
     * @param  array<string, mixed>  $search
     * @return array<string, mixed>
     */
    private function flightSegment(array $flight, array $search): array
    {
        return [
            'flight_number' => $flight['flight_number'],
            'date' => $flight['date'],
            'hour' => $flight['hour'],
            'arrival_hour' => $flight['arrival_time'],
            'duration_str' => $flight['duration'],
            'origin' => $search['origin'],
            'destination' => $search['destination'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offerFare(Offer $offer, int $seatPassengers): array
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

    /**
     * @return array<string, mixed>
     */
    private function orderSummary(Order $order): array
    {
        return [
            'success' => true,
            'order_id' => $order->id,
            'booking_reference' => $order->booking_reference,
            'passenger' => "{$order->first_name} {$order->last_name}",
            'total_usd' => (float) $order->total_price,
            'tickets_issued' => $order->tickets()->count(),
        ];
    }
}
