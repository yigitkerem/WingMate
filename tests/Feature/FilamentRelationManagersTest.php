<?php

use App\Filament\Resources\Airports\Pages\EditAirport;
use App\Filament\Resources\Airports\RelationManagers\FlightsRelationManager;
use App\Filament\Resources\Availabilities\Pages\EditAvailability;
use App\Filament\Resources\Availabilities\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Flights\Pages\EditFlight;
use App\Filament\Resources\Flights\RelationManagers\AvailabilitiesRelationManager;
use App\Models\Airport;
use App\Models\Availability;
use App\Models\Flight;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');

    actingAs(User::factory()->create([
        'is_admin' => true,
    ]));
});

it('shows flights arriving at or departing from an airport', function () {
    $airport = Airport::factory()->create();
    $otherAirport = Airport::factory()->create();
    $unrelatedOriginAirport = Airport::factory()->create();
    $unrelatedDestinationAirport = Airport::factory()->create();

    $departingFlight = Flight::factory()->create([
        'origin_airport_id' => $airport->id,
        'destination_airport_id' => $otherAirport->id,
    ]);
    $arrivingFlight = Flight::factory()->create([
        'origin_airport_id' => $otherAirport->id,
        'destination_airport_id' => $airport->id,
    ]);
    $unrelatedFlight = Flight::factory()->create([
        'origin_airport_id' => $unrelatedOriginAirport->id,
        'destination_airport_id' => $unrelatedDestinationAirport->id,
    ]);

    Livewire::test(EditAirport::class, [
        'record' => $airport->id,
    ])
        ->assertOk()
        ->assertSeeLivewire(FlightsRelationManager::class);

    Livewire::test(FlightsRelationManager::class, [
        'ownerRecord' => $airport,
        'pageClass' => EditAirport::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords(collect([$departingFlight, $arrivingFlight]))
        ->assertCanNotSeeTableRecords(collect([$unrelatedFlight]));
});

it('shows availabilities for a flight', function () {
    $flight = Flight::factory()->create();
    $availabilities = Availability::factory()
        ->count(2)
        ->create([
            'flight_id' => $flight->id,
        ]);
    $unrelatedAvailability = Availability::factory()->create();

    Livewire::test(EditFlight::class, [
        'record' => $flight->id,
    ])
        ->assertOk()
        ->assertSeeLivewire(AvailabilitiesRelationManager::class);

    Livewire::test(AvailabilitiesRelationManager::class, [
        'ownerRecord' => $flight,
        'pageClass' => EditFlight::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($availabilities)
        ->assertCanNotSeeTableRecords(collect([$unrelatedAvailability]));
});

it('shows tickets for an availability', function () {
    $availability = Availability::factory()->create();
    $tickets = Ticket::factory()
        ->count(2)
        ->create([
            'availability_id' => $availability->id,
        ]);
    $unrelatedTicket = Ticket::factory()->create();

    Livewire::test(EditAvailability::class, [
        'record' => $availability->id,
    ])
        ->assertOk()
        ->assertSeeLivewire(TicketsRelationManager::class);

    Livewire::test(TicketsRelationManager::class, [
        'ownerRecord' => $availability,
        'pageClass' => EditAvailability::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($tickets)
        ->assertCanNotSeeTableRecords(collect([$unrelatedTicket]));
});
