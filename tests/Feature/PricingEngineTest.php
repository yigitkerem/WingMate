<?php

use App\Models\BundleService;
use App\Models\Offer;
use App\Models\PricingRule;
use App\Models\Service;
use App\Models\ServiceConstraint;
use App\Models\ServicePrice;
use App\Pricing\OfferBuilder;
use App\Services\TurkishAirlines\TurkishAirlinesMcpGateway;
use Illuminate\Validation\ValidationException;

test('pricing rules apply discounts from expression context', function () {
    $fixture = createSellablePricingFixture();
    PricingRule::query()->create([
        'name' => 'Route discount',
        'priority' => 1,
        'active' => true,
        'stackable' => true,
        'condition_expression' => 'route == "IST-LHR"',
        'actions' => [['type' => 'percentage_discount', 'value' => 10, 'label' => 'Route discount']],
    ]);

    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0);

    expect((float) $offer->discount)->toBe(12.5)
        ->and((float) $offer->total_price)->toBe(112.5);
});

test('service constraints block nonsensical custom bundles', function () {
    $fixture = createSellablePricingFixture();
    ServiceConstraint::query()
        ->where('service_id', $fixture['checkedBag']->id)
        ->update(['type' => 'conflicts', 'related_service_id' => $fixture['cabinBag']->id]);

    app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0, null, [
        ['service_code' => 'CHECKED_BAG', 'quantity' => 1],
    ]);
})->throws(ValidationException::class);

test('checked baggage customizations are priced by additional kilograms', function () {
    $fixture = createSellablePricingFixture();

    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0, null, [
        ['service_code' => 'CHECKED_BAG', 'value' => ['amount' => 30]],
    ]);

    $checkedBag = $offer->offerServices->firstWhere('service_id', $fixture['checkedBag']->id);

    expect((float) $offer->services_total)->toBe(28.0)
        ->and((float) $offer->total_price)->toBe(153.0)
        ->and($checkedBag->value)->toBe(['amount' => 30])
        ->and($checkedBag->quantity)->toBe(30)
        ->and((float) $checkedBag->price)->toBe(28.0);
});

test('custom baggage does not downgrade a better package allowance', function () {
    $fixture = createSellablePricingFixture();

    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0, null, [
        ['service_code' => 'CHECKED_BAG', 'value' => ['amount' => 15]],
    ]);

    $checkedBag = $offer->offerServices->firstWhere('service_id', $fixture['checkedBag']->id);

    expect((float) $offer->services_total)->toBe(0.0)
        ->and((float) $offer->total_price)->toBe(125.0)
        ->and($checkedBag->value)->toBe(['amount' => 23])
        ->and($checkedBag->source)->toBe('bundle');
});

test('checked baggage constraints use kilograms instead of bag count', function () {
    $fixture = createSellablePricingFixture();
    ServiceConstraint::query()
        ->where('service_id', $fixture['checkedBag']->id)
        ->where('type', 'requires')
        ->delete();
    ServiceConstraint::query()->create([
        'service_id' => $fixture['checkedBag']->id,
        'type' => 'max_quantity',
        'parameters' => ['quantity' => 50],
        'message' => 'At most 50 kg of checked baggage can be selected.',
    ]);

    app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0, null, [
        ['service_code' => 'CHECKED_BAG', 'value' => ['amount' => 51]],
    ]);
})->throws(ValidationException::class, 'At most 50 kg of checked baggage can be selected.');

test('tiered service upgrades replace lower bundled tiers and charge the difference', function () {
    $fixture = createSellablePricingFixture();
    $wifi = Service::query()->create([
        'code' => 'WIFI',
        'name' => 'Wi-Fi 250 MB',
        'category' => 'CONNECTIVITY',
        'value_type' => 'integer',
        'default_unit' => 'MB',
    ]);
    $wifi1Gb = Service::query()->create([
        'code' => 'WIFI_1GB',
        'name' => 'Wi-Fi 1 GB',
        'category' => 'CONNECTIVITY',
        'value_type' => 'integer',
        'default_unit' => 'MB',
    ]);
    BundleService::query()->create([
        'bundle_id' => $fixture['bundle']->id,
        'service_id' => $wifi->id,
        'included_value' => ['amount' => 250, 'data_mb' => 250],
        'included' => true,
    ]);
    ServicePrice::query()->create(['service_id' => $wifi->id, 'unit_price' => 6, 'max_quantity' => 1]);
    ServicePrice::query()->create(['service_id' => $wifi1Gb->id, 'unit_price' => 12, 'max_quantity' => 1]);

    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0, null, [
        ['service_code' => 'WIFI_1GB', 'value' => ['amount' => 1024, 'data_mb' => 1024]],
    ]);

    $serviceCodes = $offer->offerServices->pluck('service.code')->all();
    $wifiUpgrade = $offer->offerServices->firstWhere('service_id', $wifi1Gb->id);

    expect($serviceCodes)->not->toContain('WIFI')
        ->and($serviceCodes)->toContain('WIFI_1GB')
        ->and((float) $offer->services_total)->toBe(6.0)
        ->and((float) $wifiUpgrade->price)->toBe(6.0);
});

test('generic tier aliases do not replace richer explicit customer tiers', function () {
    $fixture = createSellablePricingFixture();
    $wifi = Service::query()->create([
        'code' => 'WIFI',
        'name' => 'Wi-Fi 250 MB',
        'category' => 'CONNECTIVITY',
        'value_type' => 'integer',
        'default_unit' => 'MB',
    ]);
    $wifi1Gb = Service::query()->create([
        'code' => 'WIFI_1GB',
        'name' => 'Wi-Fi 1 GB',
        'category' => 'CONNECTIVITY',
        'value_type' => 'integer',
        'default_unit' => 'MB',
    ]);
    ServicePrice::query()->create(['service_id' => $wifi->id, 'unit_price' => 6, 'max_quantity' => 1]);
    ServicePrice::query()->create(['service_id' => $wifi1Gb->id, 'unit_price' => 12, 'max_quantity' => 1]);

    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0, null, [
        ['service_code' => 'WIFI_1GB', 'value' => ['amount' => 1024, 'data_mb' => 1024]],
        ['service_code' => 'WIFI', 'value' => ['amount' => 250, 'data_mb' => 250]],
    ]);

    $serviceCodes = $offer->offerServices->pluck('service.code')->all();

    expect($serviceCodes)->toContain('WIFI_1GB')
        ->and($serviceCodes)->not->toContain('WIFI')
        ->and((float) $offer->services_total)->toBe(12.0);
});

test('thy mcp gateway reports configuration status and stores fakeable search shape', function () {
    config()->set('services.thy_mcp.url', null);

    $gateway = app(TurkishAirlinesMcpGateway::class);
    $result = $gateway->search(['origin' => 'IST', 'destination' => 'LHR']);

    expect($gateway->status()['configured'])->toBeFalse()
        ->and($result['status'])->toBe('not_configured')
        ->and($result['items'])->toBe([]);
});

test('offers are immutable snapshots when fare changes later', function () {
    $fixture = createSellablePricingFixture();
    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0);
    $fixture['baseFare']->update(['base_price' => 999]);

    expect((float) $offer->refresh()->total_price)->toBe(125.0)
        ->and(Offer::query()->first()->fare_basis_code)->toBe('QEXTRAFLYOW1');
});
