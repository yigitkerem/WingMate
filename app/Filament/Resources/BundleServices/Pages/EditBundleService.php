<?php

namespace App\Filament\Resources\BundleServices\Pages;

use App\Filament\Resources\BundleServices\BundleServiceResource;
use Filament\Resources\Pages\EditRecord;

class EditBundleService extends EditRecord
{
    protected static string $resource = BundleServiceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return BundleServiceResource::fillIncludedValueData($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return BundleServiceResource::mutateIncludedValueData($data);
    }
}
