<?php

namespace Database\Factories;

use App\Models\Airport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Airport>
 */
class AirportFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->unique()->lexify('???');

        return [
            'iata_code' => strtoupper($code),
            'icao_code' => strtoupper('L'.$code),
            'name' => fake()->city().' Airport',
            'city' => fake()->city(),
            'country' => fake()->countryCode(),
            'timezone' => fake()->timezone(),
        ];
    }
}
