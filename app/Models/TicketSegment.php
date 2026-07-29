<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_id', 'flight_id', 'booking_class_id', 'coupon_status'])]
class TicketSegment extends Model
{
    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

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
}
