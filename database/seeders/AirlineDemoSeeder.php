<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\BaseFare;
use App\Models\BookingClass;
use App\Models\Bundle;
use App\Models\BundleService;
use App\Models\Cabin;
use App\Models\Flight;
use App\Models\FlightInventory;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PriceComponent;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Service as AirlineService;
use App\Models\ServiceConstraint;
use App\Models\ServicePrice;
use App\Models\Ticket;
use App\Models\TicketSegment;
use App\Models\User;
use App\Pricing\OfferBuilder;
use App\Support\ServiceValue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class AirlineDemoSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, city: string, country: string, timezone: string, icao?: string}>
     */
    private const array AIRPORTS = [
        'IST' => ['name' => 'Istanbul Airport', 'city' => 'Istanbul', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTFM'],
        'SAW' => ['name' => 'Sabiha Gokcen International Airport', 'city' => 'Istanbul', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTFJ'],
        'ESB' => ['name' => 'Ankara Esenboga Airport', 'city' => 'Ankara', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAC'],
        'ADB' => ['name' => 'Izmir Adnan Menderes Airport', 'city' => 'Izmir', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTBJ'],
        'AYT' => ['name' => 'Antalya Airport', 'city' => 'Antalya', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAI'],
        'LHR' => ['name' => 'London Heathrow Airport', 'city' => 'London', 'country' => 'GB', 'timezone' => 'Europe/London', 'icao' => 'EGLL'],
        'CDG' => ['name' => 'Paris Charles de Gaulle Airport', 'city' => 'Paris', 'country' => 'FR', 'timezone' => 'Europe/Paris', 'icao' => 'LFPG'],
        'AMS' => ['name' => 'Amsterdam Schiphol Airport', 'city' => 'Amsterdam', 'country' => 'NL', 'timezone' => 'Europe/Amsterdam', 'icao' => 'EHAM'],
        'FRA' => ['name' => 'Frankfurt Airport', 'city' => 'Frankfurt', 'country' => 'DE', 'timezone' => 'Europe/Berlin', 'icao' => 'EDDF'],
        'DXB' => ['name' => 'Dubai International Airport', 'city' => 'Dubai', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'icao' => 'OMDB'],
        'JFK' => ['name' => 'John F. Kennedy International Airport', 'city' => 'New York', 'country' => 'US', 'timezone' => 'America/New_York', 'icao' => 'KJFK'],
        'SIN' => ['name' => 'Singapore Changi Airport', 'city' => 'Singapore', 'country' => 'SG', 'timezone' => 'Asia/Singapore', 'icao' => 'WSSS'],
    ];

    /**
     * @var array<int, array{0: string, 1: string, 2: int, 3: int, 4: array<int, string>}>
     */
    private const array ROUTES = [
        ['IST', 'LHR', 210, 250, ['07:35', '13:20', '18:55']],
        ['LHR', 'IST', 205, 230, ['09:10', '15:45', '21:10']],
        ['IST', 'CDG', 185, 220, ['06:50', '12:30', '19:25']],
        ['CDG', 'IST', 178, 205, ['08:05', '14:00', '20:15']],
        ['IST', 'AMS', 190, 225, ['08:45', '16:20']],
        ['AMS', 'IST', 180, 205, ['10:25', '18:10']],
        ['IST', 'FRA', 170, 200, ['07:10', '17:30']],
        ['FRA', 'IST', 165, 185, ['11:20', '19:40']],
        ['IST', 'DXB', 295, 275, ['01:45', '14:15', '22:30']],
        ['DXB', 'IST', 300, 295, ['03:05', '12:25', '20:50']],
        ['IST', 'JFK', 620, 660, ['08:25', '14:05']],
        ['JFK', 'IST', 590, 570, ['00:20', '19:00']],
        ['IST', 'SIN', 690, 650, ['02:10']],
        ['SIN', 'IST', 660, 680, ['10:15']],
        ['SAW', 'ADB', 72, 65, ['08:00', '13:35', '20:20']],
        ['ADB', 'SAW', 70, 65, ['07:50', '15:10', '21:15']],
    ];

    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        collect([
            TicketSegment::class,
            Ticket::class,
            Order::class,
            PriceComponent::class,
            Offer::class,
            BaseFare::class,
            FlightInventory::class,
            Flight::class,
            ServicePrice::class,
            ServiceConstraint::class,
            BundleService::class,
            AirlineService::class,
            Bundle::class,
            Product::class,
            BookingClass::class,
            Cabin::class,
            PricingRule::class,
            Airport::class,
        ])->each(fn (string $model): int => $model::query()->delete());
        Schema::enableForeignKeyConstraints();

        $airports = $this->seedAirports();
        $cabins = $this->seedCabins();
        $classes = $this->seedBookingClasses($cabins);
        $bundles = $this->seedProductsAndBundles();
        $services = $this->seedServices($bundles);
        $this->seedRules();
        $this->seedFlights($airports, $classes, $bundles);
        $this->seedHistory($airports, $classes, $bundles);
    }

    /**
     * @return array<string, Airport>
     */
    private function seedAirports(): array
    {
        return collect(self::AIRPORTS)
            ->mapWithKeys(fn (array $airport, string $code): array => [
                $code => Airport::query()->create([
                    'iata_code' => $code,
                    'icao_code' => $airport['icao'],
                    'name' => $airport['name'],
                    'city' => $airport['city'],
                    'country' => $airport['country'],
                    'timezone' => $airport['timezone'],
                ]),
            ])
            ->all();
    }

    /**
     * @return array<string, Cabin>
     */
    private function seedCabins(): array
    {
        return [
            'ECONOMY' => Cabin::query()->create(['code' => 'ECONOMY', 'name' => 'Economy', 'display_order' => 1]),
            'BUSINESS' => Cabin::query()->create(['code' => 'BUSINESS', 'name' => 'Business', 'display_order' => 2]),
        ];
    }

    /**
     * @param  array<string, Cabin>  $cabins
     * @return array<string, BookingClass>
     */
    private function seedBookingClasses(array $cabins): array
    {
        $rows = [
            ['V', 'ECONOMY', 10],
            ['Q', 'ECONOMY', 20],
            ['M', 'ECONOMY', 30],
            ['Y', 'ECONOMY', 40],
            ['D', 'BUSINESS', 50],
            ['J', 'BUSINESS', 60],
        ];

        return collect($rows)
            ->mapWithKeys(fn (array $row): array => [
                $row[0] => BookingClass::query()->create([
                    'code' => $row[0],
                    'cabin_id' => $cabins[$row[1]]->id,
                    'priority' => $row[2],
                ]),
            ])
            ->all();
    }

    /**
     * @return array<string, Bundle>
     */
    private function seedProductsAndBundles(): array
    {
        $economy = Product::query()->create(['code' => 'ECONOMY', 'name' => 'Economy', 'description' => 'Economy cabin offers']);
        $business = Product::query()->create(['code' => 'BUSINESS', 'name' => 'Business', 'description' => 'Business cabin offers']);

        return [
            'ECOFLY' => Bundle::query()->create(['product_id' => $economy->id, 'code' => 'ECOFLY', 'name' => 'EcoFly', 'description' => 'Lowest economy fare', 'display_order' => 1]),
            'EXTRAFLY' => Bundle::query()->create(['product_id' => $economy->id, 'code' => 'EXTRAFLY', 'name' => 'ExtraFly', 'description' => 'Economy with baggage and seat options', 'display_order' => 2]),
            'PRIMEFLY' => Bundle::query()->create(['product_id' => $economy->id, 'code' => 'PRIMEFLY', 'name' => 'PrimeFly', 'description' => 'Flexible economy fare', 'display_order' => 3]),
            'BUSINESSFLY' => Bundle::query()->create(['product_id' => $business->id, 'code' => 'BUSINESSFLY', 'name' => 'BusinessFly', 'description' => 'Business essentials', 'display_order' => 4]),
            'BUSINESSPRIME' => Bundle::query()->create(['product_id' => $business->id, 'code' => 'BUSINESSPRIME', 'name' => 'BusinessPrime', 'description' => 'Fully flexible business', 'display_order' => 5]),
        ];
    }

    /**
     * @param  array<string, Bundle>  $bundles
     * @return array<string, AirlineService>
     */
    private function seedServices(array $bundles): array
    {
        $services = collect([
            ['CHECKED_BAG', 'Checked baggage', 'BAG', 'integer', 'kg', 4, 50],
            ['CABIN_BAG', 'Cabin baggage', 'BAG', 'integer', 'kg', 5, 12],
            ['SEAT_SELECTION', 'Seat selection', 'SEAT', 'boolean', 'seat', 18, 1],
            ['SEAT_STANDARD', 'Standard seat selection', 'SEAT', 'string', 'seat', 12, 1],
            ['SEAT_EXIT_ROW', 'Exit row seat selection', 'SEAT', 'string', 'seat', 32, 1],
            ['CHANGE_ALLOWED', 'Change rule', 'FLEXIBILITY', 'integer', 'hours', 16, 1],
            ['CHANGE_FEE', 'Change fee', 'FLEXIBILITY', 'decimal', 'USD', 0, 1],
            ['REFUNDABLE', 'Refund rule', 'FLEXIBILITY', 'integer', 'hours', 24, 1],
            ['REFUND_FEE', 'Refund fee', 'FLEXIBILITY', 'decimal', 'USD', 0, 1],
            ['LOUNGE', 'Lounge access', 'LOUNGE', 'boolean', 'passenger', 55, 1],
            ['FAST_TRACK', 'Fast track', 'AIRPORT', 'boolean', 'passenger', 25, 1],
            ['PRIORITY_BOARDING', 'Priority boarding', 'AIRPORT', 'boolean', 'passenger', 18, 1],
            ['PRIORITY_CHECKIN', 'Priority check-in', 'AIRPORT', 'boolean', 'passenger', 16, 1],
            ['WIFI', 'Wi-Fi 250 MB', 'CONNECTIVITY', 'integer', 'MB', 6, 1],
            ['WIFI_1GB', 'Wi-Fi 1 GB', 'CONNECTIVITY', 'integer', 'MB', 12, 1],
            ['WIFI_5GB', 'Wi-Fi 5 GB', 'CONNECTIVITY', 'integer', 'MB', 22, 1],
            ['WIFI_UNLIMITED', 'Wi-Fi unlimited', 'CONNECTIVITY', 'boolean', 'flight', 35, 1],
            ['MEAL', 'Special meal', 'MEAL', 'boolean', 'passenger', 16, 1],
        ])->mapWithKeys(function (array $row): array {
            $service = AirlineService::query()->create([
                'code' => $row[0],
                'name' => $row[1],
                'category' => $row[2],
                'value_type' => $row[3],
                'default_unit' => $row[4],
            ]);

            ServicePrice::query()->create([
                'service_id' => $service->id,
                'currency' => 'USD',
                'unit_price' => $row[5],
                'max_quantity' => $row[6],
            ]);

            return [$service->code => $service];
        })->all();

        $bundleMatrix = [
            'ECOFLY' => ['CABIN_BAG' => ['amount' => 8], 'CHECKED_BAG' => ['amount' => 0], 'SEAT_SELECTION' => false, 'CHANGE_ALLOWED' => false, 'CHANGE_FEE' => ['amount' => null], 'REFUNDABLE' => false, 'REFUND_FEE' => ['amount' => null]],
            'EXTRAFLY' => ['CABIN_BAG' => ['amount' => 8], 'CHECKED_BAG' => ['amount' => 23], 'SEAT_SELECTION' => true, 'SEAT_STANDARD' => ['seat_type' => 'standard'], 'CHANGE_ALLOWED' => ['amount' => 24, 'allowed' => true, 'window_hours' => 24, 'fee_type' => 'fixed', 'fee_amount' => 55], 'CHANGE_FEE' => ['amount' => 55], 'REFUNDABLE' => false, 'REFUND_FEE' => ['amount' => null]],
            'PRIMEFLY' => ['CABIN_BAG' => ['amount' => 8], 'CHECKED_BAG' => ['amount' => 30], 'SEAT_SELECTION' => true, 'SEAT_STANDARD' => ['seat_type' => 'standard'], 'CHANGE_ALLOWED' => ['amount' => 72, 'allowed' => true, 'window_hours' => 72, 'fee_type' => 'fixed', 'fee_amount' => 0], 'CHANGE_FEE' => ['amount' => 0], 'REFUNDABLE' => ['amount' => 24, 'allowed' => true, 'window_hours' => 24, 'fee_type' => 'fixed', 'fee_amount' => 45], 'REFUND_FEE' => ['amount' => 45], 'PRIORITY_BOARDING' => true, 'WIFI' => ['amount' => 250, 'data_mb' => 250]],
            'BUSINESSFLY' => ['CABIN_BAG' => ['amount' => 8], 'CHECKED_BAG' => ['amount' => 40], 'SEAT_SELECTION' => true, 'SEAT_EXIT_ROW' => ['seat_type' => 'exit_row'], 'CHANGE_ALLOWED' => ['amount' => 24, 'allowed' => true, 'window_hours' => 24, 'fee_type' => 'fixed', 'fee_amount' => 40], 'CHANGE_FEE' => ['amount' => 40], 'REFUNDABLE' => ['amount' => 72, 'allowed' => true, 'window_hours' => 72, 'fee_type' => 'percent', 'fee_amount' => 25], 'REFUND_FEE' => ['amount' => 25, 'fee_type' => 'percent'], 'LOUNGE' => true, 'FAST_TRACK' => true, 'PRIORITY_CHECKIN' => true, 'WIFI_1GB' => ['amount' => 1024, 'data_mb' => 1024]],
            'BUSINESSPRIME' => ['CABIN_BAG' => ['amount' => 8], 'CHECKED_BAG' => ['amount' => 50], 'SEAT_SELECTION' => true, 'SEAT_EXIT_ROW' => ['seat_type' => 'exit_row'], 'CHANGE_ALLOWED' => ['amount' => 360, 'allowed' => true, 'window_hours' => 360, 'fee_type' => 'fixed', 'fee_amount' => 0], 'CHANGE_FEE' => ['amount' => 0], 'REFUNDABLE' => ['amount' => 240, 'allowed' => true, 'window_hours' => 240, 'fee_type' => 'fixed', 'fee_amount' => 0], 'REFUND_FEE' => ['amount' => 0], 'LOUNGE' => true, 'FAST_TRACK' => true, 'PRIORITY_BOARDING' => true, 'PRIORITY_CHECKIN' => true, 'WIFI_UNLIMITED' => true],
        ];

        foreach ($bundleMatrix as $bundleCode => $serviceValues) {
            foreach ($serviceValues as $serviceCode => $value) {
                BundleService::query()->create([
                    'bundle_id' => $bundles[$bundleCode]->id,
                    'service_id' => $services[$serviceCode]->id,
                    'included_value' => $value,
                    'included' => $this->serviceValueIsIncluded($value),
                ]);
            }
        }

        ServiceConstraint::query()->create([
            'service_id' => $services['CHECKED_BAG']->id,
            'type' => 'requires',
            'related_service_id' => $services['CABIN_BAG']->id,
            'message' => 'Checked baggage requires a cabin baggage allowance.',
        ]);
        ServiceConstraint::query()->create([
            'service_id' => $services['CHECKED_BAG']->id,
            'type' => 'max_quantity',
            'parameters' => ['quantity' => 50],
            'message' => 'At most 50 kg of checked baggage can be selected.',
        ]);
        ServiceConstraint::query()->create([
            'service_id' => $services['CABIN_BAG']->id,
            'type' => 'max_quantity',
            'parameters' => ['quantity' => 12],
            'message' => 'At most 12 kg of cabin baggage can be selected.',
        ]);

        return $services;
    }

    private function serviceValueIsIncluded(mixed $value): bool
    {
        return ServiceValue::isEnabled($value);
    }

    private function seedRules(): void
    {
        collect([
            ['Weekend uplift', 'weekend', 20, true, "departure_day == 'fri' || departure_day == 'sat' || departure_day == 'sun'", [['type' => 'percentage_surcharge', 'value' => 12, 'label' => 'Weekend demand']]],
            ['Close-in booking uplift', 'close_in', 22, true, 'days_to_departure <= 2', [['type' => 'percentage_surcharge', 'value' => 7, 'label' => 'Close-in booking demand']]],
            ['Low inventory protection', 'inventory_protection', 24, true, 'available_inventory <= 8', [['type' => 'percentage_surcharge', 'value' => 9, 'label' => 'Low inventory protection']]],
            ['Advance purchase saver', 'advance_purchase', 26, true, 'days_to_departure >= 21', [['type' => 'percentage_discount', 'value' => 6, 'label' => 'Advance purchase saving']]],
            ['Elite extra fast track', 'loyalty_service', 30, true, "loyalty_tier == 'elite' || loyalty_tier == 'elite_plus'", [['type' => 'include_service', 'service_code' => 'FAST_TRACK', 'label' => 'Elite fast track']]],
            ['Elite lounge invitation', 'loyalty_lounge', 32, true, "loyalty_tier == 'elite_plus'", [['type' => 'include_service', 'service_code' => 'LOUNGE', 'label' => 'Elite Plus lounge invitation']]],
            ['Family standard seats', 'family_seating', 34, true, 'children > 0', [['type' => 'include_service', 'service_code' => 'SEAT_STANDARD', 'value' => ['seat_type' => 'standard'], 'label' => 'Family standard seats']]],
            ['Infant priority check-in', 'infant_service', 36, true, 'infants > 0', [['type' => 'include_service', 'service_code' => 'PRIORITY_CHECKIN', 'label' => 'Infant priority check-in']]],
            ['Repeat route discount', 'history_discount', 40, true, 'route_count_12m >= 10', [['type' => 'percentage_discount', 'value' => 10, 'label' => 'Repeat route discount']]],
            ['Roundtrip saving', 'roundtrip_discount', 50, true, "trip_type == 'round_trip'", [['type' => 'percentage_discount', 'value' => 8, 'label' => 'Roundtrip saving']]],
            ['Domestic shuttle saver', 'domestic_discount', 54, true, "route == 'SAW-ADB' || route == 'ADB-SAW'", [['type' => 'fixed_discount', 'value' => 12, 'label' => 'Domestic shuttle saving']]],
            ['London advance saver', 'route_discount', 56, true, "route == 'IST-LHR' && days_to_departure >= 14", [['type' => 'fixed_discount', 'value' => 15, 'label' => 'London advance saving']]],
            ['Dubai airport convenience', 'airport_service', 58, true, "destination == 'DXB'", [['type' => 'include_service', 'service_code' => 'FAST_TRACK', 'label' => 'Dubai fast track']]],
            ['IST-JFK market surcharge', 'route_surcharge', 60, true, "route == 'IST-JFK'", [['type' => 'percentage_surcharge', 'value' => 6, 'label' => 'Long-haul demand']]],
            ['Long-haul Wi-Fi starter', 'longhaul_wifi', 62, true, "route == 'IST-JFK' || route == 'JFK-IST' || route == 'IST-SIN' || route == 'SIN-IST'", [['type' => 'include_service', 'service_code' => 'WIFI', 'value' => ['amount' => 250, 'data_mb' => 250], 'label' => 'Long-haul Wi-Fi starter']]],
            ['Business Wi-Fi upgrade', 'business_wifi', 64, true, "cabin_code == 'BUSINESS'", [['type' => 'include_service', 'service_code' => 'WIFI_5GB', 'value' => ['amount' => 5120, 'data_mb' => 5120], 'label' => 'Business Wi-Fi upgrade']]],
            ['BusinessPrime chauffeur fare', 'premium_fixed', 90, false, "bundle_code == 'BUSINESSPRIME' && route == 'IST-JFK' && days_to_departure >= 21", [['type' => 'fixed_fare', 'value' => 1599, 'label' => 'BusinessPrime long-haul fixed fare']]],
        ])->each(fn (array $rule): PricingRule => PricingRule::query()->create([
            'name' => $rule[0],
            'preset' => $rule[1],
            'priority' => $rule[2],
            'active' => true,
            'stackable' => $rule[3],
            'condition_expression' => $rule[4],
            'actions' => $rule[5],
        ]));
    }

    /**
     * @param  array<string, Airport>  $airports
     * @param  array<string, BookingClass>  $classes
     * @param  array<string, Bundle>  $bundles
     */
    private function seedFlights(array $airports, array $classes, array $bundles): void
    {
        $sequence = 100;

        foreach (range(0, 44) as $dayOffset) {
            foreach (self::ROUTES as [$origin, $destination, $price, $duration, $hours]) {
                foreach ($hours as $hour) {
                    $departureAt = CarbonImmutable::today()->addDays($dayOffset)->setTimeFromTimeString($hour);
                    $flight = Flight::query()->create([
                        'flight_number' => 'TK'.$sequence++,
                        'origin_airport_id' => $airports[$origin]->id,
                        'destination_airport_id' => $airports[$destination]->id,
                        'departure_at' => $departureAt,
                        'arrival_at' => $departureAt->addMinutes($duration),
                        'duration_minutes' => $duration,
                        'aircraft_type' => $this->aircraftFor($origin, $destination),
                        'status' => 'scheduled',
                    ]);

                    $this->seedInventoryAndFares($flight, $classes, $bundles, $price, $dayOffset);
                }
            }
        }
    }

    /**
     * @param  array<string, BookingClass>  $classes
     * @param  array<string, Bundle>  $bundles
     */
    private function seedInventoryAndFares(Flight $flight, array $classes, array $bundles, int $routePrice, int $dayOffset): void
    {
        $demand = match (true) {
            $dayOffset <= 3 => 1.35,
            $dayOffset <= 10 => 1.20,
            $dayOffset <= 21 => 1.08,
            default => 1.00,
        };

        $profiles = [
            ['ECOFLY', 'V', 0, 44],
            ['EXTRAFLY', 'Q', 75, 36],
            ['PRIMEFLY', 'M', 145, 28],
            ['BUSINESSFLY', 'D', 420, 12],
            ['BUSINESSPRIME', 'J', 720, 8],
        ];

        foreach ($profiles as [$bundleCode, $classCode, $offset, $capacity]) {
            FlightInventory::query()->updateOrCreate(
                ['flight_id' => $flight->id, 'booking_class_id' => $classes[$classCode]->id],
                ['capacity' => $capacity, 'available' => fake()->numberBetween(max(2, (int) ($capacity * 0.25)), $capacity)],
            );

            foreach (['one_way' => [1], 'round_trip' => [1, 2]] as $tripType => $legs) {
                foreach ($legs as $legIndex) {
                    $base = (int) round(($routePrice + $offset) * $demand * ($tripType === 'round_trip' ? 0.88 : 1));
                    BaseFare::query()->create([
                        'flight_id' => $flight->id,
                        'booking_class_id' => $classes[$classCode]->id,
                        'bundle_id' => $bundles[$bundleCode]->id,
                        'trip_type' => $tripType,
                        'leg_index' => $legIndex,
                        'currency' => 'USD',
                        'base_price' => $base,
                        'taxes' => $this->taxFor($flight),
                        'fees' => $tripType === 'round_trip' ? 22 : 28,
                        'fare_basis_template' => '{class}{bundle}{trip}{leg}',
                        'class_letters' => $tripType === 'round_trip' ? "{$classCode}(R{$legIndex})" : $classCode,
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, Airport>  $airports
     * @param  array<string, BookingClass>  $classes
     * @param  array<string, Bundle>  $bundles
     */
    private function seedHistory(array $airports, array $classes, array $bundles): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'frequent@example.com'],
            ['name' => 'Frequent Flyer', 'password' => 'password', 'passport_number' => 'FF1234567', 'loyalty_tier' => 'elite'],
        );

        $builder = app(OfferBuilder::class);

        foreach (range(1, 10) as $index) {
            $departureAt = CarbonImmutable::today()->subMonths($index)->setTime(9, 20);
            $flight = Flight::query()->create([
                'flight_number' => 'TK9'.$index,
                'origin_airport_id' => $airports['IST']->id,
                'destination_airport_id' => $airports['LHR']->id,
                'departure_at' => $departureAt,
                'arrival_at' => $departureAt->addMinutes(250),
                'duration_minutes' => 250,
                'aircraft_type' => 'Airbus A321neo',
                'status' => 'completed',
            ]);
            FlightInventory::query()->create(['flight_id' => $flight->id, 'booking_class_id' => $classes['Q']->id, 'capacity' => 30, 'available' => 20]);
            $fare = BaseFare::query()->create([
                'flight_id' => $flight->id,
                'booking_class_id' => $classes['Q']->id,
                'bundle_id' => $bundles['EXTRAFLY']->id,
                'trip_type' => 'one_way',
                'leg_index' => 1,
                'base_price' => 220,
                'taxes' => 42,
                'fees' => 25,
                'fare_basis_template' => '{class}{bundle}{trip}{leg}',
                'class_letters' => 'Q',
            ]);
            $offer = $builder->build($fare, 1, 0, 0, $user);
            $order = Order::query()->create([
                'user_id' => $user->id,
                'booking_reference' => 'HX'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'status' => 'flown',
                'first_name' => 'Frequent',
                'last_name' => 'Flyer',
                'email' => $user->email,
                'passport_number' => $user->passport_number,
                'total_price' => $offer->total_price,
                'passengers' => ['adults' => 1, 'children' => 0, 'infants' => 0],
                'created_at' => $departureAt,
                'updated_at' => $departureAt,
            ]);
            $ticket = Ticket::query()->create([
                'order_id' => $order->id,
                'offer_id' => $offer->id,
                'ticket_number' => '23599'.str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                'passenger_type' => 'ADT',
                'status' => 'flown',
                'issued_at' => $departureAt,
            ]);
            TicketSegment::query()->create([
                'ticket_id' => $ticket->id,
                'flight_id' => $flight->id,
                'booking_class_id' => $classes['Q']->id,
                'coupon_status' => 'flown',
            ]);
        }
    }

    private function aircraftFor(string $origin, string $destination): string
    {
        return in_array($origin, ['JFK', 'SIN', 'DXB'], true) || in_array($destination, ['JFK', 'SIN', 'DXB'], true)
            ? fake()->randomElement(['Airbus A330-300', 'Airbus A350-900', 'Boeing 787-9'])
            : fake()->randomElement(['Airbus A320neo', 'Airbus A321neo', 'Boeing 737 MAX 8']);
    }

    private function taxFor(Flight $flight): int
    {
        return in_array($flight->originAirport->country, ['TR'], true) && in_array($flight->destinationAirport->country, ['TR'], true) ? 18 : 46;
    }
}
