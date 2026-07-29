<?php

namespace App\Filament\Resources\PriceImportBatches;

use App\Filament\Resources\PriceImportBatches\Pages\ListPriceImportBatches;
use App\Filament\Resources\PriceImportBatches\Pages\ViewPriceImportBatch;
use App\Filament\Resources\PriceImportBatches\RelationManagers\ItemsRelationManager;
use App\Models\PriceImportBatch;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceImportBatchResource extends Resource
{
    protected static ?string $model = PriceImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('source')->sortable(),
                TextColumn::make('status')->sortable(),
                TextColumn::make('imported_count')->sortable(),
                TextColumn::make('message')->limit(60),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListPriceImportBatches::route('/'), 'view' => ViewPriceImportBatch::route('/{record}')];
    }
}
