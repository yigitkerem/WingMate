<?php

namespace App\Filament\Resources\Tickets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pnr.passport_number')
                    ->label('PNR')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pnr.first_name')
                    ->label('First name')
                    ->searchable(),
                TextColumn::make('pnr.last_name')
                    ->label('Last name')
                    ->searchable(),
                TextColumn::make('availability.flight.flight_number')
                    ->label('Flight')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('availability.class_letters')
                    ->label('Class')
                    ->sortable(),
                IconColumn::make('flown')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('flown')
                    ->options([
                        '1' => 'Flown',
                        '0' => 'Not flown',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
