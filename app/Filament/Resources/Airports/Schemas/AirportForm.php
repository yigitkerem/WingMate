<?php

namespace App\Filament\Resources\Airports\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AirportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->required()
                    ->length(3)
                    ->unique(ignoreRecord: true)
                    ->formatStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null)
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            ]);
    }
}
