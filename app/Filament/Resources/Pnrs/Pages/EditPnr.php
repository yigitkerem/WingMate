<?php

namespace App\Filament\Resources\Pnrs\Pages;

use App\Filament\Resources\Pnrs\PnrResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPnr extends EditRecord
{
    protected static string $resource = PnrResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
