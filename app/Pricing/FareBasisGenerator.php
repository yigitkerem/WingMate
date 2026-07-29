<?php

namespace App\Pricing;

use App\Models\BaseFare;
use Illuminate\Support\Str;

class FareBasisGenerator
{
    public function generate(BaseFare $baseFare): string
    {
        $bundle = $baseFare->bundle;
        $bookingClass = $baseFare->bookingClass;
        $suffix = $baseFare->trip_type === 'round_trip' ? 'RT' : 'OW';

        return Str::of($baseFare->fare_basis_template)
            ->replace('{class}', $bookingClass->code)
            ->replace('{bundle}', $bundle->code)
            ->replace('{trip}', $suffix)
            ->replace('{leg}', (string) $baseFare->leg_index)
            ->upper()
            ->toString();
    }
}
