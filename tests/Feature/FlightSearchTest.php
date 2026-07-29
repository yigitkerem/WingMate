<?php

use App\Models\Airport;
use App\Models\Offer;
use App\Models\User;
use Database\Seeders\AirlineDemoSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('flight search page is displayed', function () {
    createSellablePricingFixture();

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flight-search')
            ->has('airports', 2)
            ->where('results', null),
        );
});

test('basic search creates immutable public package offers', function () {
    $fixture = createSellablePricingFixture();

    $this->post(route('flight-search.search'), [
        'origin_airport_id' => $fixture['origin']->id,
        'destination_airport_id' => $fixture['destination']->id,
        'trip_type' => 'one_way',
        'depart_date' => '2026-08-10',
        'search_mode' => 'basic',
        'adults' => 1,
        'children' => 1,
        'babies' => 0,
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flight-results')
            ->where('results.seat_passengers', 2)
            ->where('results.outbound.0.flight_number', 'TK100')
            ->where('results.outbound.0.fares.0.class', 'ExtraFly')
            ->where('results.outbound.0.fares.0.available', true)
            ->where('results.outbound.0.fares.0.checked_baggage_kg', 23),
        );

    expect(Offer::query()->count())->toBe(1)
        ->and(Offer::query()->first()->total_price)->toEqual('225.00');
});

test('round trip search creates separate outbound and return leg offers', function () {
    $outbound = createSellablePricingFixture(['trip_type' => 'round_trip', 'leg_index' => 1, 'class_letters' => 'Q(R1)']);
    $return = createSellablePricingFixture([
        'origin' => 'LHR',
        'destination' => 'IST',
        'flight_number' => 'TK101',
        'departure_at' => '2026-08-15 11:00',
        'trip_type' => 'round_trip',
        'leg_index' => 2,
        'class_letters' => 'Q(R2)',
    ]);

    $this->actingAs(User::factory()->create())->post(route('flight-search.search'), [
        'origin_airport_id' => $outbound['origin']->id,
        'destination_airport_id' => $outbound['destination']->id,
        'trip_type' => 'round_trip',
        'depart_date' => '2026-08-10',
        'return_date' => '2026-08-15',
        'search_mode' => 'basic',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.outbound.0.fares.0.leg_index', 1)
            ->where('results.outbound.0.fares.0.class_letters', 'Q(R1)')
            ->where('results.return.0.fares.0.leg_index', 2)
            ->where('results.return.0.fares.0.class_letters', 'Q(R2)'),
        );

    expect($return['flight']->flight_number)->toBe('TK101')
        ->and(Offer::query()->count())->toBe(2);
});

test('seeded no change and no refund fares expose unavailable flexibility windows', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');

    try {
        $this->seed(AirlineDemoSeeder::class);

        $origin = Airport::query()->where('iata_code', 'IST')->firstOrFail();
        $destination = Airport::query()->where('iata_code', 'LHR')->firstOrFail();

        $this->post(route('flight-search.search'), [
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'trip_type' => 'one_way',
            'depart_date' => '2026-07-29',
            'search_mode' => 'basic',
            'adults' => 1,
            'children' => 0,
            'babies' => 0,
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.outbound.0.fares.0.package_code', 'ECOFLY')
                ->where('results.outbound.0.fares.0.latest_change_hours', null)
                ->where('results.outbound.0.fares.0.change_fee_usd', 0)
                ->where('results.outbound.0.fares.0.latest_refund_hours', null)
                ->where('results.outbound.0.fares.0.refund_fee_usd', 0)
                ->where('results.outbound.0.fares.1.package_code', 'EXTRAFLY')
                ->where('results.outbound.0.fares.1.latest_change_hours', 24)
                ->where('results.outbound.0.fares.1.change_fee_usd', 55)
                ->where('results.outbound.0.fares.1.latest_refund_hours', null)
                ->where('results.outbound.0.fares.1.refund_fee_usd', 0),
            );
    } finally {
        Carbon::setTestNow();
    }
});

test('origin and destination must be different', function () {
    $fixture = createSellablePricingFixture();

    $this->from(route('home'))->post(route('flight-search.search'), [
        'origin_airport_id' => $fixture['origin']->id,
        'destination_airport_id' => $fixture['origin']->id,
        'trip_type' => 'one_way',
        'depart_date' => '2026-08-10',
        'search_mode' => 'basic',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ])
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors('destination_airport_id');
});
