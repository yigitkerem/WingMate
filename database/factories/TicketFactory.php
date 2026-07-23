<?php

namespace Database\Factories;

use App\Models\Availability;
use App\Models\Pnr;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'availability_id' => Availability::factory(),
            'pnr_id' => Pnr::factory(),
            'flown' => fake()->boolean(20),
        ];
    }
}
