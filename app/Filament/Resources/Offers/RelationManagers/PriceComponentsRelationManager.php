<?php

namespace App\Filament\Resources\Offers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceComponentsRelationManager extends RelationManager
{
    protected static string $relationship = 'priceComponents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('label')->searchable(),
                TextColumn::make('type')->sortable(),
                TextColumn::make('amount')->money('USD')->sortable(),
                TextColumn::make('pricingRule.name')->label('Rule')->placeholder('-'),
            ]);
    }
}
