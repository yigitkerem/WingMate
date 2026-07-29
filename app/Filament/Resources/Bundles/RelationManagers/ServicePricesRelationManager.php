<?php

namespace App\Filament\Resources\Bundles\RelationManagers;

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

class ServicePricesRelationManager extends RelationManager
{
    protected static string $relationship = 'servicePrices';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')
                ->relationship('service', 'name')
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('unit_price')->numeric()->prefix('$')->required(),
            TextInput::make('min_quantity')->numeric()->default(0)->required(),
            TextInput::make('max_quantity')->numeric()->default(1)->required(),
            DateTimePicker::make('valid_from'),
            DateTimePicker::make('valid_until'),
            Toggle::make('active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('service.name')
            ->columns([
                TextColumn::make('service.code')->label('Code')->searchable()->sortable(),
                TextColumn::make('service.name')->label('Service')->searchable(),
                TextColumn::make('unit_price')->money('USD')->sortable(),
                TextColumn::make('min_quantity')->sortable(),
                TextColumn::make('max_quantity')->sortable(),
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
