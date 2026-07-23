<?php

namespace App\Filament\Resources\Flights\Pages;

use App\Filament\Resources\Flights\FlightResource;
use App\Models\Availability;
use App\Models\Flight;
use Filament\Resources\Pages\CreateRecord;

class CreateFlight extends CreateRecord
{
    protected static string $resource = FlightResource::class;

    protected function afterCreate(): void
    {
        $data = $this->form->getRawState();

        if (! ($data['create_default_availability'] ?? false)) {
            return;
        }

        collect([
            ...array_values(Flight::defaultAvailabilityTemplates(
                (int) $data['default_a_price_usd'],
                (int) $data['default_b_price_usd'],
                (int) $data['default_c_price_usd'],
            )),
            ...array_values(Flight::roundTripAvailabilityTemplates(
                (int) $data['default_a_price_usd'],
                (int) $data['default_b_price_usd'],
                (int) $data['default_c_price_usd'],
            )),
        ])->each(function (array $availability): void {
            Availability::query()->create([
                ...$availability,
                'flight_id' => $this->record->id,
            ]);
        });
    }
}
