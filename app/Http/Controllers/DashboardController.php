<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user !== null, 403);

        $tickets = Ticket::query()
            ->select(['id', 'availability_id', 'pnr_id', 'flown', 'created_at'])
            ->with([
                'pnr:id,user_id,first_name,last_name,passport_number',
                'availability:id,flight_id,class,class_letters,fare_type,base_price_usd,checked_baggage_kg,cabin_baggage_kg,seat_selection_free',
                'availability.flight:id,date,hour,origin_airport_id,destination_airport_id,flight_number,plane_model',
                'availability.flight.originAirport:id,name,code',
                'availability.flight.destinationAirport:id,name,code',
            ])
            ->whereHas('pnr', fn (Builder $query) => $query->whereBelongsTo($user))
            ->latest()
            ->get()
            ->map(function (Ticket $ticket): array {
                $flight = $ticket->availability->flight;
                $departureAt = Carbon::parse($flight->date->toDateString().' '.$flight->hour);
                $state = $ticket->flown || $departureAt->isPast() ? 'past' : 'upcoming';

                return [
                    'id' => $ticket->id,
                    'state' => $state,
                    'pnr' => [
                        'first_name' => $ticket->pnr->first_name,
                        'last_name' => $ticket->pnr->last_name,
                        'passport_number' => $ticket->pnr->passport_number,
                    ],
                    'flight' => [
                        'flight_number' => $flight->flight_number,
                        'plane_model' => $flight->plane_model,
                        'depart_at' => $departureAt->toIso8601String(),
                        'depart_date' => $departureAt->format('M j, Y'),
                        'depart_time' => $departureAt->format('H:i'),
                        'origin' => [
                            'code' => $flight->originAirport->code,
                            'name' => $flight->originAirport->name,
                        ],
                        'destination' => [
                            'code' => $flight->destinationAirport->code,
                            'name' => $flight->destinationAirport->name,
                        ],
                    ],
                    'fare' => [
                        'class' => $ticket->availability->class,
                        'class_letters' => $ticket->availability->class_letters,
                        'fare_type' => $ticket->availability->fare_type,
                        'base_price_usd' => $ticket->availability->base_price_usd,
                        'checked_baggage_kg' => $ticket->availability->checked_baggage_kg,
                        'cabin_baggage_kg' => $ticket->availability->cabin_baggage_kg,
                        'seat_selection_free' => $ticket->availability->seat_selection_free,
                    ],
                    'flown' => $ticket->flown,
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
}
