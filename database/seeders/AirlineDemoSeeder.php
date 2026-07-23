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

        $this->extraBookingClasses($basePriceUsd, $demandMultiplier, 'one_way')
            ->each(fn (array $availability): Availability => Availability::query()->create([
                ...$availability,
                'flight_id' => $flight->id,
            ]));

        $this->extraBookingClasses($basePriceUsd, $demandMultiplier, 'round_trip')
            ->each(fn (array $availability): Availability => Availability::query()->create([
                ...$availability,
                'flight_id' => $flight->id,
            ]));
    }

    /**
     * @return Collection<int, array<string, int|string|null>>
     */
    private function extraBookingClasses(int $basePriceUsd, float $demandMultiplier, string $fareType): Collection
    {
        return collect([
            ['class' => 'Economy Saver', 'class_letters' => 'E', 'checked_baggage_kg' => 15, 'cabin_baggage_kg' => 8, 'change_fee_usd' => 90, 'refund_fee_usd' => 140, 'latest_refund_hours' => 24, 'latest_change_hours' => 12, 'price_offset' => 35, 'count_available' => fake()->numberBetween(0, 20)],
            ['class' => 'Economy Standard', 'class_letters' => 'M', 'checked_baggage_kg' => 20, 'cabin_baggage_kg' => 8, 'change_fee_usd' => 60, 'refund_fee_usd' => 100, 'latest_refund_hours' => 18, 'latest_change_hours' => 8, 'price_offset' => 95, 'count_available' => fake()->numberBetween(0, 18)],
            ['class' => 'Economy Full', 'class_letters' => 'YQ', 'checked_baggage_kg' => 23, 'cabin_baggage_kg' => 8, 'change_fee_usd' => 0, 'refund_fee_usd' => 25, 'latest_refund_hours' => 6, 'latest_change_hours' => 3, 'price_offset' => 170, 'count_available' => fake()->numberBetween(0, 14)],
            ['class' => 'Premium Economy', 'class_letters' => 'W', 'checked_baggage_kg' => 25, 'cabin_baggage_kg' => 8, 'change_fee_usd' => 25, 'refund_fee_usd' => 60, 'latest_refund_hours' => 12, 'latest_change_hours' => 6, 'price_offset' => 240, 'count_available' => fake()->numberBetween(0, 12)],
            ['class' => 'Business Saver', 'class_letters' => 'D', 'checked_baggage_kg' => 30, 'cabin_baggage_kg' => 12, 'change_fee_usd' => 50, 'refund_fee_usd' => 120, 'latest_refund_hours' => 12, 'latest_change_hours' => 6, 'price_offset' => 430, 'count_available' => fake()->numberBetween(0, 8)],
            ['class' => 'Business Full', 'class_letters' => 'J', 'checked_baggage_kg' => 32, 'cabin_baggage_kg' => 12, 'change_fee_usd' => 0, 'refund_fee_usd' => 0, 'latest_refund_hours' => 4, 'latest_change_hours' => 2, 'price_offset' => 680, 'count_available' => fake()->numberBetween(0, 6)],
        ])->map(function (array $availability) use ($basePriceUsd, $demandMultiplier, $fareType): array {
            $oneWayPriceUsd = (int) round(($basePriceUsd + $availability['price_offset']) * $demandMultiplier);

            if ($fareType === 'round_trip') {
                $availability['class'] .= ' Roundtrip';
                $availability['class_letters'] .= '(R)';
                $availability['fare_type'] = 'round_trip';
                $availability['base_price_usd'] = Flight::roundTripPrice($oneWayPriceUsd);
                $availability['count_available'] = max(0, (int) round($availability['count_available'] * 0.75));
            } else {
                $availability['fare_type'] = 'one_way';
                $availability['base_price_usd'] = $oneWayPriceUsd;
            }

            unset($availability['price_offset']);

            return $availability;
        });
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
