<?php

namespace App\Filament\Resources\Offers;

use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Offers\Pages\ViewOffer;
use App\Filament\Resources\Offers\RelationManagers\OfferServicesRelationManager;
use App\Filament\Resources\Offers\RelationManagers\PriceComponentsRelationManager;
use App\Models\Offer;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static \UnitEnum|string|null $navigationGroup = 'Pricing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('flight.flight_number')->searchable(),
            TextColumn::make('bundle.code')->label('Bundle'),
            TextColumn::make('class_letters'),
            TextColumn::make('total_price')->money('USD')->sortable(),
            TextColumn::make('expires_at')->dateTime()->sortable(),
        ])->defaultSort('id', 'desc')->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            OfferServicesRelationManager::class,
            PriceComponentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListOffers::route('/'), 'view' => ViewOffer::route('/{record}')];
    }
}
