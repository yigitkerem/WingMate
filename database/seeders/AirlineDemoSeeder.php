<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\Availability;
use App\Models\Flight;
use App\Models\Pnr;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class AirlineDemoSeeder extends Seeder
{
    /**
     * @var array<int, array{label: string, letter: string, offset: int, base_count: int}>
     */
    private const array EXTRA_CLASS_PROFILES = [
        ['label' => 'Economy Saver', 'letter' => 'E', 'offset' => 35, 'base_count' => 20],
        ['label' => 'Economy Standard', 'letter' => 'M', 'offset' => 95, 'base_count' => 18],
        ['label' => 'Economy Full', 'letter' => 'YQ', 'offset' => 170, 'base_count' => 14],
        ['label' => 'Premium Economy', 'letter' => 'W', 'offset' => 240, 'base_count' => 12],
        ['label' => 'Business Saver', 'letter' => 'D', 'offset' => 430, 'base_count' => 8],
        ['label' => 'Business Full', 'letter' => 'J', 'offset' => 680, 'base_count' => 6],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Ticket::query()->delete();
        Pnr::query()->delete();
        Availability::query()->delete();
        Flight::query()->delete();

        $airports = collect([
            ['name' => 'Istanbul Airport', 'code' => 'IST'],
            ['name' => 'London Heathrow Airport', 'code' => 'LHR'],
            ['name' => 'Paris Charles de Gaulle Airport', 'code' => 'CDG'],
            ['name' => 'Amsterdam Schiphol Airport', 'code' => 'AMS'],
            ['name' => 'Frankfurt Airport', 'code' => 'FRA'],
            ['name' => 'Dubai International Airport', 'code' => 'DXB'],
            ['name' => 'John F. Kennedy International Airport', 'code' => 'JFK'],
            ['name' => 'Singapore Changi Airport', 'code' => 'SIN'],
        ])->mapWithKeys(fn (array $airport): array => [
            $airport['code'] => Airport::query()->updateOrCreate(
                ['code' => $airport['code']],
                ['name' => $airport['name']],
            ),
        ]);

        $routes = [
            ['IST', 'LHR', 180, ['07:35', '13:20', '18:55']],
            ['LHR', 'IST', 178, ['09:10', '15:45', '21:10']],
            ['IST', 'CDG', 150, ['06:50', '12:30', '19:25']],
            ['CDG', 'IST', 152, ['08:05', '14:00', '20:15']],
            ['IST', 'AMS', 145, ['08:45', '16:20']],
            ['AMS', 'IST', 143, ['10:25', '18:10']],
            ['IST', 'FRA', 135, ['07:10', '17:30']],
            ['FRA', 'IST', 138, ['11:20', '19:40']],
            ['IST', 'DXB', 260, ['01:45', '14:15', '22:30']],
            ['DXB', 'IST', 255, ['03:05', '12:25', '20:50']],
            ['LHR', 'JFK', 520, ['09:55', '16:10']],
            ['JFK', 'LHR', 515, ['18:40', '22:15']],
            ['DXB', 'SIN', 390, ['02:15', '21:40']],
            ['SIN', 'DXB', 388, ['01:20', '20:30']],
            ['FRA', 'JFK', 545, ['10:15', '17:05']],
            ['JFK', 'FRA', 540, ['19:00', '23:30']],
        ];

        $flightSequence = 100;

        foreach (range(0, 24) as $dayOffset) {
            $date = CarbonImmutable::today()->addDays($dayOffset);

            foreach ($routes as [$originCode, $destinationCode, $basePriceUsd, $hours]) {
                foreach ($hours as $hour) {
                    $flight = Flight::query()->create([
                        'date' => $date->toDateString(),
                        'hour' => $hour,
                        'origin_airport_id' => $airports[$originCode]->id,
                        'destination_airport_id' => $airports[$destinationCode]->id,
                        'flight_number' => 'DP'.$flightSequence++,
                        'plane_model' => $this->planeForRoute($originCode, $destinationCode),
                    ]);

                    $this->createAvailability($flight, $basePriceUsd, $dayOffset);
                }
            }
        }

        Availability::query()
            ->inRandomOrder()
            ->limit(120)
            ->get()
            ->each(function (Availability $availability): void {
                $pnr = Pnr::factory()->create();

                Ticket::factory()
                    ->count(fake()->numberBetween(1, 3))
                    ->create([
                        'availability_id' => $availability->id,
                        'pnr_id' => $pnr->id,
                    ]);
            });
    }

    private function createAvailability(Flight $flight, int $basePriceUsd, int $dayOffset): void
    {
        $demandMultiplier = match (true) {
            $dayOffset <= 2 => 1.45,
            $dayOffset <= 7 => 1.25,
            $dayOffset <= 14 => 1.10,
            default => 1.00,
        };

        $prices = [
            'A' => (int) round($basePriceUsd * $demandMultiplier),
            'B' => (int) round(($basePriceUsd + 65) * $demandMultiplier),
            'C' => (int) round(($basePriceUsd + 280) * $demandMultiplier),
        ];

        collect(Flight::defaultAvailabilityTemplates($prices['A'], $prices['B'], $prices['C']))
            ->each(function (array $availability, string $classLetter) use ($flight, $dayOffset): void {
                Availability::query()->create([
                    ...$availability,
                    'flight_id' => $flight->id,
                    'count_available' => $this->defaultAvailabilityCount($classLetter, $dayOffset),
                ]);
            });

        collect(Flight::roundTripAvailabilityTemplates($prices['A'], $prices['B'], $prices['C']))
            ->each(function (array $availability, string $classLetter) use ($flight, $dayOffset): void {
                Availability::query()->create([
                    ...$availability,
                    'flight_id' => $flight->id,
                    'count_available' => $this->roundTripAvailabilityCount($classLetter, $dayOffset),
                ]);
            });

        Availability::query()->insert(
            collect(['one_way', 'round_trip'])
                ->flatMap(fn (string $fareType): Collection => $this->extraAvailabilityRows(
                    $flight,
                    $basePriceUsd,
                    $demandMultiplier,
                    $dayOffset,
                    $fareType,
                ))
                ->all(),
        );
    }

    /**
     * @return Collection<int, array{checked_baggage_kg: int, cabin_baggage_kg: int, seat_selection_free: bool, refund_paid: bool, latest_refund_hours: int|null, change_paid: bool, latest_change_hours: int|null}>
     */
    public static function fareFeatureCombinations(): Collection
    {
        return collect([0, 5, 10, 15, 20, 25])
            ->crossJoin(
                [0, 8],
                [false, true],
                [
                    ['paid' => false, 'latest_hours' => 12],
                    ['paid' => false, 'latest_hours' => 72],
                    ['paid' => true, 'latest_hours' => null],
                ],
                [
                    ['paid' => false, 'latest_hours' => 6],
                    ['paid' => false, 'latest_hours' => 36],
                    ['paid' => true, 'latest_hours' => null],
                ],
            )
            ->map(fn (array $combination): array => [
                'checked_baggage_kg' => $combination[0],
                'cabin_baggage_kg' => $combination[1],
                'seat_selection_free' => $combination[2],
                'refund_paid' => $combination[3]['paid'],
                'latest_refund_hours' => $combination[3]['latest_hours'],
                'change_paid' => $combination[4]['paid'],
                'latest_change_hours' => $combination[4]['latest_hours'],
            ]);
    }

    /**
     * @return Collection<int, array<string, bool|int|string|null>>
     */
    private function extraAvailabilityRows(Flight $flight, int $basePriceUsd, float $demandMultiplier, int $dayOffset, string $fareType): Collection
    {
        $timestamp = now();

        return self::fareFeatureCombinations()
            ->values()
            ->map(function (array $features, int $index) use ($flight, $basePriceUsd, $demandMultiplier, $dayOffset, $fareType, $timestamp): array {
                $profile = self::EXTRA_CLASS_PROFILES[$index % count(self::EXTRA_CLASS_PROFILES)];
                $oneWayPriceUsd = (int) round(
                    ($basePriceUsd + $profile['offset'] + $this->featurePriceOffset($features)) * $demandMultiplier,
                );
                $classLetters = sprintf('%s%03d', $profile['letter'], $index + 1);
                $countAvailable = $this->extraAvailabilityCount($profile['base_count'], $dayOffset);

                if ($fareType === 'round_trip') {
                    $classLetters .= '(R)';
                    $countAvailable = max(0, (int) round($countAvailable * 0.75));
                }

                return [
                    'flight_id' => $flight->id,
                    'class' => $fareType === 'round_trip' ? "{$profile['label']} Roundtrip" : $profile['label'],
                    'checked_baggage_kg' => $features['checked_baggage_kg'],
                    'cabin_baggage_kg' => $features['cabin_baggage_kg'],
                    'seat_selection_free' => $features['seat_selection_free'],
                    'change_fee_usd' => $features['change_paid'] ? $this->changePenaltyUsd($oneWayPriceUsd) : 0,
                    'refund_fee_usd' => $features['refund_paid'] ? $this->refundPenaltyUsd($oneWayPriceUsd) : 0,
                    'latest_refund_hours' => $features['latest_refund_hours'],
                    'latest_change_hours' => $features['latest_change_hours'],
                    'class_letters' => $classLetters,
                    'fare_type' => $fareType,
                    'base_price_usd' => $fareType === 'round_trip' ? Flight::roundTripPrice($oneWayPriceUsd) : $oneWayPriceUsd,
                    'count_available' => $countAvailable,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            });
    }

    /**
     * @param  array{checked_baggage_kg: int, cabin_baggage_kg: int, seat_selection_free: bool, refund_paid: bool, latest_refund_hours: int|null, change_paid: bool, latest_change_hours: int|null}  $features
     */
    private function featurePriceOffset(array $features): int
    {
        $refundOffset = match ($features['latest_refund_hours']) {
            12 => 24,
            72 => 48,
            default => 0,
        };
        $changeOffset = match ($features['latest_change_hours']) {
            6 => 18,
            36 => 36,
            default => 0,
        };

        return ($features['checked_baggage_kg'] * 3)
            + ($features['cabin_baggage_kg'] === 8 ? 20 : 0)
            + ($features['seat_selection_free'] ? 18 : 0)
            + $refundOffset
            + $changeOffset;
    }

    private function changePenaltyUsd(int $oneWayPriceUsd): int
    {
        return max(30, (int) round($oneWayPriceUsd * 0.25));
    }

    private function refundPenaltyUsd(int $oneWayPriceUsd): int
    {
        return max(50, (int) round($oneWayPriceUsd * 0.45));
    }

    private function extraAvailabilityCount(int $baseCount, int $dayOffset): int
    {
        if ($dayOffset <= 2) {
            return fake()->numberBetween(0, $baseCount);
        }

        return fake()->numberBetween(1, $baseCount);
    }

    private function defaultAvailabilityCount(string $classLetter, int $dayOffset): int
    {
        if ($dayOffset <= 2 && $classLetter === 'A') {
            return fake()->randomElement([0, 0, 1, 2, 4]);
        }

        return match ($classLetter) {
            'A' => fake()->numberBetween(0, 18),
            'B' => fake()->numberBetween(2, 14),
            'C' => fake()->numberBetween(1, 8),
            default => 0,
        };
    }

    private function roundTripAvailabilityCount(string $classLetter, int $dayOffset): int
    {
        return max(0, (int) round($this->defaultAvailabilityCount($classLetter, $dayOffset) * 0.75));
    }

    private function planeForRoute(string $originCode, string $destinationCode): string
    {
        $longHaulCodes = ['JFK', 'SIN', 'DXB'];

        if (in_array($originCode, $longHaulCodes, true) || in_array($destinationCode, $longHaulCodes, true)) {
            return fake()->randomElement(['Airbus A330-300', 'Airbus A350-900', 'Boeing 787-9']);
        }

        return fake()->randomElement(['Airbus A320neo', 'Airbus A321neo', 'Boeing 737 MAX 8']);
    }
}
