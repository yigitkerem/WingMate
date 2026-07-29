<?php

namespace App\Actions;

use App\Models\Offer;
use App\Models\Order;
use App\Pricing\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseTickets
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    /**
     * @param  array{offer_ids: array<int, int>, user_id?: int|null, first_name: string, last_name: string, email?: string|null, passport_number?: string|null}  $data
     */
    public function execute(array $data): Order
    {
        return DB::transaction(function () use ($data): Order {
            $offers = Offer::query()
                ->whereKey($data['offer_ids'])
                ->lockForUpdate()
                ->with(['flight', 'bookingClass'])
                ->get();

            if ($offers->count() !== count(array_unique($data['offer_ids']))) {
                throw ValidationException::withMessages(['offer_ids' => 'One or more offers could not be found.']);
            }

            $this->validateOffers($offers);

            $offers->each(fn (Offer $offer): null => $this->inventoryService->reserve($offer));

            $order = Order::query()->create([
                'user_id' => $data['user_id'] ?? null,
                'booking_reference' => $this->bookingReference(),
                'status' => 'confirmed',
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
                'passport_number' => isset($data['passport_number']) ? strtoupper((string) $data['passport_number']) : null,
                'currency' => 'USD',
                'total_price' => $offers->sum(fn (Offer $offer): float => (float) $offer->total_price),
                'passengers' => [
                    'adults' => $offers->max('adults'),
                    'children' => $offers->max('children'),
                    'infants' => $offers->max('infants'),
                ],
            ]);

            $offers->each(fn (Offer $offer): null => $this->issueTickets($order, $offer));

            return $order->load('tickets.segments');
        }, attempts: 3);
    }

    /**
     * @param  Collection<int, Offer>  $offers
     */
    private function validateOffers(Collection $offers): void
    {
        if ($offers->contains(fn (Offer $offer): bool => $offer->expires_at->isPast())) {
            throw ValidationException::withMessages(['offer_ids' => 'This offer has expired. Please search again.']);
        }

        if ($offers->pluck('trip_type')->unique()->count() > 1) {
            throw ValidationException::withMessages(['offer_ids' => 'Offer trip types cannot be mixed.']);
        }

        $tripType = $offers->first()->trip_type;

        if ($tripType === 'one_way' && $offers->count() !== 1) {
            throw ValidationException::withMessages(['offer_ids' => 'Choose one offer for a one-way trip.']);
        }

        if ($tripType === 'round_trip' && $offers->pluck('leg_index')->sort()->values()->all() !== [1, 2]) {
            throw ValidationException::withMessages(['offer_ids' => 'Choose one outbound offer and one return offer.']);
        }
    }

    private function issueTickets(Order $order, Offer $offer): void
    {
        foreach ($this->passengerTypes($offer) as $passengerType) {
            $ticket = $order->tickets()->create([
                'offer_id' => $offer->id,
                'ticket_number' => '235'.now()->format('ymd').random_int(100000, 999999),
                'passenger_type' => $passengerType,
                'status' => 'issued',
                'issued_at' => now(),
            ]);

            $ticket->segments()->create([
                'flight_id' => $offer->flight_id,
                'booking_class_id' => $offer->booking_class_id,
                'coupon_status' => 'open',
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function passengerTypes(Offer $offer): array
    {
        return [
            ...array_fill(0, $offer->adults, 'ADT'),
            ...array_fill(0, $offer->children, 'CHD'),
            ...array_fill(0, $offer->infants, 'INF'),
        ];
    }

    private function bookingReference(): string
    {
        do {
            $reference = Str::upper(Str::random(6));
        } while (Order::query()->where('booking_reference', $reference)->exists());

        return $reference;
    }
}
