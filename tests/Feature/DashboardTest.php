<?php

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Pricing\OfferBuilder;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('ticketStats.total', 0)
            ->has('tickets.upcoming', 0)
            ->has('tickets.past', 0),
        );
});

test('admin dashboard does not show default panel widgets', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertDontSee('Welcome')
        ->assertDontSee('Documentation')
        ->assertDontSee('GitHub');
});

test('dashboard shows upcoming and past tickets for the signed in customer', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    dashboardTicketForUser($user, 'TK321', '2026-08-10 10:20', 'issued');
    dashboardTicketForUser($user, 'TK654', now()->subDays(3)->format('Y-m-d H:i'), 'flown');
    dashboardTicketForUser($otherUser, 'TK999', '2026-08-12 07:00', 'issued');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('ticketStats.total', 2)
            ->where('ticketStats.upcoming', 1)
            ->where('ticketStats.past', 1)
            ->where('tickets.upcoming.0.flight.flight_number', 'TK321')
            ->where('tickets.past.0.flight.flight_number', 'TK654'),
        );
});

function dashboardTicketForUser(User $user, string $flightNumber, string $departureAt, string $status): Ticket
{
    $fixture = createSellablePricingFixture([
        'flight_number' => $flightNumber,
        'departure_at' => $departureAt,
    ]);
    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0, $user);
    $order = Order::query()->create([
        'user_id' => $user->id,
        'booking_reference' => str_replace('TK', 'BR', $flightNumber),
        'status' => $status === 'flown' ? 'flown' : 'confirmed',
        'first_name' => 'Demo',
        'last_name' => 'Passenger',
        'passport_number' => $user->passport_number,
        'total_price' => $offer->total_price,
    ]);

    return Ticket::query()->create([
        'order_id' => $order->id,
        'offer_id' => $offer->id,
        'ticket_number' => '235'.$flightNumber,
        'passenger_type' => 'ADT',
        'status' => $status,
        'issued_at' => now(),
    ]);
}
