<?php

use App\Models\Flight;
use Database\Seeders\AirlineDemoSeeder;

test('default A B C seed templates keep their booking engine fare rules', function () {
    expect(Flight::defaultAvailabilityTemplates(120, 190, 400))->toMatchArray([
        'A' => [
            'class' => 'Economy Light',
            'checked_baggage_kg' => 15,
            'cabin_baggage_kg' => 8,
            'seat_selection_free' => false,
            'change_fee_usd' => 120,
            'refund_fee_usd' => 120,
            'latest_refund_hours' => null,
            'latest_change_hours' => null,
            'class_letters' => 'A',
            'base_price_usd' => 120,
            'count_available' => 18,
            'fare_type' => 'one_way',
        ],
        'B' => [
            'class' => 'Economy Flex',
            'checked_baggage_kg' => 20,
            'cabin_baggage_kg' => 8,
            'seat_selection_free' => true,
            'change_fee_usd' => 30,
            'refund_fee_usd' => 50,
            'latest_refund_hours' => 12,
            'latest_change_hours' => 12,
            'class_letters' => 'B',
            'base_price_usd' => 190,
            'count_available' => 14,
            'fare_type' => 'one_way',
        ],
        'C' => [
            'class' => 'Business',
            'checked_baggage_kg' => 25,
            'cabin_baggage_kg' => 8,
            'seat_selection_free' => true,
            'change_fee_usd' => 0,
            'refund_fee_usd' => 0,
            'latest_refund_hours' => 6,
            'latest_change_hours' => 6,
            'class_letters' => 'C',
            'base_price_usd' => 400,
            'count_available' => 8,
            'fare_type' => 'one_way',
        ],
    ]);
});

test('demo seeder defines every requested fare feature combination', function () {
    $combinations = AirlineDemoSeeder::fareFeatureCombinations();

    $combinationKeys = $combinations->map(fn (array $combination): string => implode('|', [
        $combination['checked_baggage_kg'],
        $combination['cabin_baggage_kg'],
        $combination['seat_selection_free'] ? 'free_seats' : 'paid_seats',
        $combination['refund_paid'] ? 'paid_refund' : "refund_{$combination['latest_refund_hours']}",
        $combination['change_paid'] ? 'paid_change' : "change_{$combination['latest_change_hours']}",
    ]));

    expect($combinations)->toHaveCount(216)
        ->and($combinationKeys->unique())->toHaveCount(216)
        ->and($combinations->pluck('checked_baggage_kg')->unique()->sort()->values()->all())->toBe([0, 5, 10, 15, 20, 25])
        ->and($combinations->pluck('cabin_baggage_kg')->unique()->sort()->values()->all())->toBe([0, 8])
        ->and($combinations->pluck('seat_selection_free')->unique()->sort()->values()->all())->toBe([false, true])
        ->and($combinations->where('refund_paid', false)->pluck('latest_refund_hours')->unique()->sort()->values()->all())->toBe([12, 72])
        ->and($combinations->where('refund_paid', true)->every(fn (array $combination): bool => $combination['latest_refund_hours'] === null))->toBeTrue()
        ->and($combinations->where('change_paid', false)->pluck('latest_change_hours')->unique()->sort()->values()->all())->toBe([6, 36])
        ->and($combinations->where('change_paid', true)->every(fn (array $combination): bool => $combination['latest_change_hours'] === null))->toBeTrue();
});
