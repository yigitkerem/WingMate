<?php

namespace App\Filament\Resources\Bundles;

use App\Filament\Resources\Bundles\Pages\CreateBundle;
use App\Filament\Resources\Bundles\Pages\EditBundle;
use App\Filament\Resources\Bundles\Pages\ListBundles;
use App\Filament\Resources\Bundles\RelationManagers\BundleServicesRelationManager;
use App\Filament\Resources\Bundles\RelationManagers\ServicePricesRelationManager;
use App\Models\Bundle;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BundleResource extends Resource
{
    protected static ?string $model = Bundle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static \UnitEnum|string|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')->relationship('product', 'name')->required()->preload(),
            TextInput::make('code')->required()->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            TextInput::make('name')->required(),
            Textarea::make('description')->columnSpanFull(),
            TextInput::make('display_order')->numeric()->default(0)->required(),
            Toggle::make('public')->default(true),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_order')->sortable(),
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('product.name')->sortable(),
            TextColumn::make('name')->searchable(),
            IconColumn::make('public')->boolean(),
            IconColumn::make('active')->boolean(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [
            BundleServicesRelationManager::class,
            ServicePricesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListBundles::route('/'), 'create' => CreateBundle::route('/create'), 'edit' => EditBundle::route('/{record}/edit')];
    }
}
