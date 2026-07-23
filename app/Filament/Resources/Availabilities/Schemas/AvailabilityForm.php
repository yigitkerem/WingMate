<?php

namespace App\Filament\Resources\Availabilities\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class AvailabilityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('flight_id')
                    ->relationship('flight', 'flight_number')
                    ->searchable()
                    ->preload()
                    ->required(),
                Grid::make(3)->schema([
                    TextInput::make('class')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('class_letters')
                        ->required()
                        ->maxLength(12)
                        ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                    Select::make('fare_type')
                        ->options([
                            'one_way' => 'One way',
                            'round_trip' => 'Round trip',
                        ])
                        ->default('one_way')
                        ->required(),
                    TextInput::make('base_price_usd')
                        ->numeric()
                        ->minValue(1)
                        ->required(),
                    TextInput::make('checked_baggage_kg')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('cabin_baggage_kg')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    Toggle::make('seat_selection_free')
                        ->label('Free seat selection')
                        ->default(false),
                    TextInput::make('count_available')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('change_fee_usd')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('refund_fee_usd')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('latest_change_hours')
                        ->numeric()
                        ->minValue(0),
                    TextInput::make('latest_refund_hours')
                        ->numeric()
                        ->minValue(0),
                ]),
            ]);
    }
}
