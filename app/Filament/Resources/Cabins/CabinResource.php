<?php

namespace App\Filament\Resources\Cabins;

use App\Filament\Resources\Cabins\Pages\CreateCabin;
use App\Filament\Resources\Cabins\Pages\EditCabin;
use App\Filament\Resources\Cabins\Pages\ListCabins;
use App\Filament\Resources\Cabins\RelationManagers\BookingClassesRelationManager;
use App\Models\Cabin;
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

class CabinResource extends Resource
{
    protected static ?string $model = Cabin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static \UnitEnum|string|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            TextInput::make('name')->required(),
            TextInput::make('display_order')->numeric()->default(0)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->sortable()->searchable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('display_order')->sortable(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [
            BookingClassesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListCabins::route('/'), 'create' => CreateCabin::route('/create'), 'edit' => EditCabin::route('/{record}/edit')];
    }
}
