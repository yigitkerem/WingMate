<?php

namespace App\Filament\Resources\PriceImportBatches\Pages;

use App\Filament\Resources\PriceImportBatches\PriceImportBatchResource;
use App\Models\PriceImportBatch;
use App\Services\TurkishAirlines\TurkishAirlinesFareImporter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPriceImportBatch extends ViewRecord
{
    protected static string $resource = PriceImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importAllPreview')
                ->label('Import all preview fares')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->items()->where('status', 'preview')->exists())
                ->action(function (TurkishAirlinesFareImporter $importer): void {
                    /** @var PriceImportBatch $batch */
                    $batch = $this->record;
                    $count = $importer->import($batch, $batch->items()->where('status', 'preview')->get());

                    Notification::make()
                        ->title($count === 1 ? 'Fare imported' : "{$count} fares imported")
                        ->success()
                        ->send();
                }),
        ];
    }
}
