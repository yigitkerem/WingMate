<?php

namespace App\Filament\Resources\Flights\RelationManagers;

use App\Filament\Resources\Availabilities\Tables\AvailabilitiesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class AvailabilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'availabilities';

    public function table(Table $table): Table
    {
        return AvailabilitiesTable::configure($table);
    }
}
