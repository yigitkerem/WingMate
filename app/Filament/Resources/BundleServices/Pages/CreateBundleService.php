<?php

namespace App\Filament\Resources\BundleServices\Pages;

use App\Filament\Resources\BundleServices\BundleServiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBundleService extends CreateRecord
{
    protected static string $resource = BundleServiceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return BundleServiceResource::mutateIncludedValueData($data);
    }
}
