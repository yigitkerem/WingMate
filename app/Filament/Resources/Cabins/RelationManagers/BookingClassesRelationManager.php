<?php

namespace App\Filament\Resources\Cabins\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BookingClassesRelationManager extends RelationManager
{
    protected static string $relationship = 'bookingClasses';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->maxLength(4)
                ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            TextInput::make('priority')->numeric()->default(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')->sortable()->searchable(),
                TextColumn::make('priority')->sortable(),
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
