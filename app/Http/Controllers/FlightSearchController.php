<?php

namespace App\Http\Controllers;

use App\Actions\SearchFlights;
use App\Http\Requests\SearchFlightsRequest;
use App\Models\Airport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
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

    public function search(SearchFlightsRequest $request, SearchFlights $searchFlights): Response|RedirectResponse
    {
        if (! $request->isSearchAttempt()) {
            return to_route('home');
        }

        $validated = $request->validated();
        $filters = [
            'origin_airport_id' => (int) $validated['origin_airport_id'],
            'destination_airport_id' => (int) $validated['destination_airport_id'],
            'trip_type' => $validated['trip_type'],
            'depart_date' => $validated['depart_date'],
            'return_date' => $validated['return_date'] ?? null,
            'search_mode' => $request->user()?->is_admin ? $validated['search_mode'] : 'basic',
            'adults' => (int) $validated['adults'],
            'children' => (int) $validated['children'],
            'babies' => (int) $validated['babies'],
        ];
        $seatPassengers = $filters['adults'] + $filters['children'];

        return $this->renderSearchPage($filters, [
            'seat_passengers' => $seatPassengers,
            'outbound' => $searchFlights->execute(
                $filters['origin_airport_id'],
                $filters['destination_airport_id'],
                $filters['depart_date'],
                $filters['search_mode'],
                $seatPassengers,
                $filters['trip_type'],
                $filters['adults'],
                $filters['children'],
                $filters['babies'],
                $request->user(),
                1,
            ),
            'return' => $filters['trip_type'] === 'round_trip'
                ? $searchFlights->execute(
                    $filters['destination_airport_id'],
                    $filters['origin_airport_id'],
                    $filters['return_date'],
                    $filters['search_mode'],
                    $seatPassengers,
                    $filters['trip_type'],
                    $filters['adults'],
                    $filters['children'],
                    $filters['babies'],
                    $request->user(),
                    2,
                )
                : [],
        ], 'flight-results');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $results
     */
    private function renderSearchPage(array $filters, ?array $results = null, string $component = 'flight-search'): Response
    {
        $user = request()->user();
        $customer = $user instanceof User ? $user : null;
        $nameParts = $customer instanceof User ? preg_split('/\s+/', trim($customer->name), 2) : [];

        return Inertia::render($component, [
            'airports' => Airport::query()
                ->select(['id', 'name', 'iata_code as code'])
                ->orderBy('iata_code')
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
            'canUseFullSearch' => $customer instanceof User ? $customer->is_admin : false,
            'customer' => [
                'isAuthenticated' => $customer !== null,
                'isAdmin' => $customer instanceof User ? $customer->is_admin : false,
                'firstName' => $nameParts[0] ?? '',
                'lastName' => $nameParts[1] ?? '',
                'email' => $customer instanceof User ? $customer->email : '',
                'passportNumber' => $customer instanceof User ? ($customer->passport_number ?? '') : '',
            ],
            'results' => $results,
        ]);
    }
}
