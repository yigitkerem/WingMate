<?php

namespace App\Http\Controllers;

use App\Actions\SearchFlights;
use App\Http\Requests\SearchFlightsRequest;
use App\Models\Airport;
use Inertia\Inertia;
use Inertia\Response;

class FlightSearchController extends Controller
{
    public function index(): Response
    {
        return $this->renderSearchPage([
            'origin_airport_id' => null,
            'destination_airport_id' => null,
            'trip_type' => 'one_way',
            'depart_date' => now()->toDateString(),
            'return_date' => now()->addDays(7)->toDateString(),
            'search_mode' => 'basic',
            'adults' => 1,
            'children' => 0,
            'babies' => 0,
        ]);
    }

    public function search(SearchFlightsRequest $request, SearchFlights $searchFlights): Response
    {
        $validated = $request->validated();
        $validated['search_mode'] = $request->user()?->is_admin ? $validated['search_mode'] : 'basic';
        $seatPassengers = ($validated['adults'] ?? 1) + ($validated['children'] ?? 0);

        return $this->renderSearchPage($validated, [
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
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $results
     */
    private function renderSearchPage(array $filters, ?array $results = null): Response
    {
        $user = request()->user();
        $nameParts = $user ? preg_split('/\s+/', trim($user->name), 2) : [];

        return Inertia::render('flight-search', [
            'airports' => Airport::query()
                ->select(['id', 'name', 'code'])
                ->orderBy('code')
                ->get(),
            'filters' => [
                'origin_airport_id' => $filters['origin_airport_id'] ?? null,
                'destination_airport_id' => $filters['destination_airport_id'] ?? null,
                'trip_type' => $filters['trip_type'] ?? 'one_way',
                'depart_date' => $filters['depart_date'] ?? now()->toDateString(),
                'return_date' => $filters['return_date'] ?? now()->addDays(7)->toDateString(),
                'search_mode' => $filters['search_mode'] ?? 'basic',
                'adults' => $filters['adults'] ?? 1,
                'children' => $filters['children'] ?? 0,
                'babies' => $filters['babies'] ?? 0,
            ],
            'canUseFullSearch' => request()->user()?->is_admin ?? false,
            'customer' => [
                'isAuthenticated' => $user !== null,
                'isAdmin' => $user?->is_admin ?? false,
                'firstName' => $nameParts[0] ?? '',
                'lastName' => $nameParts[1] ?? '',
            ],
            'results' => $results,
        ]);
    }
}
