<?php

namespace App\Filament\Resources\ServicePrices\Pages;

use App\Filament\Resources\ServicePrices\ServicePriceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServicePrice extends CreateRecord
{
    protected static string $resource = ServicePriceResource::class;
}
