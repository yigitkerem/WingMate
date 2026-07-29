<?php

namespace App\Filament\Resources\Services\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConstraintsRelationManager extends RelationManager
{
    protected static string $relationship = 'constraints';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->options([
                    'requires' => 'Requires',
                    'conflicts' => 'Conflicts',
                    'min_quantity' => 'Minimum quantity',
                    'max_quantity' => 'Maximum quantity',
                ])
                ->required(),
            Select::make('related_service_id')
                ->relationship('relatedService', 'name')
                ->searchable()
                ->preload(),
            KeyValue::make('parameters')
                ->keyLabel('Parameter')
                ->valueLabel('Value')
                ->columnSpanFull(),
            TextInput::make('message')->columnSpanFull(),
            Toggle::make('active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                TextColumn::make('type')->sortable(),
                TextColumn::make('relatedService.code')->label('Related service')->placeholder('-')->sortable(),
                TextColumn::make('message')->limit(50)->placeholder('-'),
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
