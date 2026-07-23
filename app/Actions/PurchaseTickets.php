<?php

namespace App\Actions;

use App\Models\Availability;
use App\Models\Pnr;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseTickets
{
    /**
     * @param  array{availability_id: int, user_id?: int|null, first_name: string, last_name: string, passport_number: string, adults: int, children: int, babies: int}  $data
     */
    public function execute(array $data): Pnr
    {
        return DB::transaction(function () use ($data): Pnr {
            $seatPassengers = $data['adults'] + $data['children'];

            /** @var Availability $availability */
            $availability = Availability::query()
                ->whereKey($data['availability_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($availability->count_available < $seatPassengers) {
                throw ValidationException::withMessages([
                    'availability_id' => 'This fare no longer has enough seats.',
                ]);
            }

            $pnr = Pnr::query()->create([
                'user_id' => $data['user_id'] ?? null,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'passport_number' => strtoupper($data['passport_number']),
            ]);

            foreach (range(1, $seatPassengers) as $_) {
                Ticket::query()->create([
                    'availability_id' => $availability->id,
                    'pnr_id' => $pnr->id,
                    'flown' => false,
                ]);
            }

            $availability->decrement('count_available', $seatPassengers);

            return $pnr;
        }, attempts: 3);
    }
}
