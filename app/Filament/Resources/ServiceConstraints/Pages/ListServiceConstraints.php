<?php

namespace App\Filament\Resources\ServiceConstraints\Pages;

use App\Filament\Resources\ServiceConstraints\ServiceConstraintResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceConstraints extends ListRecords
{
    protected static string $resource = ServiceConstraintResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
