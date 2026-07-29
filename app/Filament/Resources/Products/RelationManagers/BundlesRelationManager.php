<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BundlesRelationManager extends RelationManager
{
    protected static string $relationship = 'bundles';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            TextInput::make('name')->required(),
            Textarea::make('description')->columnSpanFull(),
            TextInput::make('display_order')->numeric()->default(0)->required(),
            Toggle::make('public')->default(true),
            Toggle::make('active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('display_order')->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                IconColumn::make('public')->boolean(),
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
