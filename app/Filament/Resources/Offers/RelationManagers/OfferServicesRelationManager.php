<?php

namespace App\Filament\Resources\Offers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OfferServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'offerServices';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('service.name')
            ->columns([
                TextColumn::make('service.code')->label('Code')->sortable(),
                TextColumn::make('service.name')->label('Service')->searchable(),
                TextColumn::make('quantity')->sortable(),
                TextColumn::make('value')->placeholder('-'),
                TextColumn::make('price')->money('USD')->sortable(),
                TextColumn::make('source')->sortable(),
                IconColumn::make('included')->boolean(),
            ]);
    }
}
