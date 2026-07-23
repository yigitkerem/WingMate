<?php

namespace Database\Factories;

use App\Models\Availability;
use App\Models\Flight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Availability>
 */
class AvailabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $classLetter = fake()->randomElement(['A', 'B', 'C', 'D', 'E', 'F', 'J', 'K', 'L', 'M', 'Q', 'S', 'Y']);

        return [
            'flight_id' => Flight::factory(),
            'class' => fake()->randomElement(['Economy Light', 'Economy Standard', 'Economy Flex', 'Premium Economy', 'Business']),
            'checked_baggage_kg' => fake()->randomElement([0, 15, 20, 23, 25, 30]),
            'cabin_baggage_kg' => 8,
            'change_fee_usd' => fake()->randomElement([0, 30, 50, 80, 120]),
            'refund_fee_usd' => fake()->randomElement([0, 50, 90, 150, 220]),
            'latest_refund_hours' => fake()->optional(0.8)->randomElement([6, 12, 24]),
            'latest_change_hours' => fake()->optional(0.9)->randomElement([6, 12, 24]),
            'class_letters' => $classLetter,
            'fare_type' => 'one_way',
            'base_price_usd' => fake()->numberBetween(90, 980),
            'count_available' => fake()->numberBetween(0, 24),
        ];
    }
}
