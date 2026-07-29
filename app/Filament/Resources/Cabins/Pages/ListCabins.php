<?php

namespace App\Filament\Resources\Cabins\Pages;

use App\Filament\Resources\Cabins\CabinResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCabins extends ListRecords
{
    protected static string $resource = CabinResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
