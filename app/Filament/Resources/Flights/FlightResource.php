<?php

namespace App\Filament\Resources\Flights;

use App\Filament\Resources\Flights\Pages\CreateFlight;
use App\Filament\Resources\Flights\Pages\EditFlight;
use App\Filament\Resources\Flights\Pages\ListFlights;
use App\Filament\Resources\Flights\RelationManagers\BaseFaresRelationManager;
use App\Filament\Resources\Flights\RelationManagers\InventoriesRelationManager;
use App\Models\Flight;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FlightResource extends Resource
{
    protected static ?string $model = Flight::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('flight_number')->required(),
            Select::make('origin_airport_id')->relationship('originAirport', 'iata_code')->searchable()->preload()->required(),
            Select::make('destination_airport_id')->relationship('destinationAirport', 'iata_code')->searchable()->preload()->required(),
            DateTimePicker::make('departure_at')->required(),
            DateTimePicker::make('arrival_at')->required(),
            TextInput::make('duration_minutes')->numeric()->required(),
            TextInput::make('aircraft_type')->required(),
            Select::make('status')->options(['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'])->default('scheduled')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('flight_number')->searchable()->sortable(),
                TextColumn::make('originAirport.iata_code')->label('Origin')->sortable(),
                TextColumn::make('destinationAirport.iata_code')->label('Destination')->sortable(),
                TextColumn::make('departure_at')->dateTime()->sortable(),
                TextColumn::make('arrival_at')->dateTime()->sortable(),
                TextColumn::make('aircraft_type')->searchable(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [
            InventoriesRelationManager::class,
            BaseFaresRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlights::route('/'),
            'create' => CreateFlight::route('/create'),
            'edit' => EditFlight::route('/{record}/edit'),
        ];
    }
}
