<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'offer_id', 'ticket_number', 'passenger_type', 'status', 'issued_at'])]
class Ticket extends Model
{
    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return HasMany<TicketSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(TicketSegment::class);
    }

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }
}
