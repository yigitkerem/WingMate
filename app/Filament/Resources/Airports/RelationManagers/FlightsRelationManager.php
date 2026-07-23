<?php

namespace App\Filament\Resources\Airports\RelationManagers;

use App\Filament\Resources\Flights\Tables\FlightsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class FlightsRelationManager extends RelationManager
{
    protected static string $relationship = 'flights';

    protected static ?string $title = 'Flights';

    public function table(Table $table): Table
    {
        return FlightsTable::configure($table);
    }
}
