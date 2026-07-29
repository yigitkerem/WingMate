<?php

namespace App\Filament\Resources\PriceImportBatches\Pages;

use App\Filament\Resources\PriceImportBatches\PriceImportBatchResource;
use App\Services\TurkishAirlines\TurkishAirlinesFareImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPriceImportBatches extends ListRecords
{
    protected static string $resource = PriceImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('thySearch')
                ->label('Search THY MCP')
                ->schema([
                    TextInput::make('origin')->default('IST')->required()->maxLength(3),
                    TextInput::make('destination')->default('LHR')->required()->maxLength(3),
                    DatePicker::make('date')->default(now()->addWeek())->required(),
                    DatePicker::make('return_date'),
                    Select::make('trip_type')->options(['one_way' => 'One way', 'round_trip' => 'Round trip'])->default('one_way')->required(),
                    TextInput::make('adults')->numeric()->default(1)->required(),
                    TextInput::make('children')->numeric()->default(0)->required(),
                    TextInput::make('babies')->numeric()->default(0)->required(),
                ])
                ->action(function (array $data, TurkishAirlinesFareImporter $importer): void {
                    $batch = $importer->preview($data, auth()->id());

                    $notification = Notification::make()
                        ->title('THY preview created')
                        ->body($batch->message ?? 'Select the fares to import from the preview table.');

                    ($batch->status === 'preview' ? $notification->success() : $notification->warning())->send();

                    $this->redirect(PriceImportBatchResource::getUrl('view', ['record' => $batch]));
                }),
            Action::make('thyBulkSearch')
                ->label('Bulk THY MCP search')
                ->schema([
                    Textarea::make('routes')
                        ->default("IST-ERC\nERC-IST")
                        ->required()
                        ->rows(6)
                        ->helperText('One route per line, for example IST-ERC or ERC,IST.'),
                    DatePicker::make('date_from')->default(now()->addWeek())->required(),
                    DatePicker::make('date_until')->default(now()->addWeek())->required(),
                    Select::make('trip_types')
                        ->options(['one_way' => 'One way', 'round_trip' => 'Round trip'])
                        ->multiple()
                        ->default(['one_way'])
                        ->required(),
                    TextInput::make('return_after_days')->numeric()->default(3)->required(),
                    TextInput::make('adults')->numeric()->default(1)->required(),
                    TextInput::make('children')->numeric()->default(0)->required(),
                    TextInput::make('babies')->numeric()->default(0)->required(),
                ])
                ->action(function (array $data, TurkishAirlinesFareImporter $importer): void {
                    $batch = $importer->previewBulk($data, auth()->id());

                    $notification = Notification::make()
                        ->title('Bulk THY preview created')
                        ->body($batch->message ?? 'Select the fares to import from the preview table.');

                    (in_array($batch->status, ['preview', 'partial_preview'], true) ? $notification->success() : $notification->warning())->send();

                    $this->redirect(PriceImportBatchResource::getUrl('view', ['record' => $batch]));
                }),
        ];
    }
}
