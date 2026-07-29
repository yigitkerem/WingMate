<?php

namespace App\Filament\Resources\BookingClasses;

use App\Filament\Resources\BookingClasses\Pages\CreateBookingClass;
use App\Filament\Resources\BookingClasses\Pages\EditBookingClass;
use App\Filament\Resources\BookingClasses\Pages\ListBookingClasses;
use App\Models\BookingClass;
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

class BookingClassResource extends Resource
{
    protected static ?string $model = BookingClass::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static \UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(4)->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            Select::make('cabin_id')->relationship('cabin', 'name')->required()->preload(),
            TextInput::make('priority')->numeric()->default(0)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->sortable()->searchable(),
            TextColumn::make('cabin.name')->sortable(),
            TextColumn::make('priority')->sortable(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBookingClasses::route('/'), 'create' => CreateBookingClass::route('/create'), 'edit' => EditBookingClass::route('/{record}/edit')];
    }
}
