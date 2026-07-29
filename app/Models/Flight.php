<?php

namespace App\Models;

use Database\Factories\FlightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $flight_number
 * @property int $origin_airport_id
 * @property int $destination_airport_id
 * @property Carbon $departure_at
 * @property Carbon $arrival_at
 * @property int $duration_minutes
 * @property string $aircraft_type
 * @property string $status
 */
#[Fillable([
    'flight_number',
    'origin_airport_id',
    'destination_airport_id',
    'departure_at',
    'arrival_at',
    'duration_minutes',
    'aircraft_type',
    'status',
])]
class Flight extends Model
{
    /** @use HasFactory<FlightFactory> */
    use HasFactory;

    public static function formatDuration(int $durationMinutes): string
    {
        $hours = intdiv($durationMinutes, 60);
        $minutes = $durationMinutes % 60;

        if ($hours === 0) {
            return "{$minutes}m";
        }

        return $minutes === 0 ? "{$hours}h" : "{$hours}h {$minutes}m";
    }

    /**
     * @return BelongsTo<Airport, $this>
     */
    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    /**
     * @return BelongsTo<Airport, $this>
     */
    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    /**
     * @return HasMany<BaseFare, $this>
     */
    public function baseFares(): HasMany
    {
        return $this->hasMany(BaseFare::class);
    }

    /**
     * @return HasMany<FlightInventory, $this>
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(FlightInventory::class);
    }

    /**
     * @param  Builder<Flight>  $query
     * @return Builder<Flight>
     */
    public function scopeForRouteOnDate(Builder $query, int $originAirportId, int $destinationAirportId, string $date): Builder
    {
        return $query
            ->where('origin_airport_id', $originAirportId)
            ->where('destination_airport_id', $destinationAirportId)
            ->whereDate('departure_at', $date);
    }

    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'arrival_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }
}
