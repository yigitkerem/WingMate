<?php

namespace App\Pricing;

use App\Models\BaseFare;
use App\Models\BundleService;
use App\Models\FlightInventory;
use App\Models\Offer;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ServiceValue;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * @phpstan-type ServiceLine array{service_id: int, code: string, category?: string, default_unit?: string|null, value: mixed, quantity: int, included: bool, price: float, source: string}
 */
class OfferBuilder
{
    private const float CHILD_FARE_RATIO = 0.75;

    private const float INFANT_FARE_RATIO = 0.10;

    /**
     * @var array<int, array<int, string>>
     */
    private const array EXCLUSIVE_SERVICE_GROUPS = [
        ['SEAT_STANDARD', 'SEAT_EXIT_ROW'],
        ['WIFI', 'WIFI_1GB', 'WIFI_5GB', 'WIFI_UNLIMITED'],
    ];

    public function __construct(
        private readonly FareBasisGenerator $fareBasisGenerator,
        private readonly RuleEngine $ruleEngine,
        private readonly ServiceConstraintValidator $constraintValidator,
    ) {}

    /**
     * @param  array<int, array{service_code: string, quantity?: int, value?: mixed}>  $selectedServices
     */
    public function build(BaseFare $baseFare, int $adults, int $children, int $infants, ?User $user = null, array $selectedServices = []): Offer
    {
        $baseFare->loadMissing(['flight.originAirport', 'flight.destinationAirport', 'bookingClass.cabin', 'bundle.product']);

        $seatPassengers = $adults + $children;
        $bundleServices = $this->bundleServices($baseFare);
        $customerServices = $this->customerServices($selectedServices, $seatPassengers, $bundleServices);
        $services = $this->combineServices($bundleServices, $customerServices);

        $this->constraintValidator->validate($services);

        $passengerBase = ((float) $baseFare->base_price * $adults)
            + ((float) $baseFare->base_price * self::CHILD_FARE_RATIO * $children)
            + ((float) $baseFare->base_price * self::INFANT_FARE_RATIO * $infants);
        $taxes = (float) $baseFare->taxes * $seatPassengers;
        $fees = (float) $baseFare->fees * $seatPassengers;
        $servicesTotal = collect($services)->sum(fn (array $service): float => (float) $service['price']);
        $initialTotal = round($passengerBase + $taxes + $fees + $servicesTotal, 2);

        $context = $this->context($baseFare, $adults, $children, $infants, $user, $services);
        $state = $this->ruleEngine->apply($context, [
            'components' => [
                ['code' => 'base_fare', 'label' => 'Base fare', 'type' => 'base', 'amount' => round($passengerBase, 2)],
                ['code' => 'taxes', 'label' => 'Taxes', 'type' => 'tax', 'amount' => round($taxes, 2)],
                ['code' => 'fees', 'label' => 'Fees', 'type' => 'fee', 'amount' => round($fees, 2)],
            ],
            'services' => $services,
            'discount' => 0,
            'total' => $initialTotal,
        ]);

        $offer = Offer::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'flight_id' => $baseFare->flight_id,
            'booking_class_id' => $baseFare->booking_class_id,
            'bundle_id' => $baseFare->bundle_id,
            'base_fare_id' => $baseFare->id,
            'trip_type' => $baseFare->trip_type,
            'leg_index' => $baseFare->leg_index,
            'currency' => 'USD',
            'base_price' => round($passengerBase, 2),
            'taxes' => round($taxes, 2),
            'fees' => round($fees, 2),
            'services_total' => round(collect($state['services'])->sum(fn (array $service): float => (float) $service['price']), 2),
            'discount' => round($state['discount'], 2),
            'total_price' => max(0, round($state['total'], 2)),
            'fare_basis_code' => $this->fareBasisGenerator->generate($baseFare),
            'class_letters' => $baseFare->class_letters,
            'adults' => $adults,
            'children' => $children,
            'infants' => $infants,
            'context' => [...$context, 'matched_rules' => $state['matched_rules']],
            'expires_at' => now()->addMinutes(20),
        ]);

        foreach ($state['services'] as $service) {
            $offer->offerServices()->create(Arr::only($service, ['service_id', 'value', 'quantity', 'included', 'price', 'source']));
        }

        foreach ($state['components'] as $component) {
            $offer->priceComponents()->create([
                'pricing_rule_id' => $component['pricing_rule_id'] ?? null,
                'code' => $component['code'],
                'label' => $component['label'],
                'type' => $component['type'],
                'amount' => $component['amount'],
                'meta' => $component['meta'] ?? null,
            ]);
        }

        return $offer->load(['flight.originAirport', 'flight.destinationAirport', 'bundle.product', 'bookingClass.cabin', 'offerServices.service', 'priceComponents']);
    }

    /**
     * @return array<string, ServiceLine>
     */
    private function bundleServices(BaseFare $baseFare): array
    {
        return BundleService::query()
            ->where('bundle_id', $baseFare->bundle_id)
            ->with('service:id,code,category,default_unit')
            ->get()
            ->mapWithKeys(fn (BundleService $bundleService): array => [
                $bundleService->service->code => [
                    'service_id' => $bundleService->service_id,
                    'code' => $bundleService->service->code,
                    'category' => $bundleService->service->category,
                    'default_unit' => $bundleService->service->default_unit,
                    'value' => $bundleService->included_value,
                    'quantity' => $this->serviceQuantity($bundleService->service, $bundleService->included_value, 1),
                    'included' => $bundleService->included,
                    'price' => 0,
                    'source' => 'bundle',
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{service_code: string, quantity?: int, value?: mixed}>  $selectedServices
     * @param  array<string, ServiceLine>  $bundleServices
     * @return array<string, ServiceLine>
     */
    private function customerServices(array $selectedServices, int $seatPassengers, array $bundleServices): array
    {
        return collect($selectedServices)
            ->mapWithKeys(function (array $selection) use ($seatPassengers, $bundleServices): array {
                $service = Service::query()->where('code', $selection['service_code'])->first();

                if (! $service instanceof Service) {
                    return [];
                }

                $quantity = max(1, (int) ($selection['quantity'] ?? 1));
                $value = $selection['value'] ?? true;
                $normalizedQuantity = $this->serviceQuantity($service, $value, $quantity);
                $includedService = $this->includedComparableService($service, $bundleServices);

                if ($this->selectedServiceIsCovered($service, $value, $normalizedQuantity, $includedService)) {
                    return [];
                }

                return [
                    $service->code => [
                        'service_id' => $service->id,
                        'code' => $service->code,
                        'category' => $service->category,
                        'default_unit' => $service->default_unit,
                        'value' => $value,
                        'quantity' => $normalizedQuantity,
                        'included' => false,
                        'price' => $this->selectedServiceIsEnabled($value)
                            ? $this->selectedServicePrice($service, $value, $normalizedQuantity, $includedService, $seatPassengers)
                            : 0,
                        'source' => 'customer',
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, ServiceLine>  $bundleServices
     * @param  array<string, ServiceLine>  $customerServices
     * @return array<string, ServiceLine>
     */
    private function combineServices(array $bundleServices, array $customerServices): array
    {
        $services = $bundleServices;

        foreach ($customerServices as $serviceCode => $service) {
            $existingCustomerService = $this->existingCustomerAlternative($services, $serviceCode);

            if ($existingCustomerService !== null
                && $this->selectedServiceIsEnabled($existingCustomerService['value'] ?? null)
                && $this->selectedServiceIsEnabled($service['value'] ?? null)
                && $this->serviceLineRetailValue($existingCustomerService) >= $this->serviceLineRetailValue($service)) {
                continue;
            }

            foreach ($this->exclusiveAlternatives($serviceCode) as $alternativeCode) {
                unset($services[$alternativeCode]);
            }

            $services[$serviceCode] = $service;
        }

        return $services;
    }

    /**
     * @param  array<string, ServiceLine>  $services
     * @return ServiceLine|null
     */
    private function existingCustomerAlternative(array $services, string $serviceCode): ?array
    {
        return collect($this->exclusiveAlternatives($serviceCode))
            ->map(fn (string $alternativeCode): ?array => $services[$alternativeCode] ?? null)
            ->first(fn (?array $service): bool => is_array($service) && ($service['source'] ?? null) === 'customer');
    }

    /**
     * @param  array<string, ServiceLine>  $bundleServices
     * @return ServiceLine|null
     */
    private function includedComparableService(Service $service, array $bundleServices): ?array
    {
        $group = $this->serviceGroup($service->code);

        return collect($group)
            ->map(fn (string $serviceCode): ?array => $bundleServices[$serviceCode] ?? null)
            ->filter(fn (?array $bundleService): bool => is_array($bundleService) && $this->selectedServiceIsEnabled($bundleService['value'] ?? null))
            ->sortByDesc(fn (array $bundleService): float => $this->serviceLineRetailValue($bundleService))
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function serviceGroup(string $serviceCode): array
    {
        foreach (self::EXCLUSIVE_SERVICE_GROUPS as $group) {
            if (in_array($serviceCode, $group, true)) {
                return $group;
            }
        }

        return [$serviceCode];
    }

    /**
     * @return array<int, string>
     */
    private function exclusiveAlternatives(string $serviceCode): array
    {
        return array_values(array_diff($this->serviceGroup($serviceCode), [$serviceCode]));
    }

    /**
     * @param  ServiceLine|null  $includedService
     */
    private function selectedServiceIsCovered(Service $service, mixed $value, int $quantity, ?array $includedService): bool
    {
        if (! $this->selectedServiceIsEnabled($value) || $includedService === null) {
            return false;
        }

        if ($this->pricesByUnit($service)) {
            return $quantity <= (int) $includedService['quantity'];
        }

        if (($includedService['code'] ?? null) !== $service->code) {
            return false;
        }

        if (is_array($value) || is_array($includedService['value'] ?? null)) {
            return false;
        }

        return $this->selectedServiceRetailValue($service, $quantity) <= $this->serviceLineRetailValue($includedService);
    }

    /**
     * @param  ServiceLine|null  $includedService
     */
    private function selectedServicePrice(Service $service, mixed $value, int $quantity, ?array $includedService, int $seatPassengers): float
    {
        $includedValue = $includedService === null ? 0 : $this->serviceLineRetailValue($includedService);
        $selectedValue = $this->selectedServiceRetailValue($service, $quantity);

        return round(max(0, $selectedValue - $includedValue) * $seatPassengers, 2);
    }

    private function selectedServiceRetailValue(Service $service, int $quantity): float
    {
        $units = $this->pricesByUnit($service) ? $quantity : 1;

        return $this->activeUnitPrice($service) * $units;
    }

    /**
     * @param  ServiceLine  $service
     */
    private function serviceLineRetailValue(array $service): float
    {
        $unitPrice = ServicePrice::query()
            ->where('service_id', $service['service_id'])
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
            ->orderByDesc('bundle_id')
            ->value('unit_price') ?? 0;
        $units = ($service['category'] ?? null) === 'BAG' && ($service['default_unit'] ?? null) === 'kg'
            ? (int) $service['quantity']
            : 1;

        return (float) $unitPrice * $units;
    }

    private function activeUnitPrice(Service $service): float
    {
        return (float) (ServicePrice::query()
            ->where('service_id', $service->id)
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
            ->orderByDesc('bundle_id')
            ->value('unit_price') ?? 0);
    }

    private function serviceQuantity(Service $service, mixed $value, int $fallback): int
    {
        if ($this->pricesByUnit($service)) {
            return max(0, (int) ServiceValue::amount($value, $fallback));
        }

        return max(1, $fallback);
    }

    private function pricesByUnit(Service $service): bool
    {
        return $service->category === 'BAG' && $service->default_unit === 'kg';
    }

    private function selectedServiceIsEnabled(mixed $value): bool
    {
        return ServiceValue::isEnabled($value);
    }

    /**
     * @param  array<string, ServiceLine>  $services
     * @return array<string, mixed>
     */
    private function context(BaseFare $baseFare, int $adults, int $children, int $infants, ?User $user, array $services): array
    {
        $origin = $baseFare->flight->originAirport->iata_code;
        $destination = $baseFare->flight->destinationAirport->iata_code;
        $route = "{$origin}-{$destination}";
        $routeCount = $this->routeCount($user, $origin, $destination);
        $inventory = FlightInventory::query()
            ->where('flight_id', $baseFare->flight_id)
            ->where('booking_class_id', $baseFare->booking_class_id)
            ->value('available') ?? 0;

        return [
            'route' => $route,
            'origin' => $origin,
            'destination' => $destination,
            'trip_type' => $baseFare->trip_type,
            'leg_index' => $baseFare->leg_index,
            'bundle_code' => $baseFare->bundle->code,
            'product_code' => $baseFare->bundle->product->code,
            'booking_class' => $baseFare->bookingClass->code,
            'class_letters' => $baseFare->class_letters,
            'cabin_code' => $baseFare->bookingClass->cabin->code,
            'departure_day' => strtolower($baseFare->flight->departure_at->format('D')),
            'days_to_departure' => now()->startOfDay()->diffInDays($baseFare->flight->departure_at->copy()->startOfDay(), false),
            'adults' => $adults,
            'children' => $children,
            'infants' => $infants,
            'seat_passengers' => $adults + $children,
            'loyalty_tier' => $user instanceof User ? $user->loyalty_tier : 'guest',
            'route_count_12m' => $routeCount,
            'available_inventory' => (int) $inventory,
            'services' => array_keys($services),
        ];
    }

    private function routeCount(?User $user, string $origin, string $destination): int
    {
        if (! $user instanceof User) {
            return 0;
        }

        return Ticket::query()
            ->join('orders', 'tickets.order_id', '=', 'orders.id')
            ->join('offers', 'tickets.offer_id', '=', 'offers.id')
            ->join('flights', 'offers.flight_id', '=', 'flights.id')
            ->join('airports as origins', 'flights.origin_airport_id', '=', 'origins.id')
            ->join('airports as destinations', 'flights.destination_airport_id', '=', 'destinations.id')
            ->where('orders.user_id', $user->id)
            ->where('orders.created_at', '>=', now()->subYear())
            ->where('origins.iata_code', $origin)
            ->where('destinations.iata_code', $destination)
            ->distinct('orders.id')
            ->count('orders.id');
    }
}
