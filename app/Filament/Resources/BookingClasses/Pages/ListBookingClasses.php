<?php

namespace App\Filament\Resources\BookingClasses\Pages;

use App\Filament\Resources\BookingClasses\BookingClassResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBookingClasses extends ListRecords
{
    protected static string $resource = BookingClassResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
