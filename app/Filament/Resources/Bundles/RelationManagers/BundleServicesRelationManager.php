<?php

namespace App\Filament\Resources\Bundles\RelationManagers;

use App\Filament\Resources\BundleServices\BundleServiceResource as BundleServiceAdminResource;
use App\Models\Bundle;
use App\Models\BundleService;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BundleServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'bundleServices';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')
                ->relationship('service', 'name')
                ->searchable()
                ->preload()
                ->live()
                ->required(),
            ...BundleServiceAdminResource::includedValueFormComponents(),
            Toggle::make('included')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('service.name')
            ->columns([
                TextColumn::make('service.code')->label('Code')->searchable()->sortable(),
                TextColumn::make('service.name')->label('Service')->searchable(),
                TextColumn::make('service.category')->label('Category')->sortable(),
                TextColumn::make('included_value')->label('Included value')->placeholder('-'),
                IconColumn::make('included')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => BundleServiceAdminResource::mutateIncludedValueData($data)),
                Action::make('addAllServices')
                    ->label('Add all active services')
                    ->action(function (): void {
                        $bundle = $this->getOwnerRecord();

                        if (! $bundle instanceof Bundle) {
                            return;
                        }

                        Service::query()
                            ->where('active', true)
                            ->get()
                            ->each(fn (Service $service): BundleService => BundleService::query()->firstOrCreate([
                                'bundle_id' => $bundle->id,
                                'service_id' => $service->id,
                            ], [
                                'included_value' => null,
                                'included' => false,
                            ]));
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => BundleServiceAdminResource::fillIncludedValueData($data))
                    ->mutateDataUsing(fn (array $data): array => BundleServiceAdminResource::mutateIncludedValueData($data)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
