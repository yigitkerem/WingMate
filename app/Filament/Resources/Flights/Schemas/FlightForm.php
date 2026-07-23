<?php

namespace App\Filament\Resources\Flights\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FlightForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Flight')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('origin_airport_id')
                                ->label('Origin')
                                ->relationship('originAirport', 'code')
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('destination_airport_id')
                                ->label('Destination')
                                ->relationship('destinationAirport', 'code')
                                ->searchable()
                                ->preload()
                                ->required(),
                            DatePicker::make('date')
                                ->required(),
                            TimePicker::make('hour')
                                ->seconds(false)
                                ->required(),
                            TextInput::make('flight_number')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('plane_model')
                                ->required()
                                ->maxLength(255),
                        ]),
                    ]),
                Section::make('Default A/B/C availability')
                    ->schema([
                        Toggle::make('create_default_availability')
                            ->label('Create A/B/C fares')
                            ->default(true)
                            ->dehydrated(false),
                        Grid::make(3)->schema([
                            TextInput::make('default_a_price_usd')
                                ->label('A price')
                                ->numeric()
                                ->minValue(1)
                                ->default(180)
                                ->required()
                                ->dehydrated(false),
                            TextInput::make('default_b_price_usd')
                                ->label('B price')
                                ->numeric()
                                ->minValue(1)
                                ->default(245)
                                ->required()
                                ->dehydrated(false),
                            TextInput::make('default_c_price_usd')
                                ->label('C price')
                                ->numeric()
                                ->minValue(1)
                                ->default(460)
                                ->required()
                                ->dehydrated(false),
                        ]),
                    ])
                    ->hiddenOn('edit'),
            ]);
    }
}
