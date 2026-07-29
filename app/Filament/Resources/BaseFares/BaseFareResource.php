<?php

namespace App\Filament\Resources\BaseFares;

use App\Filament\Resources\BaseFares\Pages\CreateBaseFare;
use App\Filament\Resources\BaseFares\Pages\EditBaseFare;
use App\Filament\Resources\BaseFares\Pages\ListBaseFares;
use App\Models\BaseFare;
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

class BaseFareResource extends Resource
{
    protected static ?string $model = BaseFare::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('flight_id')->relationship('flight', 'flight_number')->searchable()->required(),
            Select::make('booking_class_id')->relationship('bookingClass', 'code')->preload()->required(),
            Select::make('bundle_id')->relationship('bundle', 'name')->preload()->required(),
            Select::make('trip_type')->options(['one_way' => 'One way', 'round_trip' => 'Round trip'])->default('one_way')->required(),
            TextInput::make('leg_index')->numeric()->default(1)->required(),
            TextInput::make('base_price')->numeric()->required(),
            TextInput::make('taxes')->numeric()->default(0)->required(),
            TextInput::make('fees')->numeric()->default(0)->required(),
            TextInput::make('fare_basis_template')->default('{class}{bundle}{trip}{leg}')->required(),
            TextInput::make('class_letters')->required(),
            DateTimePicker::make('valid_from'),
            DateTimePicker::make('valid_until'),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('flight.flight_number')->searchable()->sortable(),
            TextColumn::make('bundle.code')->label('Bundle')->sortable(),
            TextColumn::make('bookingClass.code')->label('Class')->sortable(),
            TextColumn::make('trip_type')->sortable(),
            TextColumn::make('leg_index')->sortable(),
            TextColumn::make('base_price')->money('USD')->sortable(),
            IconColumn::make('active')->boolean(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBaseFares::route('/'), 'create' => CreateBaseFare::route('/create'), 'edit' => EditBaseFare::route('/{record}/edit')];
    }
}
