<?php

namespace App\Filament\Resources\PricingRules;

use App\Filament\Resources\PricingRules\Pages\CreatePricingRule;
use App\Filament\Resources\PricingRules\Pages\EditPricingRule;
use App\Filament\Resources\PricingRules\Pages\ListPricingRules;
use App\Filament\Resources\PricingRules\RelationManagers\ExecutionLogsRelationManager;
use App\Models\PricingRule;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PricingRuleResource extends Resource
{
    protected static ?string $model = PricingRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static \UnitEnum|string|null $navigationGroup = 'Pricing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            Select::make('preset')->options([
                'weekend' => 'Weekend uplift',
                'route_surcharge' => 'Route surcharge',
                'history_discount' => 'Repeat-route discount',
                'loyalty_service' => 'Loyalty service',
                'roundtrip_discount' => 'Roundtrip discount',
                'corporate_fixed' => 'Corporate fixed fare',
            ]),
            TextInput::make('priority')->numeric()->default(100)->required(),
            Toggle::make('active')->default(true),
            Toggle::make('stackable')->default(true),
            Textarea::make('condition_expression')->required()->columnSpanFull(),
            Repeater::make('actions')
                ->schema([
                    Select::make('type')->options([
                        'percentage_discount' => 'Percentage discount',
                        'percentage_surcharge' => 'Percentage surcharge',
                        'fixed_discount' => 'Fixed discount',
                        'fixed_fare' => 'Fixed fare',
                        'include_service' => 'Include service',
                    ])->required(),
                    TextInput::make('value')->numeric(),
                    TextInput::make('service_code')->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
                    TextInput::make('quantity')->numeric()->default(1),
                    TextInput::make('label'),
                    TextInput::make('code'),
                ])
                ->defaultItems(1)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('preset')->sortable(),
            TextColumn::make('priority')->sortable(),
            IconColumn::make('active')->boolean(),
            IconColumn::make('stackable')->boolean(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [
            ExecutionLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListPricingRules::route('/'), 'create' => CreatePricingRule::route('/create'), 'edit' => EditPricingRule::route('/{record}/edit')];
    }
}
