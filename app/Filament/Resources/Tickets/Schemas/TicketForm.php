<?php

namespace App\Filament\Resources\Tickets\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TicketForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('availability_id')
                    ->relationship('availability', 'class_letters')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('pnr_id')
                    ->relationship('pnr', 'passport_number')
                    ->searchable()
                    ->preload()
                    ->required(),
                Toggle::make('flown')
                    ->default(false),
            ]);
    }
}
