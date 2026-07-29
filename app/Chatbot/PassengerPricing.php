<?php

namespace App\Chatbot;

class PassengerPricing
{
    private const float CHILD_FARE_RATIO = 0.75;

    private const float INFANT_FARE_RATIO = 0.10;

    private const int TAX_PER_SEATED_PASSENGER_USD = 240;

    /**
     * @return array<string, float|int>
     */
    public function breakdown(int $basePriceUsd, int $adults, int $children, int $babies): array
    {
        $adultBase = $basePriceUsd * $adults;
        $childBase = $basePriceUsd * self::CHILD_FARE_RATIO * $children;
        $infantBase = $basePriceUsd * self::INFANT_FARE_RATIO * $babies;
        $taxes = self::TAX_PER_SEATED_PASSENGER_USD * ($adults + $children);

        return [
            'base_price_usd' => $basePriceUsd,
            'adults' => $adults,
            'children' => $children,
            'babies' => $babies,
            'adult_base_usd' => round($adultBase, 2),
            'child_base_usd' => round($childBase, 2),
            'infant_base_usd' => round($infantBase, 2),
            'taxes_usd' => $taxes,
            'grand_total_usd' => round($adultBase + $childBase + $infantBase + $taxes, 2),
        ];
    }
}
