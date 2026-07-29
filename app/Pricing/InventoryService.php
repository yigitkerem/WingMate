<?php

namespace App\Pricing;

use App\Models\FlightInventory;
use App\Models\Offer;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function reserve(Offer $offer): void
    {
        $seats = $offer->adults + $offer->children;

        if ($seats === 0) {
            return;
        }

        $inventory = FlightInventory::query()
            ->where('flight_id', $offer->flight_id)
            ->where('booking_class_id', $offer->booking_class_id)
            ->lockForUpdate()
            ->first();

        if (! $inventory instanceof FlightInventory || $inventory->available < $seats) {
            throw ValidationException::withMessages([
                'offer_ids' => 'This offer no longer has enough seats.',
            ]);
        }

        $inventory->decrement('available', $seats);
    }
}
