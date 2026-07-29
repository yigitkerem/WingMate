<?php

namespace App\Filament\Resources\ServiceConstraints;

use App\Filament\Resources\ServiceConstraints\Pages\CreateServiceConstraint;
use App\Filament\Resources\ServiceConstraints\Pages\EditServiceConstraint;
use App\Filament\Resources\ServiceConstraints\Pages\ListServiceConstraints;
use App\Models\ServiceConstraint;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceConstraintResource extends Resource
{
    protected static ?string $model = ServiceConstraint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static \UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')->relationship('service', 'name')->preload()->required(),
            Select::make('type')->options(['requires' => 'Requires', 'conflicts' => 'Conflicts', 'min_quantity' => 'Minimum quantity', 'max_quantity' => 'Maximum quantity'])->required(),
            Select::make('related_service_id')->relationship('relatedService', 'name')->preload(),
            KeyValue::make('parameters')->columnSpanFull(),
            TextInput::make('message')->columnSpanFull(),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('service.code')->sortable(),
            TextColumn::make('type')->sortable(),
            TextColumn::make('relatedService.code')->placeholder('-'),
            IconColumn::make('active')->boolean(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServiceConstraints::route('/'), 'create' => CreateServiceConstraint::route('/create'), 'edit' => EditServiceConstraint::route('/{record}/edit')];
    }
}
