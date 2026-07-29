<?php

namespace App\Filament\Resources\Services;

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\RelationManagers\ConstraintsRelationManager;
use App\Filament\Resources\Services\RelationManagers\PricesRelationManager;
use App\Models\Service;
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

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static \UnitEnum|string|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            TextInput::make('name')->required(),
            Textarea::make('description')->columnSpanFull(),
            Select::make('category')->options(['BAG' => 'Bag', 'SEAT' => 'Seat', 'FLEXIBILITY' => 'Flexibility', 'LOUNGE' => 'Lounge', 'AIRPORT' => 'Airport', 'CONNECTIVITY' => 'Connectivity', 'MEAL' => 'Meal'])->required(),
            Select::make('value_type')->options(['boolean' => 'Boolean', 'integer' => 'Integer', 'decimal' => 'Decimal', 'string' => 'String'])->required(),
            TextInput::make('default_unit'),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('category')->sortable(),
            TextColumn::make('value_type'),
            IconColumn::make('active')->boolean(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [
            PricesRelationManager::class,
            ConstraintsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListServices::route('/'), 'create' => CreateService::route('/create'), 'edit' => EditService::route('/{record}/edit')];
    }
}
