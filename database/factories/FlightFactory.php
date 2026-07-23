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
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-2 days', '+45 days');

        return [
            'date' => $date->format('Y-m-d'),
            'hour' => fake()->randomElement(['06:15', '08:40', '10:25', '13:10', '15:45', '18:20', '21:05']),
            'origin_airport_id' => Airport::factory(),
            'destination_airport_id' => Airport::factory(),
            'flight_number' => 'DP'.fake()->numberBetween(100, 999),
            'plane_model' => fake()->randomElement([
                'Airbus A320neo',
                'Airbus A321neo',
                'Boeing 737 MAX 8',
                'Boeing 787-9',
            ]),
        ];
    }
}
