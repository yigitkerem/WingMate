<?php

namespace App\Filament\Resources\Tickets;

use App\Filament\Resources\Tickets\Pages\EditTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\RelationManagers\SegmentsRelationManager;
use App\Models\Ticket;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static \UnitEnum|string|null $navigationGroup = 'Pricing';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('ticket_number')->required(),
            Select::make('status')->options(['issued' => 'Issued', 'flown' => 'Flown', 'cancelled' => 'Cancelled'])->required(),
            Select::make('passenger_type')->options(['ADT' => 'Adult', 'CHD' => 'Child', 'INF' => 'Infant'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('ticket_number')->searchable()->sortable(),
            TextColumn::make('order.booking_reference')->searchable(),
            TextColumn::make('offer.flight.flight_number')->label('Flight'),
            TextColumn::make('passenger_type')->sortable(),
            TextColumn::make('status')->sortable(),
            TextColumn::make('issued_at')->dateTime()->sortable(),
        ])->defaultSort('id', 'desc')->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            SegmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListTickets::route('/'), 'edit' => EditTicket::route('/{record}/edit')];
    }
}
