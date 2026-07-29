<?php

namespace App\Filament\Resources\Flights\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InventoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'inventories';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('booking_class_id')
                ->relationship('bookingClass', 'code')
                ->preload()
                ->required(),
            TextInput::make('capacity')->numeric()->required(),
            TextInput::make('available')->numeric()->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('bookingClass.code')
            ->columns([
                TextColumn::make('bookingClass.cabin.code')->label('Cabin')->sortable(),
                TextColumn::make('bookingClass.code')->label('Class')->sortable(),
                TextColumn::make('capacity')->sortable(),
                TextColumn::make('available')->sortable(),
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
