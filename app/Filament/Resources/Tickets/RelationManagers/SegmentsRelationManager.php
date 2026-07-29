<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SegmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'segments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('flight_id')
                ->relationship('flight', 'flight_number')
                ->searchable()
                ->required(),
            Select::make('booking_class_id')
                ->relationship('bookingClass', 'code')
                ->preload()
                ->required(),
            Select::make('coupon_status')
                ->options(['open' => 'Open', 'used' => 'Used', 'void' => 'Void', 'refunded' => 'Refunded'])
                ->default('open')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('coupon_status')
            ->columns([
                TextColumn::make('flight.flight_number')->label('Flight')->searchable()->sortable(),
                TextColumn::make('bookingClass.code')->label('Class')->sortable(),
                TextColumn::make('coupon_status')->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
