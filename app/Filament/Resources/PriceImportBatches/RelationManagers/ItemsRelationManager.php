<?php

namespace App\Filament\Resources\PriceImportBatches\RelationManagers;

use App\Models\PriceImportBatch;
use App\Models\PriceImportItem;
use App\Services\TurkishAirlines\TurkishAirlinesFareImporter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('status')
            ->columns([
                TextColumn::make('status')->sortable(),
                TextColumn::make('mapped_payload.flight_number')->label('Flight')->searchable(),
                TextColumn::make('mapped_payload.origin')->label('From'),
                TextColumn::make('mapped_payload.destination')->label('To'),
                TextColumn::make('mapped_payload.departure_at')->label('Departure')->dateTime()->sortable(),
                TextColumn::make('mapped_payload.bundle')->label('Bundle')->badge(),
                TextColumn::make('mapped_payload.booking_class')->label('Class'),
                TextColumn::make('mapped_payload.base_price')
                    ->label('Base')
                    ->formatStateUsing(fn (mixed $state, PriceImportItem $record): string => $this->formatMoney($state, $record)),
                TextColumn::make('mapped_payload.taxes')
                    ->label('Taxes')
                    ->formatStateUsing(fn (mixed $state, PriceImportItem $record): string => $this->formatMoney($state, $record)),
                TextColumn::make('mapped_payload.fees')
                    ->label('Fees')
                    ->formatStateUsing(fn (mixed $state, PriceImportItem $record): string => $this->formatMoney($state, $record)),
                TextColumn::make('mapped_payload.available')->label('Seats'),
                TextColumn::make('flight.flight_number')->label('Imported flight')->placeholder('-')->searchable(),
                TextColumn::make('baseFare.bundle.code')->label('Imported bundle')->placeholder('-'),
                IconColumn::make('base_fare_id')->label('Imported')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('import')
                    ->label('Import')
                    ->requiresConfirmation()
                    ->visible(fn (PriceImportItem $record): bool => $record->status !== 'imported')
                    ->action(function (PriceImportItem $record, TurkishAirlinesFareImporter $importer): void {
                        /** @var PriceImportBatch $batch */
                        $batch = $this->getOwnerRecord();
                        $count = $importer->import($batch, [$record]);

                        $this->notifyImported($count);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('importSelected')
                        ->label('Import selected')
                        ->requiresConfirmation()
                        ->action(function (Collection $records, TurkishAirlinesFareImporter $importer): void {
                            /** @var PriceImportBatch $batch */
                            $batch = $this->getOwnerRecord();
                            $count = $importer->import($batch, $records);

                            $this->notifyImported($count);
                        }),
                ]),
            ]);
    }

    private function notifyImported(int $count): void
    {
        Notification::make()
            ->title($count === 1 ? 'Fare imported' : "{$count} fares imported")
            ->success()
            ->send();
    }

    private function formatMoney(mixed $state, PriceImportItem $record): string
    {
        return number_format((float) $state, 2).' '.data_get($record->mapped_payload, 'currency', 'USD');
    }
}
