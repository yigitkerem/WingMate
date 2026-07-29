<?php

namespace App\Filament\Resources\ServicePrices\Pages;

use App\Filament\Resources\ServicePrices\ServicePriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServicePrices extends ListRecords
{
    protected static string $resource = ServicePriceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
