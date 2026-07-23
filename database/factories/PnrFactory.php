<?php

namespace Database\Factories;

use App\Models\Pnr;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pnr>
 */
class PnrFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'passport_number' => strtoupper(fake()->bothify('??#######')),
        ];
    }
}
