<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\RelationManagers\TicketsRelationManager;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static \UnitEnum|string|null $navigationGroup = 'Pricing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('booking_reference')->required(),
            Select::make('status')->options(['confirmed' => 'Confirmed', 'flown' => 'Flown', 'cancelled' => 'Cancelled'])->required(),
            TextInput::make('first_name')->required(),
            TextInput::make('last_name')->required(),
            TextInput::make('email')->email(),
            TextInput::make('passport_number'),
            TextInput::make('total_price')->numeric()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('booking_reference')->searchable()->sortable(),
            TextColumn::make('first_name')->searchable(),
            TextColumn::make('last_name')->searchable(),
            TextColumn::make('status')->sortable(),
            TextColumn::make('total_price')->money('USD')->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('id', 'desc')->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            TicketsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'edit' => EditOrder::route('/{record}/edit')];
    }
}
