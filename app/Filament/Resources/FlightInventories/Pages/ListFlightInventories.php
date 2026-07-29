<?php

namespace App\Filament\Resources\FlightInventories\Pages;

use App\Filament\Resources\FlightInventories\FlightInventoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlightInventories extends ListRecords
{
    protected static string $resource = FlightInventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
