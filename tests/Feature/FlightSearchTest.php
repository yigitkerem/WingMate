<?php

use App\Actions\SearchFlights;
use App\Models\Airport;
use App\Models\Offer;
use App\Models\PricingRule;
use App\Models\ServiceConstraint;
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
            ->where('locale', 'en')
            ->where('results', null),
        );
});

test('language can be switched for inertia pages', function () {
    createSellablePricingFixture();

    $this->from(route('home'))
        ->post(route('language.update', ['locale' => 'tr']))
        ->assertRedirect(route('home'));

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flight-search')
            ->where('locale', 'tr'),
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

test('get search renders results from query parameters', function () {
    $fixture = createSellablePricingFixture();

    $this->get('/search?'.http_build_query([
        'origin_airport_id' => $fixture['origin']->id,
        'destination_airport_id' => $fixture['destination']->id,
        'trip_type' => 'one_way',
        'depart_date' => '2026-08-10',
        'search_mode' => 'basic',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flight-results')
            ->where('results.seat_passengers', 1)
            ->where('results.outbound.0.flight_number', 'TK100')
            ->where('results.outbound.0.fares.0.class', 'ExtraFly'),
        );
});

test('flight search skips fares that violate service constraints', function () {
    $fixture = createSellablePricingFixture();

    ServiceConstraint::query()->create([
        'service_id' => $fixture['checkedBag']->id,
        'type' => 'max_quantity',
        'parameters' => ['quantity' => 3],
        'message' => 'At most three checked bags can be selected.',
    ]);

    $this->get('/search?'.http_build_query([
        'origin_airport_id' => $fixture['origin']->id,
        'destination_airport_id' => $fixture['destination']->id,
        'trip_type' => 'one_way',
        'depart_date' => '2026-08-10',
        'search_mode' => 'basic',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flight-results')
            ->where('results.outbound', []),
        );
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

test('seeded premium fares expose rich flexibility rules', function () {
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
                ->where('results.outbound.0.fares.3.package_code', 'BUSINESSFLY')
                ->where('results.outbound.0.fares.3.latest_refund_hours', 72)
                ->where('results.outbound.0.fares.3.refund_fee_usd', null)
                ->where('results.outbound.0.fares.3.refund_fee_percent', 25)
                ->where('results.outbound.0.fares.4.package_code', 'BUSINESSPRIME')
                ->where('results.outbound.0.fares.4.latest_change_hours', 360)
                ->where('results.outbound.0.fares.4.latest_refund_hours', 240),
            );
    } finally {
        Carbon::setTestNow();
    }
});

test('demo seeder creates searchable fares between Istanbul and Haneda', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');

    try {
        $this->seed(AirlineDemoSeeder::class);

        $istanbul = Airport::query()->where('iata_code', 'IST')->firstOrFail();
        $haneda = Airport::query()->where('iata_code', 'HND')->firstOrFail();
        $searchFlights = app(SearchFlights::class);

        $outboundFlights = $searchFlights->execute(
            originAirportId: $istanbul->id,
            destinationAirportId: $haneda->id,
            date: '2026-07-29',
            mode: 'basic',
            seatPassengers: 1,
            tripType: 'one_way',
        );
        $returnFlights = $searchFlights->execute(
            originAirportId: $haneda->id,
            destinationAirportId: $istanbul->id,
            date: '2026-07-29',
            mode: 'basic',
            seatPassengers: 1,
            tripType: 'one_way',
        );
    } finally {
        Carbon::setTestNow();
    }

    $expectedPackages = ['ECOFLY', 'EXTRAFLY', 'PRIMEFLY', 'BUSINESSFLY', 'BUSINESSPRIME'];

    expect($outboundFlights)->toHaveCount(1)
        ->and($outboundFlights[0]['destination']['code'])->toBe('HND')
        ->and(collect($outboundFlights[0]['fares'])->pluck('package_code')->all())->toBe($expectedPackages)
        ->and($returnFlights)->toHaveCount(1)
        ->and($returnFlights[0]['destination']['code'])->toBe('IST')
        ->and(collect($returnFlights[0]['fares'])->pluck('package_code')->all())->toBe($expectedPackages);
});

test('demo seeder creates a richer rule catalogue with service incentives', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');

    try {
        $this->seed(AirlineDemoSeeder::class);

        $origin = Airport::query()->where('iata_code', 'IST')->firstOrFail();
        $destination = Airport::query()->where('iata_code', 'LHR')->firstOrFail();

        $flights = app(SearchFlights::class)->execute(
            originAirportId: $origin->id,
            destinationAirportId: $destination->id,
            date: '2026-07-29',
            mode: 'basic',
            seatPassengers: 2,
            tripType: 'one_way',
            adults: 1,
            children: 1,
        );
    } finally {
        Carbon::setTestNow();
    }

    $services = collect($flights[0]['fares'][0]['services']);
    $familySeat = $services->firstWhere('code', 'SEAT_STANDARD');

    expect(PricingRule::query()->count())->toBeGreaterThanOrEqual(17)
        ->and(PricingRule::query()->where('name', 'Family standard seats')->exists())->toBeTrue()
        ->and(PricingRule::query()->where('name', 'Business Wi-Fi upgrade')->exists())->toBeTrue()
        ->and($familySeat['source'])->toBe('rule')
        ->and($familySeat['value']['seat_type'])->toBe('standard');
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
