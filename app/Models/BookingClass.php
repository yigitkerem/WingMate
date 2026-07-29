<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'cabin_id', 'priority'])]
class BookingClass extends Model
{
    /**
     * @return BelongsTo<Cabin, $this>
     */
    public function cabin(): BelongsTo
    {
        return $this->belongsTo(Cabin::class);
    }

    /**
     * @return HasMany<FlightInventory, $this>
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(FlightInventory::class);
    }

    /**
     * @return HasMany<BaseFare, $this>
     */
    public function baseFares(): HasMany
    {
        return $this->hasMany(BaseFare::class);
    }
}
