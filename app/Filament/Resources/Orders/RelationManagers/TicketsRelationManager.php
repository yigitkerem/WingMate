<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('offer_id')
                ->relationship('offer', 'uuid')
                ->searchable()
                ->required(),
            TextInput::make('ticket_number')->required(),
            Select::make('passenger_type')
                ->options(['ADT' => 'Adult', 'CHD' => 'Child', 'INF' => 'Infant'])
                ->default('ADT')
                ->required(),
            Select::make('status')
                ->options(['issued' => 'Issued', 'flown' => 'Flown', 'cancelled' => 'Cancelled'])
                ->default('issued')
                ->required(),
            DateTimePicker::make('issued_at'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ticket_number')
            ->columns([
                TextColumn::make('ticket_number')->searchable()->sortable(),
                TextColumn::make('offer.flight.flight_number')->label('Flight')->searchable(),
                TextColumn::make('offer.bundle.code')->label('Bundle')->sortable(),
                TextColumn::make('passenger_type')->sortable(),
                TextColumn::make('status')->sortable(),
                TextColumn::make('issued_at')->dateTime()->sortable(),
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
