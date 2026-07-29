<?php

namespace App\Filament\Resources\ServicePrices;

use App\Filament\Resources\ServicePrices\Pages\CreateServicePrice;
use App\Filament\Resources\ServicePrices\Pages\EditServicePrice;
use App\Filament\Resources\ServicePrices\Pages\ListServicePrices;
use App\Models\ServicePrice;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServicePriceResource extends Resource
{
    protected static ?string $model = ServicePrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static \UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')->relationship('service', 'name')->preload()->required(),
            Select::make('bundle_id')->relationship('bundle', 'name')->preload(),
            TextInput::make('unit_price')->numeric()->required(),
            TextInput::make('min_quantity')->numeric()->default(0)->required(),
            TextInput::make('max_quantity')->numeric()->default(1)->required(),
            DateTimePicker::make('valid_from'),
            DateTimePicker::make('valid_until'),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('service.code')->sortable(),
            TextColumn::make('bundle.code')->placeholder('Any bundle'),
            TextColumn::make('unit_price')->money('USD')->sortable(),
            IconColumn::make('active')->boolean(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServicePrices::route('/'), 'create' => CreateServicePrice::route('/create'), 'edit' => EditServicePrice::route('/{record}/edit')];
    }
}
