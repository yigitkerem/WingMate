<?php

namespace App\Filament\Resources\Airports;

use App\Filament\Resources\Airports\Pages\CreateAirport;
use App\Filament\Resources\Airports\Pages\EditAirport;
use App\Filament\Resources\Airports\Pages\ListAirports;
use App\Filament\Resources\Airports\RelationManagers\DestinationFlightsRelationManager;
use App\Filament\Resources\Airports\RelationManagers\OriginFlightsRelationManager;
use App\Models\Airport;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AirportResource extends Resource
{
    protected static ?string $model = Airport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('iata_code')->required()->maxLength(3)->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            TextInput::make('icao_code')->maxLength(4)->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
            TextInput::make('name')->required(),
            TextInput::make('city')->required(),
            TextInput::make('country')->required()->maxLength(2)->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            TextInput::make('timezone')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('iata_code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('city')->searchable(),
                TextColumn::make('country')->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [
            OriginFlightsRelationManager::class,
            DestinationFlightsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAirports::route('/'),
            'create' => CreateAirport::route('/create'),
            'edit' => EditAirport::route('/{record}/edit'),
        ];
    }
}
