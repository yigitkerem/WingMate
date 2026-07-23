<?php

namespace App\Filament\Resources\Availabilities\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AvailabilitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('flight.flight_number')
                    ->label('Flight')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('class_letters')
                    ->label('Letters')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('fare_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'round_trip' ? 'Round trip' : 'One way')
                    ->sortable(),
                TextColumn::make('class')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('base_price_usd')
                    ->money('USD')
                    ->sortable(),
                TextColumn::make('count_available')
                    ->label('Seats')
                    ->sortable(),
                TextColumn::make('checked_baggage_kg')
                    ->label('Checked kg')
                    ->sortable(),
                TextColumn::make('cabin_baggage_kg')
                    ->label('Cabin kg')
                    ->sortable(),
                IconColumn::make('seat_selection_free')
                    ->label('Free seats')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('change_fee_usd')
                    ->money('USD')
                    ->sortable(),
                TextColumn::make('refund_fee_usd')
                    ->money('USD')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('flight_id')
                    ->relationship('flight', 'flight_number')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('fare_type')
                    ->options([
                        'one_way' => 'One way',
                        'round_trip' => 'Round trip',
                    ]),
                SelectFilter::make('seat_selection_free')
                    ->label('Seat selection')
                    ->options([
                        '1' => 'Free',
                        '0' => 'Paid',
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
