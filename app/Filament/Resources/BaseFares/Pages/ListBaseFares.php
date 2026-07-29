<?php

namespace App\Filament\Resources\BaseFares\Pages;

use App\Filament\Resources\BaseFares\BaseFareResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBaseFares extends ListRecords
{
    protected static string $resource = BaseFareResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
