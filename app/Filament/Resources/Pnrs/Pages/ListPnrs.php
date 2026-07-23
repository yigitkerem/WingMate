<?php

namespace App\Filament\Resources\Pnrs\Pages;

use App\Filament\Resources\Pnrs\PnrResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPnrs extends ListRecords
{
    protected static string $resource = PnrResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
