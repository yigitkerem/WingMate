<?php

namespace App\Services\TurkishAirlines;

use App\Models\Airport;
use App\Models\BaseFare;
use App\Models\BookingClass;
use App\Models\Bundle;
use App\Models\Cabin;
use App\Models\Flight;
use App\Models\FlightInventory;
use App\Models\PriceImportBatch;
use App\Models\PriceImportItem;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class TurkishAirlinesFareImporter
{
    public function __construct(
        private readonly TurkishAirlinesMcpGateway $gateway,
        private readonly TurkishAirlinesAirportLookup $airports,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function preview(array $parameters, ?int $userId): PriceImportBatch
    {
        $normalized = $this->normalizeSearchParameters($parameters);
        $result = $this->gateway->search($normalized);
        $items = $result['items'] ?? [];

        return DB::transaction(function () use ($normalized, $result, $items, $userId): PriceImportBatch {
            $batch = PriceImportBatch::query()->create([
                'user_id' => $userId,
                'source' => 'thy_mcp',
                'status' => ($result['status'] ?? null) === 'ok' ? 'preview' : $result['status'],
                'search_parameters' => $normalized,
                'raw_response' => $result['raw'] ?? $result,
                'imported_count' => 0,
                'message' => $result['message'] ?? null,
            ]);

            foreach ($items as $item) {
                $batch->items()->create([
                    'status' => 'preview',
                    'mapped_payload' => $item,
                    'raw_payload' => $item,
                ]);
            }

            return $batch;
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function previewBulk(array $parameters, ?int $userId): PriceImportBatch
    {
        $searches = $this->bulkSearchParameters($parameters);
        $rawResponses = [];
        $items = [];
        $errors = [];

        foreach ($searches as $search) {
            $result = $this->gateway->search($search);
            $rawResponses[] = [
                'search_parameters' => $search,
                'result' => $result['raw'] ?? $result,
                'status' => $result['status'] ?? 'error',
                'message' => $result['message'] ?? null,
            ];

            foreach ($result['items'] ?? [] as $item) {
                $items[] = $item;
            }

            if (($result['status'] ?? null) !== 'ok') {
                $errors[] = trim(implode(' ', [$search['origin'].'-'.$search['destination'], $search['date'], $search['trip_type'], $result['message'] ?? 'failed']));
            }
        }

        return DB::transaction(function () use ($parameters, $searches, $rawResponses, $items, $errors, $userId): PriceImportBatch {
            $batch = PriceImportBatch::query()->create([
                'user_id' => $userId,
                'source' => 'thy_mcp',
                'status' => $items === [] ? 'error' : ($errors === [] ? 'preview' : 'partial_preview'),
                'search_parameters' => [
                    'mode' => 'bulk',
                    'input' => $parameters,
                    'search_count' => count($searches),
                ],
                'raw_response' => ['searches' => $rawResponses],
                'imported_count' => 0,
                'message' => $items === []
                    ? 'No importable THY MCP fares were found.'
                    : count($items).' THY MCP fare(s) are ready to preview across '.count($searches).' search(es).',
            ]);

            foreach ($items as $item) {
                $batch->items()->create([
                    'status' => 'preview',
                    'mapped_payload' => $item,
                    'raw_payload' => $item,
                ]);
            }

            return $batch;
        });
    }

    /**
     * @param  EloquentCollection<int, PriceImportItem>|array<int, int|string>|iterable<int, PriceImportItem>  $items
     */
    public function import(PriceImportBatch $batch, iterable $items): int
    {
        $records = $this->resolveItems($batch, $items);

        return DB::transaction(function () use ($batch, $records): int {
            $importedCount = 0;

            foreach ($records as $item) {
                if ($item->status === 'imported') {
                    continue;
                }

                $this->importItem($item);
                $importedCount++;
            }

            $batch->refresh();
            $totalImported = $batch->items()->where('status', 'imported')->count();
            $remainingPreview = $batch->items()->where('status', 'preview')->count();

            $batch->update([
                'status' => $remainingPreview > 0 ? 'partially_imported' : 'imported',
                'imported_count' => $totalImported,
                'message' => "{$totalImported} fare(s) imported from THY MCP.",
            ]);

            return $importedCount;
        });
    }

    private function importItem(PriceImportItem $item): void
    {
        $payload = $item->mapped_payload;
        $origin = $this->airport((string) $payload['origin']);
        $destination = $this->airport((string) $payload['destination']);
        $cabin = Cabin::query()->firstOrCreate(
            ['code' => $payload['cabin']],
            ['name' => ucfirst(mb_strtolower((string) $payload['cabin'])), 'display_order' => $payload['cabin'] === 'BUSINESS' ? 2 : 1],
        );
        $bookingClass = BookingClass::query()->firstOrCreate(
            ['code' => $payload['booking_class']],
            ['cabin_id' => $cabin->id, 'priority' => $payload['cabin'] === 'BUSINESS' ? 50 : 20],
        );
        $product = Product::query()->firstOrCreate(
            ['code' => $payload['cabin']],
            ['name' => ucfirst(mb_strtolower((string) $payload['cabin']))],
        );
        $bundle = Bundle::query()->firstOrCreate(
            ['code' => $payload['bundle']],
            ['product_id' => $product->id, 'name' => $this->bundleName((string) $payload['bundle']), 'public' => true],
        );

        $flight = Flight::query()->updateOrCreate(
            [
                'flight_number' => $payload['flight_number'],
                'origin_airport_id' => $origin->id,
                'destination_airport_id' => $destination->id,
                'departure_at' => $payload['departure_at'],
            ],
            [
                'arrival_at' => $payload['arrival_at'],
                'duration_minutes' => $payload['duration_minutes'],
                'aircraft_type' => $payload['aircraft_type'],
                'status' => 'scheduled',
            ],
        );

        FlightInventory::query()->updateOrCreate(
            ['flight_id' => $flight->id, 'booking_class_id' => $bookingClass->id],
            ['capacity' => max((int) $payload['capacity'], (int) $payload['available']), 'available' => (int) $payload['available']],
        );

        $fare = BaseFare::query()->updateOrCreate(
            [
                'flight_id' => $flight->id,
                'booking_class_id' => $bookingClass->id,
                'bundle_id' => $bundle->id,
                'trip_type' => $payload['trip_type'],
                'leg_index' => $payload['leg_index'],
            ],
            [
                'currency' => $payload['currency'],
                'base_price' => $payload['base_price'],
                'taxes' => $payload['taxes'],
                'fees' => $payload['fees'],
                'fare_basis_template' => $payload['fare_basis_template'],
                'class_letters' => $payload['class_letters'],
                'active' => true,
            ],
        );

        $item->update([
            'status' => 'imported',
            'flight_id' => $flight->id,
            'base_fare_id' => $fare->id,
        ]);
    }

    private function airport(string $iataCode): Airport
    {
        $airport = $this->airports->attributes($iataCode);

        return Airport::query()->firstOrCreate(
            ['iata_code' => $iataCode],
            [
                'icao_code' => $airport['icao'] ?? null,
                'name' => $airport['name'],
                'city' => $airport['city'],
                'country' => $airport['country'],
                'timezone' => $airport['timezone'],
            ],
        );
    }

    /**
     * @param  EloquentCollection<int, PriceImportItem>|array<int, int|string>|iterable<int, PriceImportItem>  $items
     * @return EloquentCollection<int, PriceImportItem>
     */
    private function resolveItems(PriceImportBatch $batch, iterable $items): EloquentCollection
    {
        if ($items instanceof EloquentCollection) {
            return $items;
        }

        $array = array_values(is_array($items) ? $items : iterator_to_array($items));

        if (empty($array)) {
            return new EloquentCollection;
        }

        if ($array[0] instanceof PriceImportItem) {
            return new EloquentCollection($array);
        }

        return $batch->items()->whereKey($array)->get();
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function normalizeSearchParameters(array $parameters): array
    {
        return [
            'origin' => mb_strtoupper((string) $parameters['origin']),
            'destination' => mb_strtoupper((string) $parameters['destination']),
            'date' => $this->dateValue($parameters['date']),
            'return_date' => filled($parameters['return_date'] ?? null) ? $this->dateValue($parameters['return_date']) : null,
            'trip_type' => (string) ($parameters['trip_type'] ?? 'one_way'),
            'adults' => (int) ($parameters['adults'] ?? 1),
            'children' => (int) ($parameters['children'] ?? 0),
            'babies' => (int) ($parameters['babies'] ?? 0),
        ];
    }

    private function bundleName(string $code): string
    {
        return match ($code) {
            'ECOFLY' => 'EcoFly',
            'EXTRAFLY' => 'ExtraFly',
            'PRIMEFLY' => 'PrimeFly',
            'BUSINESSFLY' => 'BusinessFly',
            'BUSINESSPRIME' => 'BusinessPrime',
            default => $code,
        };
    }

    private function dateValue(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<int, array<string, mixed>>
     */
    private function bulkSearchParameters(array $parameters): array
    {
        $routes = $this->parseRoutes((string) ($parameters['routes'] ?? ''));
        $dates = $this->datesBetween($parameters['date_from'], $parameters['date_until']);
        $tripTypes = (array) ($parameters['trip_types'] ?? ['one_way']);
        $returnAfterDays = max(1, (int) ($parameters['return_after_days'] ?? 3));
        $searches = [];

        foreach ($routes as [$origin, $destination]) {
            foreach ($dates as $date) {
                foreach ($tripTypes as $tripType) {
                    $searches[] = [
                        'origin' => $origin,
                        'destination' => $destination,
                        'date' => $date,
                        'return_date' => $tripType === 'round_trip'
                            ? CarbonImmutable::parse($date)->addDays($returnAfterDays)->toDateString()
                            : null,
                        'trip_type' => $tripType,
                        'adults' => (int) ($parameters['adults'] ?? 1),
                        'children' => (int) ($parameters['children'] ?? 0),
                        'babies' => (int) ($parameters['babies'] ?? 0),
                    ];
                }
            }
        }

        return $searches;
    }

    /**
     * @return array<int, array{string, string}>
     */
    private function parseRoutes(string $routes): array
    {
        return collect(preg_split('/\R/', $routes) ?: [])
            ->map(fn (string $route): string => trim($route))
            ->filter()
            ->map(function (string $route): array {
                $parts = preg_split('/\s*(?:-|>|,)\s*/', mb_strtoupper($route)) ?: [];

                return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
            })
            ->filter(fn (array $route): bool => filled($route[0]) && filled($route[1]) && $route[0] !== $route[1])
            ->unique(fn (array $route): string => implode('-', $route))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function datesBetween(mixed $dateFrom, mixed $dateUntil): array
    {
        $start = CarbonImmutable::parse($this->dateValue($dateFrom));
        $end = CarbonImmutable::parse($this->dateValue($dateUntil));

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $dates = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }
}
