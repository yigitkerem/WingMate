<?php

namespace App\Models;

use Database\Factories\AirportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['iata_code', 'icao_code', 'name', 'city', 'country', 'timezone'])]
class Airport extends Model
{
    /** @use HasFactory<AirportFactory> */
    use HasFactory;

    /**
     * @return HasMany<Flight, $this>
     */
    public function originFlights(): HasMany
    {
        return $this->hasMany(Flight::class, 'origin_airport_id');
    }

    /**
     * @return HasMany<Flight, $this>
     */
    public function destinationFlights(): HasMany
    {
        return $this->hasMany(Flight::class, 'destination_airport_id');
    }
}
