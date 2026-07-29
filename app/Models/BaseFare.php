<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'flight_id',
    'booking_class_id',
    'bundle_id',
    'trip_type',
    'leg_index',
    'currency',
    'base_price',
    'taxes',
    'fees',
    'fare_basis_template',
    'class_letters',
    'valid_from',
    'valid_until',
    'active',
])]
class BaseFare extends Model
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
     * @param  Builder<BaseFare>  $query
     * @return Builder<BaseFare>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn (Builder $query): Builder => $query->whereNull('valid_until')->orWhere('valid_until', '>=', now()));
    }

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'taxes' => 'decimal:2',
            'fees' => 'decimal:2',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'active' => 'boolean',
        ];
    }
}
