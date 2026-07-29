<?php

use App\Models\FlightInventory;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Pricing\OfferBuilder;

test('customer can buy an immutable offer', function () {
    $fixture = createSellablePricingFixture(['available' => 5]);
    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 1, 1);

    $this->from(route('home'))->post(route('tickets.purchase'), [
        'offer_ids' => [$offer->id],
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'passport_number' => 'AA1234567',
        'adults' => 1,
        'children' => 1,
        'babies' => 1,
    ])->assertRedirect(route('home'));

    expect(FlightInventory::query()->first()->available)->toBe(3)
        ->and(Order::query()->first()->booking_reference)->not->toBeEmpty()
        ->and(Ticket::query()->count())->toBe(3);
});

test('signed in customer purchase is linked to their account', function () {
    $user = User::factory()->create(['passport_number' => 'CC1234567']);
    $fixture = createSellablePricingFixture(['available' => 3]);
    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0, $user);

    $this->actingAs($user)
        ->from(route('home'))
        ->post(route('tickets.purchase'), [
            'offer_ids' => [$offer->id],
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'email' => $user->email,
            'passport_number' => $user->passport_number,
            'adults' => 1,
            'children' => 0,
            'babies' => 0,
        ])
        ->assertRedirect(route('home'));

    expect(Order::query()->first()->user_id)->toBe($user->id)
        ->and(FlightInventory::query()->first()->available)->toBe(2);
});

test('purchase cannot oversell inventory', function () {
    $fixture = createSellablePricingFixture(['available' => 1]);
    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 1, 0);

    $this->from(route('home'))->post(route('tickets.purchase'), [
        'offer_ids' => [$offer->id],
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'passport_number' => 'BB1234567',
        'adults' => 1,
        'children' => 1,
        'babies' => 0,
    ])
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors('offer_ids');

    expect(FlightInventory::query()->first()->available)->toBe(1)
        ->and(Ticket::query()->count())->toBe(0);
});

test('round trip purchase requires one offer per leg', function () {
    $outbound = createSellablePricingFixture(['trip_type' => 'round_trip', 'leg_index' => 1]);
    $return = createSellablePricingFixture([
        'origin' => 'LHR',
        'destination' => 'IST',
        'flight_number' => 'TK101',
        'departure_at' => '2026-08-15 11:00',
        'trip_type' => 'round_trip',
        'leg_index' => 2,
    ]);
    $outboundOffer = app(OfferBuilder::class)->build($outbound['baseFare'], 1, 0, 0);
    $returnOffer = app(OfferBuilder::class)->build($return['baseFare'], 1, 0, 0);

    $this->post(route('tickets.purchase'), [
        'offer_ids' => [$outboundOffer->id, $returnOffer->id],
        'first_name' => 'Round',
        'last_name' => 'Trip',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ])->assertRedirect(route('home'));

    expect(Order::query()->count())->toBe(1)
        ->and(Ticket::query()->count())->toBe(2)
        ->and(Offer::query()->pluck('leg_index')->sort()->values()->all())->toBe([1, 2]);
});
