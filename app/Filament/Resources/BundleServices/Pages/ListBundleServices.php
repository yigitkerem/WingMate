<?php

namespace App\Filament\Resources\BundleServices\Pages;

use App\Filament\Resources\BundleServices\BundleServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBundleServices extends ListRecords
{
    protected static string $resource = BundleServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
