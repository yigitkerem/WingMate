<?php

namespace App\Filament\Resources\Flights\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FlightsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('flight_number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('originAirport.code')
                    ->label('Origin')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('destinationAirport.code')
                    ->label('Destination')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('hour')
                    ->time()
                    ->sortable(),
                TextColumn::make('plane_model')
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('origin_airport_id')
                    ->label('Origin')
                    ->relationship('originAirport', 'code')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('destination_airport_id')
                    ->label('Destination')
                    ->relationship('destinationAirport', 'code')
                    ->searchable()
                    ->preload(),
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
