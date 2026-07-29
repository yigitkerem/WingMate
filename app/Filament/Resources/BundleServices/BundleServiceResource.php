<?php

namespace App\Filament\Resources\BundleServices;

use App\Filament\Resources\BundleServices\Pages\CreateBundleService;
use App\Filament\Resources\BundleServices\Pages\EditBundleService;
use App\Filament\Resources\BundleServices\Pages\ListBundleServices;
use App\Models\BundleService;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BundleServiceResource extends Resource
{
    protected static ?string $model = BundleService::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static \UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('bundle_id')
                ->relationship('bundle', 'name')
                ->preload()
                ->required(),
            Select::make('service_id')
                ->relationship('service', 'name')
                ->preload()
                ->live()
                ->required(),
            ...self::includedValueFormComponents(),
            Toggle::make('included')->default(true),
        ]);
    }

    /**
     * @return array<int, Component>
     */
    public static function includedValueFormComponents(): array
    {
        return [
            Toggle::make('included_value_boolean')
                ->label('Included value')
                ->visible(fn (Get $get, ?BundleService $record): bool => self::serviceValueType($get('service_id'), $record) === 'boolean'),
            TextInput::make('included_value_amount')
                ->label('Included amount')
                ->numeric()
                ->visible(fn (Get $get, ?BundleService $record): bool => in_array(self::serviceValueType($get('service_id'), $record), ['integer', 'decimal'], true)),
            TextInput::make('included_value_text')
                ->label('Included value')
                ->visible(fn (Get $get, ?BundleService $record): bool => self::serviceValueType($get('service_id'), $record) === 'string'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillIncludedValueData(array $data): array
    {
        $value = $data['included_value'] ?? null;

        $data['included_value_boolean'] = is_bool($value) ? $value : (bool) ($value['amount'] ?? $value ?? false);
        $data['included_value_amount'] = is_array($value) ? ($value['amount'] ?? null) : (is_numeric($value) ? $value : null);
        $data['included_value_text'] = is_array($value) ? ($value['value'] ?? null) : (is_string($value) ? $value : null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateIncludedValueData(array $data): array
    {
        $valueType = self::serviceValueType($data['service_id'] ?? null);

        $data['included_value'] = match ($valueType) {
            'boolean' => (bool) ($data['included_value_boolean'] ?? false),
            'integer' => ['amount' => filled($data['included_value_amount'] ?? null) ? (int) $data['included_value_amount'] : null],
            'decimal' => ['amount' => filled($data['included_value_amount'] ?? null) ? (float) $data['included_value_amount'] : null],
            'string' => $data['included_value_text'] ?? null,
            default => $data['included_value'] ?? null,
        };

        unset($data['included_value_boolean'], $data['included_value_amount'], $data['included_value_text']);

        return $data;
    }

    private static function serviceValueType(mixed $serviceId, ?BundleService $record = null): ?string
    {
        if (filled($serviceId)) {
            return Service::query()->whereKey($serviceId)->value('value_type');
        }

        return $record?->service?->value_type ?? $record?->service()->value('value_type');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('bundle.code')->sortable(),
            TextColumn::make('service.code')->sortable(),
            IconColumn::make('included')->boolean(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBundleServices::route('/'), 'create' => CreateBundleService::route('/create'), 'edit' => EditBundleService::route('/{record}/edit')];
    }
}
