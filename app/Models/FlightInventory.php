<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['flight_id', 'booking_class_id', 'capacity', 'available'])]
class FlightInventory extends Model
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

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'available' => 'integer',
        ];
    }
}
