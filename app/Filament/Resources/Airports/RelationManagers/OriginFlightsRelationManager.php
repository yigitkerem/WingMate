<?php

namespace App\Filament\Resources\Airports\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OriginFlightsRelationManager extends RelationManager
{
    protected static string $relationship = 'originFlights';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('flight_number')->required(),
            Select::make('destination_airport_id')->relationship('destinationAirport', 'iata_code')->searchable()->preload()->required(),
            DateTimePicker::make('departure_at')->required(),
            DateTimePicker::make('arrival_at')->required(),
            TextInput::make('duration_minutes')->numeric()->required(),
            TextInput::make('aircraft_type')->required(),
            Select::make('status')->options(['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'])->default('scheduled')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('flight_number')
            ->columns([
                TextColumn::make('flight_number')->searchable()->sortable(),
                TextColumn::make('destinationAirport.iata_code')->label('To')->sortable(),
                TextColumn::make('departure_at')->dateTime()->sortable(),
                TextColumn::make('arrival_at')->dateTime()->sortable(),
                TextColumn::make('aircraft_type')->searchable(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
