<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'display_order'])]
class Cabin extends Model
{
    /**
     * @return HasMany<BookingClass, $this>
     */
    public function bookingClasses(): HasMany
    {
        return $this->hasMany(BookingClass::class);
    }
}
