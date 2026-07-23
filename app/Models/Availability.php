<?php

namespace App\Models;

use Database\Factories\AvailabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $flight_id
 * @property string $class
 * @property int $checked_baggage_kg
 * @property int $cabin_baggage_kg
 * @property bool $seat_selection_free
 * @property int $change_fee_usd
 * @property int $refund_fee_usd
 * @property int|null $latest_refund_hours
 * @property int|null $latest_change_hours
 * @property string $class_letters
 * @property string $fare_type
 * @property int $base_price_usd
 * @property int $count_available
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'flight_id',
    'class',
    'checked_baggage_kg',
    'cabin_baggage_kg',
    'seat_selection_free',
    'change_fee_usd',
    'refund_fee_usd',
    'latest_refund_hours',
    'latest_change_hours',
    'class_letters',
    'fare_type',
    'base_price_usd',
    'count_available',
])]
class Availability extends Model
{
    /** @use HasFactory<AvailabilityFactory> */
    use HasFactory;

    protected $attributes = [
        'fare_type' => 'one_way',
        'seat_selection_free' => false,
    ];

    protected function casts(): array
    {
        return [
            'seat_selection_free' => 'boolean',
        ];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function scopeSeatsFor(Builder $query, int $seatPassengers): Builder
    {
        return $query->where('count_available', '>=', $seatPassengers);
    }
}
