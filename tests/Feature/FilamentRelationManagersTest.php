<?php

use App\Filament\Resources\Airports\Pages\EditAirport;
use App\Filament\Resources\Bundles\Pages\EditBundle;
use App\Filament\Resources\Bundles\RelationManagers\BundleServicesRelationManager;
use App\Filament\Resources\BundleServices\Pages\EditBundleService;
use App\Filament\Resources\Cabins\Pages\EditCabin;
use App\Filament\Resources\Flights\Pages\ListFlights;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Offers\Pages\ViewOffer;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\PriceImportBatches\Pages\ListPriceImportBatches;
use App\Filament\Resources\PricingRules\Pages\ListPricingRules;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\BundleService;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Service;
use App\Models\User;
use App\Pricing\OfferBuilder;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    actingAs(User::factory()->admin()->create());
});

it('renders core offer engine resources', function () {
    $fixture = createSellablePricingFixture();
    PricingRule::query()->create([
        'name' => 'Test discount',
        'priority' => 10,
        'active' => true,
        'stackable' => true,
        'condition_expression' => 'route == "IST-LHR"',
        'actions' => [['type' => 'percentage_discount', 'value' => 10]],
    ]);
    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0);
    $order = Order::query()->create([
        'booking_reference' => 'ABC123',
        'status' => 'confirmed',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'currency' => 'USD',
        'total_price' => 125,
    ]);

    foreach ([
        ListFlights::class,
        ListServices::class,
        ListPricingRules::class,
        ListOffers::class,
        ListPriceImportBatches::class,
    ] as $page) {
        Livewire::test($page)->assertOk();
    }

    Livewire::test(EditAirport::class, ['record' => $fixture['origin']->getRouteKey()])->assertOk();
    Livewire::test(EditCabin::class, ['record' => $fixture['cabin']->getRouteKey()])->assertOk();
    Livewire::test(EditProduct::class, ['record' => $fixture['product']->getRouteKey()])->assertOk();
    Livewire::test(EditBundle::class, ['record' => $fixture['bundle']->getRouteKey()])->assertOk();
    Livewire::test(EditService::class, ['record' => $fixture['checkedBag']->getRouteKey()])->assertOk();
    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->assertOk();
    Livewire::test(ViewOffer::class, ['record' => $offer->getRouteKey()])->assertOk();

    expect(Offer::query()->count())->toBe(1);
});

it('edits boolean bundle service included values', function () {
    $fixture = createSellablePricingFixture();
    $service = Service::query()->create([
        'code' => 'CHANGE_ALLOWED',
        'name' => 'Change right',
        'category' => 'FLEXIBILITY',
        'value_type' => 'boolean',
        'default_unit' => 'trip',
    ]);
    $bundleService = BundleService::query()->create([
        'bundle_id' => $fixture['bundle']->id,
        'service_id' => $service->id,
        'included_value' => false,
        'included' => true,
    ]);

    Livewire::test(EditBundleService::class, ['record' => $bundleService->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'included_value_boolean' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($bundleService->refresh()->included_value)->toBeTrue();

    Livewire::test(BundleServicesRelationManager::class, [
        'ownerRecord' => $fixture['bundle'],
        'pageClass' => EditBundle::class,
    ])
        ->mountTableAction('edit', $bundleService)
        ->assertTableActionDataSet([
            'included_value_boolean' => true,
        ])
        ->fillForm([
            'service_id' => $service->id,
            'included_value_boolean' => false,
            'included' => true,
        ])
        ->callMountedTableAction()
        ->assertHasNoFormErrors();

    expect($bundleService->refresh()->included_value)->toBeFalse();
});
