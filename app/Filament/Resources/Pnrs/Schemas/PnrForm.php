<?php

namespace App\Filament\Resources\Pnrs\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PnrForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('passport_number')
                    ->required()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            ]);
    }
}
