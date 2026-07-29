<?php

use App\Models\Offer;
use App\Models\PricingRule;
use App\Models\ServiceConstraint;
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
