<?php

namespace App\Filament\Resources\Availabilities\RelationManagers;

use App\Filament\Resources\Tickets\Tables\TicketsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    public function table(Table $table): Table
    {
        return TicketsTable::configure($table);
    }
}
