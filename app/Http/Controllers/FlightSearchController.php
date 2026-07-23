<?php

namespace App\Http\Controllers;

use App\Actions\SearchFlights;
use App\Http\Requests\SearchFlightsRequest;
use App\Models\Airport;
use Inertia\Inertia;
use Inertia\Response;

class FlightSearchController extends Controller
{
    public function __invoke(SearchFlightsRequest $request, SearchFlights $searchFlights): Response
    {
        $validated = $request->validated();
        $seatPassengers = ($validated['adults'] ?? 1) + ($validated['children'] ?? 0);

        return Inertia::render('flight-search', [
            'airports' => Airport::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('code')
                ->get(),
            'filters' => [
                'origin_airport_id' => $validated['origin_airport_id'] ?? null,
                'destination_airport_id' => $validated['destination_airport_id'] ?? null,
                'trip_type' => $validated['trip_type'] ?? 'one_way',
                'depart_date' => $validated['depart_date'] ?? now()->toDateString(),
                'return_date' => $validated['return_date'] ?? now()->addDays(7)->toDateString(),
                'search_mode' => $validated['search_mode'] ?? 'basic',
                'adults' => $validated['adults'] ?? 1,
                'children' => $validated['children'] ?? 0,
                'babies' => $validated['babies'] ?? 0,
            ],
            'results' => $request->isSearchAttempt() ? [
                'seat_passengers' => $seatPassengers,
                'outbound' => $searchFlights->execute(
                    $validated['origin_airport_id'],
                    $validated['destination_airport_id'],
                    $validated['depart_date'],
                    $validated['search_mode'],
                    $seatPassengers,
                    $validated['trip_type'],
                ),
                'return' => $validated['trip_type'] === 'round_trip'
                    ? $searchFlights->execute(
                        $validated['destination_airport_id'],
                        $validated['origin_airport_id'],
                        $validated['return_date'],
                        $validated['search_mode'],
                        $seatPassengers,
                        $validated['trip_type'],
                    )
                    : [],
            ] : null,
        ]);
    }
}
