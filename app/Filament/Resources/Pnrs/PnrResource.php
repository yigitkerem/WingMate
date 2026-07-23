<?php

namespace App\Filament\Resources\Pnrs;

use App\Filament\Resources\Pnrs\Pages\CreatePnr;
use App\Filament\Resources\Pnrs\Pages\EditPnr;
use App\Filament\Resources\Pnrs\Pages\ListPnrs;
use App\Filament\Resources\Pnrs\Schemas\PnrForm;
use App\Filament\Resources\Pnrs\Tables\PnrsTable;
use App\Models\Pnr;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PnrResource extends Resource
{
    protected static ?string $model = Pnr::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PnrForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PnrsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPnrs::route('/'),
            'create' => CreatePnr::route('/create'),
            'edit' => EditPnr::route('/{record}/edit'),
        ];
    }
}
