<?php

namespace App\Filament\Resources\ServicePrices\Pages;

use App\Filament\Resources\ServicePrices\ServicePriceResource;
use Filament\Resources\Pages\EditRecord;

class EditServicePrice extends EditRecord
{
    protected static string $resource = ServicePriceResource::class;
}
