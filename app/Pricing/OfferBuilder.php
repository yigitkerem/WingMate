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
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * @phpstan-type ServiceLine array{service_id: int, code: string, value: mixed, quantity: int, included: bool, price: float, source: string}
 */
class OfferBuilder
{
    private const float CHILD_FARE_RATIO = 0.75;

    private const float INFANT_FARE_RATIO = 0.10;

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
        $customerServices = $this->customerServices($selectedServices, $seatPassengers);
        $services = [...$bundleServices, ...$customerServices];

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
            ->with('service:id,code')
            ->get()
            ->mapWithKeys(fn (BundleService $bundleService): array => [
                $bundleService->service->code => [
                    'service_id' => $bundleService->service_id,
                    'code' => $bundleService->service->code,
                    'value' => $bundleService->included_value,
                    'quantity' => 1,
                    'included' => $bundleService->included,
                    'price' => 0,
                    'source' => 'bundle',
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{service_code: string, quantity?: int, value?: mixed}>  $selectedServices
     * @return array<string, ServiceLine>
     */
    private function customerServices(array $selectedServices, int $seatPassengers): array
    {
        return collect($selectedServices)
            ->mapWithKeys(function (array $selection) use ($seatPassengers): array {
                $service = Service::query()->where('code', $selection['service_code'])->first();

                if (! $service instanceof Service) {
                    return [];
                }

                $quantity = max(1, (int) ($selection['quantity'] ?? 1));
                $value = $selection['value'] ?? true;
                $price = ServicePrice::query()
                    ->where('service_id', $service->id)
                    ->where('active', true)
                    ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
                    ->orderByDesc('bundle_id')
                    ->value('unit_price') ?? 0;

                return [
                    $service->code => [
                        'service_id' => $service->id,
                        'code' => $service->code,
                        'value' => $value,
                        'quantity' => $quantity,
                        'included' => false,
                        'price' => $this->selectedServiceIsEnabled($value)
                            ? round((float) $price * $quantity * $seatPassengers, 2)
                            : 0,
                        'source' => 'customer',
                    ],
                ];
            })
            ->all();
    }

    private function selectedServiceIsEnabled(mixed $value): bool
    {
        if (is_array($value)) {
            return ((float) ($value['amount'] ?? 0)) > 0;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((float) $value) > 0;
        }

        return $value !== null && $value !== '';
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
