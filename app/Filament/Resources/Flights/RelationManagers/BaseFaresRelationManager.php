<?php

namespace App\Filament\Resources\Flights\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BaseFaresRelationManager extends RelationManager
{
    protected static string $relationship = 'baseFares';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('booking_class_id')
                ->relationship('bookingClass', 'code')
                ->preload()
                ->required(),
            Select::make('bundle_id')
                ->relationship('bundle', 'name')
                ->searchable()
                ->preload()
                ->required(),
            Select::make('trip_type')
                ->options(['one_way' => 'One way', 'round_trip' => 'Round trip'])
                ->default('one_way')
                ->required(),
            TextInput::make('leg_index')->numeric()->default(1)->required(),
            TextInput::make('base_price')->numeric()->prefix('$')->required(),
            TextInput::make('taxes')->numeric()->prefix('$')->default(0)->required(),
            TextInput::make('fees')->numeric()->prefix('$')->default(0)->required(),
            TextInput::make('fare_basis_template')->default('{class}{bundle}{trip}{leg}')->required(),
            TextInput::make('class_letters')->required(),
            DateTimePicker::make('valid_from'),
            DateTimePicker::make('valid_until'),
            Toggle::make('active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('bundle.code')
            ->columns([
                TextColumn::make('bookingClass.code')->label('Class')->sortable(),
                TextColumn::make('bundle.code')->label('Bundle')->searchable()->sortable(),
                TextColumn::make('trip_type')->sortable(),
                TextColumn::make('leg_index')->sortable(),
                TextColumn::make('base_price')->money('USD')->sortable(),
                TextColumn::make('taxes')->money('USD'),
                TextColumn::make('fees')->money('USD'),
                IconColumn::make('active')->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
