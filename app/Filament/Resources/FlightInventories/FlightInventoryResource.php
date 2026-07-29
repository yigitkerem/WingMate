<?php

namespace App\Filament\Resources\FlightInventories;

use App\Filament\Resources\FlightInventories\Pages\CreateFlightInventory;
use App\Filament\Resources\FlightInventories\Pages\EditFlightInventory;
use App\Filament\Resources\FlightInventories\Pages\ListFlightInventories;
use App\Models\FlightInventory;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FlightInventoryResource extends Resource
{
    protected static ?string $model = FlightInventory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('flight_id')->relationship('flight', 'flight_number')->searchable()->required(),
            Select::make('booking_class_id')->relationship('bookingClass', 'code')->preload()->required(),
            TextInput::make('capacity')->numeric()->required(),
            TextInput::make('available')->numeric()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('flight.flight_number')->searchable()->sortable(),
            TextColumn::make('bookingClass.code')->label('Class')->sortable(),
            TextColumn::make('capacity')->sortable(),
            TextColumn::make('available')->sortable(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFlightInventories::route('/'), 'create' => CreateFlightInventory::route('/create'), 'edit' => EditFlightInventory::route('/{record}/edit')];
    }
}
