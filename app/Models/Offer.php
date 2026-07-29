<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $flight_id
 * @property int $booking_class_id
 * @property int $bundle_id
 * @property int $base_fare_id
 * @property string $trip_type
 * @property int $leg_index
 * @property string $currency
 * @property string $total_price
 * @property string $class_letters
 * @property int $adults
 * @property int $children
 * @property int $infants
 * @property Carbon $expires_at
 */
#[Fillable([
    'uuid',
    'user_id',
    'flight_id',
    'booking_class_id',
    'bundle_id',
    'base_fare_id',
    'trip_type',
    'leg_index',
    'currency',
    'base_price',
    'taxes',
    'fees',
    'services_total',
    'discount',
    'total_price',
    'fare_basis_code',
    'class_letters',
    'adults',
    'children',
    'infants',
    'context',
    'expires_at',
])]
class Offer extends Model
{
    /**
     * @return BelongsTo<Flight, $this>
     */
    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    /**
     * @return BelongsTo<BookingClass, $this>
     */
    public function bookingClass(): BelongsTo
    {
        return $this->belongsTo(BookingClass::class);
    }

    /**
     * @return BelongsTo<Bundle, $this>
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    /**
     * @return BelongsTo<BaseFare, $this>
     */
    public function baseFare(): BelongsTo
    {
        return $this->belongsTo(BaseFare::class);
    }

    /**
     * @return HasMany<OfferService, $this>
     */
    public function offerServices(): HasMany
    {
        return $this->hasMany(OfferService::class);
    }

    /**
     * @return HasMany<PriceComponent, $this>
     */
    public function priceComponents(): HasMany
    {
        return $this->hasMany(PriceComponent::class);
    }

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'taxes' => 'decimal:2',
            'fees' => 'decimal:2',
            'services_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_price' => 'decimal:2',
            'context' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
