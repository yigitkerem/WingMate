<?php

namespace App\Filament\Resources\Bundles\Pages;

use App\Filament\Resources\Bundles\BundleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBundles extends ListRecords
{
    protected static string $resource = BundleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
