<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('booking_reference')
            ->columns([
                TextColumn::make('booking_reference')->searchable()->sortable(),
                TextColumn::make('status')->sortable(),
                TextColumn::make('first_name')->searchable(),
                TextColumn::make('last_name')->searchable(),
                TextColumn::make('total_price')->money('USD')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }
}
