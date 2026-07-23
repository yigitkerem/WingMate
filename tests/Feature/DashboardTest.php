<?php

use App\Models\Airport;
use App\Models\Availability;
use App\Models\Flight;
use App\Models\Pnr;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('ticketStats.total', 0)
            ->has('tickets.upcoming', 0)
            ->has('tickets.past', 0),
        );
});

test('dashboard shows upcoming and past tickets for the signed in customer', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    dashboardTicketForUser($user, [
        'date' => now()->addDays(5)->toDateString(),
        'hour' => '10:20',
        'flight_number' => 'DP321',
        'flown' => false,
    ]);

    dashboardTicketForUser($user, [
        'date' => now()->subDays(3)->toDateString(),
        'hour' => '18:45',
        'flight_number' => 'DP654',
        'flown' => true,
    ]);

    dashboardTicketForUser($otherUser, [
        'date' => now()->addDays(7)->toDateString(),
        'hour' => '07:00',
        'flight_number' => 'DP999',
        'flown' => false,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('ticketStats.total', 2)
            ->where('ticketStats.upcoming', 1)
            ->where('ticketStats.past', 1)
            ->where('tickets.upcoming.0.flight.flight_number', 'DP321')
            ->where('tickets.past.0.flight.flight_number', 'DP654')
            ->missing('tickets.upcoming.1'),
        );
});

/**
 * @param  array{date: string, hour: string, flight_number: string, flown: bool}  $flightState
 */
function dashboardTicketForUser(User $user, array $flightState): Ticket
{
    $origin = Airport::factory()->create();
    $destination = Airport::factory()->create();
    $flight = Flight::factory()->create([
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'date' => $flightState['date'],
        'hour' => $flightState['hour'],
        'flight_number' => $flightState['flight_number'],
    ]);
    $availability = Availability::factory()->create(['flight_id' => $flight->id]);
    $pnr = Pnr::factory()->create(['user_id' => $user->id]);

    return Ticket::factory()->create([
        'availability_id' => $availability->id,
        'pnr_id' => $pnr->id,
        'flown' => $flightState['flown'],
    ]);
}
