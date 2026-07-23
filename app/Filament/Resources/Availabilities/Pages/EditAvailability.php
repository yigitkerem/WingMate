<?php

namespace App\Filament\Resources\Availabilities\Pages;

use App\Filament\Resources\Availabilities\AvailabilityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAvailability extends EditRecord
{
    protected static string $resource = AvailabilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
