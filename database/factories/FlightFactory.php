<?php

namespace Database\Factories;

use App\Models\Airport;
use App\Models\Flight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flight>
 */
class FlightFactory extends Factory
{
    public function definition(): array
    {
        $departureAt = now()->addDays(fake()->numberBetween(1, 30))->setTime(fake()->numberBetween(0, 22), fake()->randomElement([0, 10, 20, 30, 40, 50]));
        $durationMinutes = fake()->numberBetween(90, 520);

        return [
            'flight_number' => 'TK'.fake()->unique()->numberBetween(100, 9999),
            'origin_airport_id' => Airport::factory(),
            'destination_airport_id' => Airport::factory(),
            'departure_at' => $departureAt,
            'arrival_at' => $departureAt->copy()->addMinutes($durationMinutes),
            'duration_minutes' => $durationMinutes,
            'aircraft_type' => fake()->randomElement(['Airbus A321neo', 'Airbus A330-300', 'Airbus A350-900', 'Boeing 787-9']),
            'status' => 'scheduled',
        ];
    }
}
