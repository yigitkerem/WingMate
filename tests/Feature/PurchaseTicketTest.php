<?php

use App\Models\Airport;
use App\Models\Availability;
use App\Models\Flight;
use App\Models\Pnr;
use App\Models\Ticket;
use App\Models\User;

test('customer can buy available tickets', function () {
    $availability = purchasableAvailability(countAvailable: 5);

    $this->from(route('home'))->post(route('tickets.purchase'), [
        'availability_id' => $availability->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'passport_number' => 'AA1234567',
        'adults' => 1,
        'children' => 1,
        'babies' => 1,
    ])
        ->assertRedirect(route('home'));

    expect($availability->refresh()->count_available)->toBe(3);
    expect(Pnr::query()->where('passport_number', 'AA1234567')->first()->user_id)->toBeNull();
    expect(Ticket::query()->where('availability_id', $availability->id)->count())->toBe(2);
});

test('signed in customer purchase is linked to their account', function () {
    $user = User::factory()->create();
    $availability = purchasableAvailability(countAvailable: 3);

    $this->actingAs($user)
        ->from(route('home'))
        ->post(route('tickets.purchase'), [
            'availability_id' => $availability->id,
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'passport_number' => 'CC1234567',
            'adults' => 1,
            'children' => 0,
            'babies' => 0,
        ])
        ->assertRedirect(route('home'));

    $pnr = Pnr::query()->where('passport_number', 'CC1234567')->first();

    expect($pnr->user_id)->toBe($user->id);
    expect($availability->refresh()->count_available)->toBe(2);
});

test('purchase cannot oversell availability', function () {
    $availability = purchasableAvailability(countAvailable: 1);

    $this->from(route('home'))->post(route('tickets.purchase'), [
        'availability_id' => $availability->id,
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'passport_number' => 'BB1234567',
        'adults' => 1,
        'children' => 1,
        'babies' => 0,
    ])
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors('availability_id');

    expect($availability->refresh()->count_available)->toBe(1);
    expect(Ticket::query()->where('availability_id', $availability->id)->count())->toBe(0);
});

function purchasableAvailability(int $countAvailable): Availability
{
    $origin = Airport::factory()->create(['code' => 'IST']);
    $destination = Airport::factory()->create(['code' => 'LHR']);
    $flight = Flight::factory()->create([
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'date' => '2026-08-10',
    ]);

    return Availability::factory()->create([
        'flight_id' => $flight->id,
        'class_letters' => 'A',
        'fare_type' => 'one_way',
        'base_price_usd' => 150,
        'count_available' => $countAvailable,
    ]);
}
