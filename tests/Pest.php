<?php

use App\Models\Airport;
use App\Models\BaseFare;
use App\Models\BookingClass;
use App\Models\Bundle;
use App\Models\BundleService;
use App\Models\Cabin;
use App\Models\Flight;
use App\Models\FlightInventory;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConstraint;
use App\Models\ServicePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * @return array<string, mixed>
 */
function createSellablePricingFixture(array $overrides = []): array
{
    $origin = Airport::query()->firstOrCreate(['iata_code' => $overrides['origin'] ?? 'IST'], [
        'icao_code' => null,
        'name' => ($overrides['origin'] ?? 'IST').' Airport',
        'city' => 'Demo',
        'country' => 'TR',
        'timezone' => 'Europe/Istanbul',
    ]);
    $destination = Airport::query()->firstOrCreate(['iata_code' => $overrides['destination'] ?? 'LHR'], [
        'icao_code' => null,
        'name' => ($overrides['destination'] ?? 'LHR').' Airport',
        'city' => 'Demo',
        'country' => 'GB',
        'timezone' => 'Europe/London',
    ]);
    $cabin = Cabin::query()->firstOrCreate(['code' => 'ECONOMY'], ['name' => 'Economy']);
    $bookingClass = BookingClass::query()->firstOrCreate(['code' => 'Q'], ['cabin_id' => $cabin->id, 'priority' => 20]);
    $product = Product::query()->firstOrCreate(['code' => 'ECONOMY'], ['name' => 'Economy']);
    $bundle = Bundle::query()->firstOrCreate(['code' => 'EXTRAFLY'], ['product_id' => $product->id, 'name' => 'ExtraFly', 'display_order' => 1, 'public' => true]);
    $cabinBag = Service::query()->firstOrCreate(['code' => 'CABIN_BAG'], ['name' => 'Cabin bag', 'category' => 'BAG', 'value_type' => 'integer', 'default_unit' => 'kg']);
    $checkedBag = Service::query()->firstOrCreate(['code' => 'CHECKED_BAG'], ['name' => 'Checked bag', 'category' => 'BAG', 'value_type' => 'integer', 'default_unit' => 'kg']);
    BundleService::query()->firstOrCreate(['bundle_id' => $bundle->id, 'service_id' => $cabinBag->id], ['included_value' => ['amount' => 8]]);
    BundleService::query()->firstOrCreate(['bundle_id' => $bundle->id, 'service_id' => $checkedBag->id], ['included_value' => ['amount' => 23]]);
    ServicePrice::query()->firstOrCreate(['service_id' => $checkedBag->id], ['unit_price' => 4, 'max_quantity' => 50]);
    ServiceConstraint::query()->firstOrCreate(['service_id' => $checkedBag->id, 'type' => 'requires'], ['related_service_id' => $cabinBag->id]);
    $departureAt = Carbon::parse($overrides['departure_at'] ?? '2026-08-10 09:00');
    $flight = Flight::query()->create([
        'flight_number' => $overrides['flight_number'] ?? 'TK100',
        'origin_airport_id' => $origin->id,
        'destination_airport_id' => $destination->id,
        'departure_at' => $departureAt,
        'arrival_at' => $departureAt->copy()->addMinutes(250),
        'duration_minutes' => 250,
        'aircraft_type' => 'Airbus A321neo',
        'status' => 'scheduled',
    ]);
    FlightInventory::query()->create(['flight_id' => $flight->id, 'booking_class_id' => $bookingClass->id, 'capacity' => 10, 'available' => $overrides['available'] ?? 5]);
    $baseFare = BaseFare::query()->create([
        'flight_id' => $flight->id,
        'booking_class_id' => $bookingClass->id,
        'bundle_id' => $bundle->id,
        'trip_type' => $overrides['trip_type'] ?? 'one_way',
        'leg_index' => $overrides['leg_index'] ?? 1,
        'base_price' => $overrides['base_price'] ?? 100,
        'taxes' => $overrides['taxes'] ?? 20,
        'fees' => $overrides['fees'] ?? 5,
        'fare_basis_template' => '{class}{bundle}{trip}{leg}',
        'class_letters' => $overrides['class_letters'] ?? 'Q',
    ]);

    return compact('origin', 'destination', 'cabin', 'bookingClass', 'product', 'bundle', 'flight', 'baseFare', 'checkedBag', 'cabinBag');
}
