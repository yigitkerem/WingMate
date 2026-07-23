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
 * @property Carbon $date
 * @property string $hour
 * @property int $origin_airport_id
 * @property int $destination_airport_id
 * @property string $flight_number
 * @property string $plane_model
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['date', 'hour', 'origin_airport_id', 'destination_airport_id', 'flight_number', 'plane_model'])]
class Flight extends Model
{
    /** @use HasFactory<FlightFactory> */
    use HasFactory;

    public static function defaultAvailabilityForClass(string $class, int $basePriceUsd, string $fareType = 'one_way'): array
    {
        $availability = match ($class) {
            'A' => [
                'class' => 'Economy Light',
                'checked_baggage_kg' => 15,
                'cabin_baggage_kg' => 8,
                'change_fee_usd' => $basePriceUsd,
                'refund_fee_usd' => $basePriceUsd,
                'latest_refund_hours' => null,
                'latest_change_hours' => null,
                'class_letters' => 'A',
                'base_price_usd' => $basePriceUsd,
                'count_available' => 18,
            ],
            'B' => [
                'class' => 'Economy Flex',
                'checked_baggage_kg' => 20,
                'cabin_baggage_kg' => 8,
                'change_fee_usd' => 30,
                'refund_fee_usd' => 50,
                'latest_refund_hours' => 12,
                'latest_change_hours' => 12,
                'class_letters' => 'B',
                'base_price_usd' => $basePriceUsd,
                'count_available' => 14,
            ],
            'C' => [
                'class' => 'Business',
                'checked_baggage_kg' => 25,
                'cabin_baggage_kg' => 8,
                'change_fee_usd' => 0,
                'refund_fee_usd' => 0,
                'latest_refund_hours' => 6,
                'latest_change_hours' => 6,
                'class_letters' => 'C',
                'base_price_usd' => $basePriceUsd,
                'count_available' => 8,
            ],
            default => throw new \InvalidArgumentException("Unsupported booking class [{$class}]."),
        };

        if ($fareType === 'round_trip') {
            $availability['class'] .= ' Roundtrip';
            $availability['class_letters'] = "{$class}(R)";
            $availability['fare_type'] = 'round_trip';
            $availability['base_price_usd'] = self::roundTripPrice((int) $availability['base_price_usd']);
        } else {
            $availability['fare_type'] = 'one_way';
        }

        return $availability;
    }

    /**
     * @return array<string, array<string, int|string|null>>
     */
    public static function defaultAvailabilityTemplates(int $aPriceUsd, int $bPriceUsd, int $cPriceUsd): array
    {
        return [
            'A' => self::defaultAvailabilityForClass('A', $aPriceUsd),
            'B' => self::defaultAvailabilityForClass('B', $bPriceUsd),
            'C' => self::defaultAvailabilityForClass('C', $cPriceUsd),
        ];
    }

    /**
     * @return array<string, array<string, int|string|null>>
     */
    public static function roundTripAvailabilityTemplates(int $aPriceUsd, int $bPriceUsd, int $cPriceUsd): array
    {
        return [
            'A' => self::defaultAvailabilityForClass('A', $aPriceUsd, 'round_trip'),
            'B' => self::defaultAvailabilityForClass('B', $bPriceUsd, 'round_trip'),
            'C' => self::defaultAvailabilityForClass('C', $cPriceUsd, 'round_trip'),
        ];
    }

    public static function roundTripPrice(int $oneWayPriceUsd): int
    {
        return (int) round($oneWayPriceUsd * 0.88);
    }

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    public function scopeForRouteOnDate(Builder $query, int $originAirportId, int $destinationAirportId, string $date): Builder
    {
        return $query
            ->where('origin_airport_id', $originAirportId)
            ->where('destination_airport_id', $destinationAirportId)
            ->whereDate('date', $date);
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
