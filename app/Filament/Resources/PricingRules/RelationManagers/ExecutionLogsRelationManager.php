<?php

namespace App\Filament\Resources\PricingRules\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExecutionLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'executionLogs';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('message')
            ->columns([
                TextColumn::make('offer_id')->sortable(),
                IconColumn::make('matched')->boolean(),
                TextColumn::make('message')->limit(80)->placeholder('-'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }
}
