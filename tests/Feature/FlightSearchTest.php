<?php

use App\Models\Airport;
use App\Models\Availability;
use App\Models\Flight;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('flight search page is displayed', function () {
    Airport::factory()->create(['name' => 'Istanbul Airport', 'code' => 'IST']);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flight-search')
            ->has('airports', 1)
            ->where('results', null),
        );
});

test('basic search returns cheapest available A B C fares or not available', function () {
    [$origin, $destination] = createAirportPair();
    $flight = Flight::factory()->create([
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'date' => '2026-07-28',
        'hour' => '09:30',
        'flight_number' => 'DP100',
    ]);

    Availability::factory()->create([
        'flight_id' => $flight->id,
        'class' => 'Economy Light',
        'class_letters' => 'A',
        'base_price_usd' => 120,
        'count_available' => 3,
    ]);
    Availability::factory()->create([
        'flight_id' => $flight->id,
        'class' => 'Economy Flex',
        'class_letters' => 'B',
        'base_price_usd' => 190,
        'count_available' => 1,
    ]);

    $this->post(route('flight-search.search'), [
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'trip_type' => 'one_way',
        'depart_date' => '2026-07-28',
        'search_mode' => 'basic',
        'adults' => 2,
        'children' => 0,
        'babies' => 1,
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flight-search')
            ->where('results.seat_passengers', 2)
            ->where('results.outbound.0.flight_number', 'DP100')
            ->where('results.outbound.0.fares.A.available', true)
            ->where('results.outbound.0.fares.A.base_price_usd', 120)
            ->where('results.outbound.0.fares.B.available', false)
            ->where('results.outbound.0.fares.C.available', false),
        );
});

test('full search shows every fare class including sold out classes', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    [$origin, $destination] = createAirportPair();
    $flight = Flight::factory()->create([
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'date' => '2026-07-29',
        'hour' => '14:10',
        'flight_number' => 'DP200',
    ]);

    Availability::factory()->create([
        'flight_id' => $flight->id,
        'class' => 'Economy Light',
        'class_letters' => 'A',
        'base_price_usd' => 110,
        'count_available' => 0,
    ]);
    Availability::factory()->create([
        'flight_id' => $flight->id,
        'class' => 'Business Full',
        'class_letters' => 'J',
        'base_price_usd' => 690,
        'count_available' => 5,
    ]);

    $this->actingAs($admin)->post(route('flight-search.search'), [
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'trip_type' => 'one_way',
        'depart_date' => '2026-07-29',
        'search_mode' => 'full',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flight-search')
            ->has('results.outbound.0.fares', 2)
            ->where('results.outbound.0.fares.0.class_letters', 'A')
            ->where('results.outbound.0.fares.0.available', false)
            ->where('results.outbound.0.fares.1.class_letters', 'J')
            ->where('results.outbound.0.fares.1.available', true),
        );
});

test('round trip search returns outbound and return legs', function () {
    [$origin, $destination] = createAirportPair();

    Flight::factory()->create([
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'date' => '2026-07-30',
        'flight_number' => 'DP300',
    ]);
    Flight::factory()->create([
        'origin_airport_id' => $destination->id,
        'destination_airport_id' => $origin->id,
        'date' => '2026-08-04',
        'flight_number' => 'DP301',
    ]);

    $this->post(route('flight-search.search'), [
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'trip_type' => 'round_trip',
        'depart_date' => '2026-07-30',
        'return_date' => '2026-08-04',
        'search_mode' => 'basic',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.outbound.0.flight_number', 'DP300')
            ->where('results.return.0.flight_number', 'DP301'),
        );
});

test('round trip search uses discounted round trip booking letters', function () {
    [$origin, $destination] = createAirportPair();
    $flight = Flight::factory()->create([
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'date' => '2026-07-31',
        'flight_number' => 'DP400',
    ]);

    Availability::factory()->create([
        'flight_id' => $flight->id,
        'class' => 'Economy Light',
        'class_letters' => 'A',
        'fare_type' => 'one_way',
        'base_price_usd' => 100,
        'count_available' => 4,
    ]);
    Availability::factory()->create([
        'flight_id' => $flight->id,
        'class' => 'Economy Light Roundtrip',
        'class_letters' => 'A(R)',
        'fare_type' => 'round_trip',
        'base_price_usd' => 88,
        'count_available' => 4,
    ]);

    $this->post(route('flight-search.search'), [
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'trip_type' => 'round_trip',
        'depart_date' => '2026-07-31',
        'return_date' => '2026-08-05',
        'search_mode' => 'basic',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.outbound.0.fares.A.available', true)
            ->where('results.outbound.0.fares.A.class_letters', 'A(R)')
            ->where('results.outbound.0.fares.A.fare_type', 'round_trip')
            ->where('results.outbound.0.fares.A.base_price_usd', 88),
        );
});

test('origin and destination must be different', function () {
    $airport = Airport::factory()->create(['code' => 'IST']);

    $this->from(route('home'))->post(route('flight-search.search'), [
        'origin_airport_id' => $airport->id,
        'destination_airport_id' => $airport->id,
        'trip_type' => 'one_way',
        'depart_date' => '2026-07-30',
        'search_mode' => 'basic',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ])
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors('destination_airport_id');
});

test('non admins cannot use full search mode', function () {
    [$origin, $destination] = createAirportPair();
    $flight = Flight::factory()->create([
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'date' => '2026-08-01',
        'flight_number' => 'DP500',
    ]);

    Availability::factory()->create([
        'flight_id' => $flight->id,
        'class_letters' => 'A',
        'fare_type' => 'one_way',
        'base_price_usd' => 120,
        'count_available' => 4,
    ]);

    $this->post(route('flight-search.search'), [
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'trip_type' => 'one_way',
        'depart_date' => '2026-08-01',
        'search_mode' => 'full',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canUseFullSearch', false)
            ->where('filters.search_mode', 'basic')
            ->where('results.outbound.0.fares.A.available', true),
        );
});

/**
 * @return array{Airport, Airport}
 */
function createAirportPair(): array
{
    return [
        Airport::factory()->create(['name' => 'Istanbul Airport', 'code' => 'IST']),
        Airport::factory()->create(['name' => 'London Heathrow Airport', 'code' => 'LHR']),
    ];
}
