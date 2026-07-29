<?php

namespace App\Services\TurkishAirlines;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class TurkishAirlinesFareMapper
{
    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $searchParameters
     * @return array<int, array<string, mixed>>
     */
    public function fromMcpResponse(array $response, array $searchParameters): array
    {
        return collect($this->importablePayloads($this->unwrapJsonRpc($response)))
            ->map(fn (array $payload): array => $this->mapPayload($payload, $searchParameters))
            ->filter(fn (array $payload): bool => filled($payload['flight_number']) && (float) $payload['base_price'] > 0)
            ->unique(fn (array $payload): string => implode('|', [
                $payload['flight_number'],
                $payload['origin'],
                $payload['destination'],
                $payload['departure_at'],
                $payload['booking_class'],
                $payload['bundle'],
                $payload['trip_type'],
                $payload['leg_index'],
            ]))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $searchParameters
     * @return array<string, mixed>
     */
    private function mapPayload(array $payload, array $searchParameters): array
    {
        $origin = mb_strtoupper((string) ($this->value($payload, ['origin', 'originAirportCode', 'departureAirportCode', 'from', 'flight.originAirportCode', 'segment.originAirportCode', 'segments.0.originAirportCode', 'flightSegments.0.originAirportCode']) ?? $searchParameters['origin']));
        $destination = mb_strtoupper((string) ($this->value($payload, ['destination', 'destinationAirportCode', 'arrivalAirportCode', 'to', 'flight.destinationAirportCode', 'segment.destinationAirportCode', 'segments.0.destinationAirportCode', 'flightSegments.0.destinationAirportCode']) ?? $searchParameters['destination']));
        $departureAt = $this->dateTime($this->value($payload, ['departure_at', 'departureAt', 'departureDateTime', 'departureDate', 'flight.departureDateTime', 'segment.departureDateTime', 'segments.0.departureDateTime', 'flightSegments.0.departureDateTime']), (string) $searchParameters['date']);
        $arrivalAt = $this->dateTime($this->value($payload, ['arrival_at', 'arrivalAt', 'arrivalDateTime', 'arrivalDate', 'flight.arrivalDateTime', 'segment.arrivalDateTime', 'segments.0.arrivalDateTime', 'flightSegments.0.arrivalDateTime']), $departureAt->addMinutes(180)->toDateTimeString());
        $durationMinutes = (int) ($this->value($payload, ['duration_minutes', 'durationMinutes', 'duration', 'flight.durationMinutes', 'segment.durationMinutes', 'segments.0.durationMinutes', 'flightSegments.0.durationMinutes']) ?? $departureAt->diffInMinutes($arrivalAt));
        $bundle = $this->bundleCode((string) ($this->value($payload, ['bundle', 'bundleCode', 'fareFamily', 'fareFamilyCode', 'fareBrand', 'brandName', 'brandedFareName', 'cabinBrand', 'price.fareFamily']) ?? ''));
        $bookingClass = mb_strtoupper((string) ($this->value($payload, ['booking_class', 'bookingClass', 'bookingClassCode', 'reservationBookingDesignator', 'fareClass', 'class', 'classCode', 'price.bookingClass']) ?? ''));
        $cabin = $this->cabinCode((string) ($this->value($payload, ['cabin', 'cabinCode', 'cabinClass', 'travelClass', 'price.cabin']) ?? "{$bookingClass} {$bundle}"));

        if ($bookingClass === '') {
            $bookingClass = $cabin === 'BUSINESS' ? 'J' : 'Q';
        }

        $taxes = $this->money($this->value($payload, ['taxes', 'tax', 'taxAmount', 'price.taxes', 'price.taxAmount']));
        $fees = $this->money($this->value($payload, ['fees', 'fee', 'surcharge', 'price.fees', 'price.surcharge']));
        $basePrice = $this->money($this->value($payload, ['base_price', 'basePrice', 'farePrice', 'price.basePrice', 'price.farePrice', 'amount', 'price.amount', 'total', 'totalPrice', 'price.total']));

        return [
            'flight_number' => mb_strtoupper((string) ($this->value($payload, ['flight_number', 'flightNumber', 'flightNo', 'marketingFlightNumber', 'operatingFlightNumber', 'flight.flightNumber', 'segment.flightNumber', 'segments.0.flightNumber', 'flightSegments.0.flightNumber']) ?? '')),
            'origin' => $origin,
            'destination' => $destination,
            'departure_at' => $departureAt->toDateTimeString(),
            'arrival_at' => $arrivalAt->toDateTimeString(),
            'duration_minutes' => max(0, $durationMinutes),
            'aircraft_type' => (string) ($this->value($payload, ['aircraft_type', 'aircraftType', 'equipment', 'aircraft', 'flight.aircraftType', 'segments.0.aircraftType', 'flightSegments.0.aircraftType']) ?? 'Turkish Airlines aircraft'),
            'cabin' => $cabin,
            'booking_class' => $bookingClass,
            'bundle' => $bundle,
            'trip_type' => (string) ($searchParameters['trip_type'] ?? 'one_way'),
            'leg_index' => (int) ($this->value($payload, ['leg_index', 'legIndex', 'segmentIndex']) ?? 1),
            'currency' => mb_strtoupper((string) ($this->value($payload, ['currency', 'price.currency']) ?? 'USD')),
            'base_price' => $basePrice,
            'taxes' => $taxes,
            'fees' => $fees,
            'fare_basis_template' => (string) ($this->value($payload, ['fare_basis_template', 'fareBasisTemplate']) ?? '{class}{bundle}{trip}{leg}'),
            'class_letters' => (string) ($this->value($payload, ['class_letters', 'classLetters', 'fareBasisCode']) ?? $bookingClass),
            'available' => max(0, (int) ($this->value($payload, ['available', 'seatsAvailable', 'seatsLeft', 'availability', 'inventory.available']) ?? 9)),
            'capacity' => max(1, (int) ($this->value($payload, ['capacity', 'inventory.capacity']) ?? 9)),
            'raw_summary' => Arr::only($payload, ['flightNumber', 'flight_number', 'fareFamily', 'fareBrand', 'price', 'total', 'totalPrice']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrapJsonRpc(array $payload): array
    {
        if (isset($payload['result']) && is_array($payload['result'])) {
            return $this->unwrapJsonRpc($payload['result']);
        }

        foreach (['content', 'items', 'flights', 'offers', 'results'] as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                continue;
            }

            $decoded = [];

            foreach ($payload[$key] as $item) {
                if (is_array($item) && is_string($item['text'] ?? null)) {
                    $json = json_decode($item['text'], true);
                    $decoded[] = is_array($json) ? $json : ['text' => $item['text']];

                    continue;
                }

                $decoded[] = $item;
            }

            return [$key => $decoded];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function importablePayloads(array $payload): array
    {
        $thyPayloads = $this->turkishAirlinesOptionPayloads($payload);

        if ($thyPayloads !== []) {
            return $thyPayloads;
        }

        if ($this->looksImportable($payload)) {
            return [$payload];
        }

        $candidates = [];

        foreach ($payload as $value) {
            if (is_array($value)) {
                $candidates = [...$candidates, ...$this->importablePayloads($value)];
            }
        }

        return collect($candidates)
            ->unique(fn (array $candidate): string => md5(json_encode($candidate) ?: ''))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function looksImportable(array $payload): bool
    {
        $flightNumber = $this->value($payload, ['flight_number', 'flightNumber', 'flightNo', 'marketingFlightNumber', 'operatingFlightNumber', 'flight.flightNumber', 'flight.flightCode.flightNumber', 'segment.flightNumber', 'segments.0.flightNumber', 'flightSegments.0.flightNumber', 'flightSegments.0.flightCode.flightNumber']);
        $price = $this->value($payload, ['base_price', 'basePrice', 'farePrice', 'price.basePrice', 'price.farePrice', 'amount', 'price.amount', 'total', 'totalPrice', 'price.total', 'passengerFare.grandTotalFare.amount', 'passengerFare.totalFare.amount']);

        return filled($flightNumber) && $this->money($price) > 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function turkishAirlinesOptionPayloads(array $payload): array
    {
        $originDestinations = data_get($payload, 'originDestinationInformations');

        if (! is_array($originDestinations)) {
            return [];
        }

        $mappedPayloads = [];

        foreach ($originDestinations as $originDestinationIndex => $originDestination) {
            if (! is_array($originDestination)) {
                continue;
            }

            foreach ((array) data_get($originDestination, 'originDestinationOptions', []) as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $segment = data_get($option, 'flightSegments.0');

                if (! is_array($segment)) {
                    continue;
                }

                foreach ((array) data_get($option, 'bookingPriceInfos', []) as $priceInfo) {
                    if (! is_array($priceInfo)) {
                        continue;
                    }

                    $mappedPayloads[] = [
                        'flightNumber' => data_get($segment, 'flightCode.airlineCode', 'TK').data_get($segment, 'flightCode.flightNumber'),
                        'originAirportCode' => data_get($segment, 'departureAirportCode', data_get($originDestination, 'originLocation')),
                        'destinationAirportCode' => data_get($segment, 'arrivalAirportCode', data_get($originDestination, 'destinationLocation')),
                        'departureDateTime' => data_get($segment, 'departureDateTime'),
                        'arrivalDateTime' => data_get($segment, 'arrivalDateTime'),
                        'durationMinutes' => $this->durationMinutes(data_get($option, 'journeyDuration')),
                        'aircraftType' => data_get($segment, 'equipment', 'Turkish Airlines aircraft'),
                        'fareFamily' => $this->brandCodeToBundle((string) data_get($priceInfo, 'brandCodeList.0', data_get($priceInfo, 'brandCodeListBySegment.0'))),
                        'bookingClass' => data_get($priceInfo, 'resBookDesigCodeList.0', data_get($priceInfo, 'bookingPriceType')),
                        'cabin' => data_get($priceInfo, 'bookingPriceType'),
                        'fareBasisCode' => data_get($priceInfo, 'fareBasisCodeList.0'),
                        'leg_index' => ((int) $originDestinationIndex) + 1,
                        'price' => [
                            'basePrice' => data_get($priceInfo, 'passengerFare.grandTotalFare.amount', data_get($priceInfo, 'passengerFare.totalFare.amount')),
                            'currency' => data_get($priceInfo, 'passengerFare.grandTotalFare.currencyCode', data_get($priceInfo, 'passengerFare.totalFare.currencyCode', data_get($option, 'cheapestPriceCurrency', 'TRY'))),
                        ],
                        'seatsAvailable' => 9,
                        'optionId' => data_get($option, 'optionId'),
                        'recommendationId' => data_get($priceInfo, 'recommendationId'),
                        'recommended' => (bool) data_get($priceInfo, 'recommended', false),
                    ];
                }
            }
        }

        return $mappedPayloads;
    }

    private function durationMinutes(mixed $duration): ?int
    {
        if (! is_numeric($duration)) {
            return null;
        }

        $duration = (int) $duration;

        return $duration > 1000 ? (int) round($duration / 60000) : $duration;
    }

    private function brandCodeToBundle(string $brandCode): string
    {
        return match (mb_strtoupper($brandCode)) {
            'CL' => 'ECOFLY',
            'LG' => 'EXTRAFLY',
            'GN', 'FL' => 'PRIMEFLY',
            'BF' => 'BUSINESSFLY',
            'BL' => 'BUSINESSPRIME',
            default => $brandCode,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function value(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function dateTime(mixed $value, string $fallback): CarbonImmutable
    {
        if (is_array($value)) {
            $value = $value['departureDate'] ?? $value['arrivalDate'] ?? $value['date'] ?? $value['value'] ?? null;
        }

        $value = (string) ($value ?: $fallback);

        if (preg_match('/^\d{2}-\d{2}-\d{4}/', $value) === 1) {
            return CarbonImmutable::createFromFormat('d-m-Y H:i', Str::contains($value, ':') ? $value : "{$value} 00:00");
        }

        return CarbonImmutable::parse($value);
    }

    private function money(mixed $value): float
    {
        if (is_array($value)) {
            $value = $value['amount'] ?? $value['value'] ?? null;
        }

        if (is_string($value)) {
            $value = preg_replace('/[^0-9.\-]/', '', $value);
        }

        return round((float) $value, 2);
    }

    private function bundleCode(string $value): string
    {
        $normalized = Str::of($value)->lower()->replace([' ', '-', '_'], '');

        return match (true) {
            $normalized->contains('businessprime') => 'BUSINESSPRIME',
            $normalized->contains('business') => 'BUSINESSFLY',
            $normalized->contains('prime') => 'PRIMEFLY',
            $normalized->contains('eco') => 'ECOFLY',
            $normalized->contains('extra') => 'EXTRAFLY',
            default => 'EXTRAFLY',
        };
    }

    private function cabinCode(string $value): string
    {
        $normalized = Str::of($value)->lower();

        return $normalized->contains('business') || $normalized->contains('j') || $normalized->contains('d')
            ? 'BUSINESS'
            : 'ECONOMY';
    }
}
