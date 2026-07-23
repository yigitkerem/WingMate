<?php

use Database\Seeders\AirlineDemoSeeder;

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
