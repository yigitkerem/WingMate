<?php

namespace App\Http\Controllers;

use App\Models\Flight;
use App\Models\Offer;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user !== null, 403);

        $tickets = Ticket::query()
            ->with([
                'order:id,user_id,booking_reference,first_name,last_name,passport_number',
                'offer.bundle:id,name,code',
                'offer.offerServices.service:id,code',
                'offer.flight.originAirport:id,name,iata_code',
                'offer.flight.destinationAirport:id,name,iata_code',
            ])
            ->whereHas('order', fn ($query) => $query->whereBelongsTo($user))
            ->latest()
            ->get()
            ->map(function (Ticket $ticket): array {
                $offer = $ticket->offer;
                $flight = $offer->flight;
                $departureAt = $flight->departure_at;
                $arrivalAt = $flight->arrival_at;
                $state = $ticket->status === 'flown' || $departureAt->isPast() ? 'past' : 'upcoming';

                return [
                    'id' => $ticket->id,
                    'state' => $state,
                    'pnr' => [
                        'first_name' => $ticket->order->first_name,
                        'last_name' => $ticket->order->last_name,
                        'passport_number' => $ticket->order->passport_number,
                        'booking_reference' => $ticket->order->booking_reference,
                    ],
                    'flight' => [
                        'flight_number' => $flight->flight_number,
                        'plane_model' => $flight->aircraft_type,
                        'depart_at' => $departureAt->toIso8601String(),
                        'depart_date' => $departureAt->format('M j, Y'),
                        'depart_time' => $departureAt->format('H:i'),
                        'arrival_time' => $arrivalAt->format('H:i'),
                        'duration' => Flight::formatDuration($flight->duration_minutes),
                        'origin' => [
                            'code' => $flight->originAirport->iata_code,
                            'name' => $flight->originAirport->name,
                        ],
                        'destination' => [
                            'code' => $flight->destinationAirport->iata_code,
                            'name' => $flight->destinationAirport->name,
                        ],
                    ],
                    'fare' => [
                        'class' => $offer->bundle->name,
                        'class_letters' => $offer->class_letters,
                        'fare_type' => $offer->trip_type,
                        'base_price_usd' => (float) $offer->total_price,
                        'checked_baggage_kg' => $this->serviceAmount($offer, 'CHECKED_BAG'),
                        'cabin_baggage_kg' => $this->serviceAmount($offer, 'CABIN_BAG'),
                        'seat_selection_free' => (bool) $this->serviceAmount($offer, 'SEAT_SELECTION'),
                    ],
                    'flown' => $ticket->status === 'flown',
                ];
            });

        $upcomingTickets = $tickets
            ->where('state', 'upcoming')
            ->sortBy('flight.depart_at')
            ->values();

        $pastTickets = $tickets
            ->where('state', 'past')
            ->sortByDesc('flight.depart_at')
            ->values();

        return Inertia::render('dashboard', [
            'tickets' => [
                'upcoming' => $upcomingTickets,
                'past' => $pastTickets,
            ],
            'ticketStats' => [
                'upcoming' => $upcomingTickets->count(),
                'past' => $pastTickets->count(),
                'total' => $tickets->count(),
            ],
        ]);
    }

    private function serviceAmount(Offer $offer, string $code): mixed
    {
        $service = $offer->offerServices->first(fn ($offerService): bool => $offerService->service->code === $code);
        $value = $service?->value;

        return is_array($value) ? ($value['amount'] ?? null) : $value;
    }
}
