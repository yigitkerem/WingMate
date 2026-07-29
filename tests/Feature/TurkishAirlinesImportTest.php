<?php

use App\Models\BaseFare;
use App\Models\Flight;
use App\Models\FlightInventory;
use App\Models\PriceImportBatch;
use App\Services\TurkishAirlines\ConfiguredTurkishAirlinesMcpGateway;
use App\Services\TurkishAirlines\StreamableHttpMcpClient;
use App\Services\TurkishAirlines\TurkishAirlinesAirportLookup;
use App\Services\TurkishAirlines\TurkishAirlinesFareImporter;
use App\Services\TurkishAirlines\TurkishAirlinesFareMapper;
use App\Services\TurkishAirlines\TurkishAirlinesMcpGateway;

it('maps structured mcp text content into previewable fares', function () {
    $items = app(TurkishAirlinesFareMapper::class)->fromMcpResponse([
        'result' => [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'flights' => [
                            [
                                'flightNumber' => 'TK1981',
                                'originAirportCode' => 'IST',
                                'destinationAirportCode' => 'LHR',
                                'departureDateTime' => '10-08-2026 09:10',
                                'arrivalDateTime' => '10-08-2026 11:20',
                                'durationMinutes' => 250,
                                'aircraftType' => 'Airbus A321neo',
                                'fareFamily' => 'ExtraFly',
                                'bookingClass' => 'Q',
                                'price' => [
                                    'basePrice' => 220,
                                    'taxes' => 46,
                                    'fees' => 28,
                                    'currency' => 'USD',
                                ],
                                'seatsAvailable' => 7,
                            ],
                        ],
                    ]),
                ],
            ],
        ],
    ], [
        'origin' => 'IST',
        'destination' => 'LHR',
        'date' => '2026-08-10',
        'trip_type' => 'one_way',
    ]);

    expect($items)->toHaveCount(1)
        ->and($items[0]['flight_number'])->toBe('TK1981')
        ->and($items[0]['bundle'])->toBe('EXTRAFLY')
        ->and($items[0]['base_price'])->toBe(220.0)
        ->and($items[0]['departure_at'])->toBe('2026-08-10 09:10:00');
});

it('maps turkish airlines origin destination option fares into preview rows', function () {
    $items = app(TurkishAirlinesFareMapper::class)->fromMcpResponse([
        'result' => [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'originDestinationInformations' => [
                            [
                                'originLocation' => 'IST',
                                'destinationLocation' => 'LON',
                                'departureDateTime' => '06-08-2026 00:00',
                                'originDestinationOptions' => [
                                    [
                                        'optionId' => 11,
                                        'flightSegments' => [
                                            [
                                                'departureAirportCode' => 'IST',
                                                'arrivalAirportCode' => 'LHR',
                                                'departureDateTime' => '06-08-2026 07:50',
                                                'arrivalDateTime' => '06-08-2026 09:50',
                                                'flightCode' => ['airlineCode' => 'TK', 'flightNumber' => '1979'],
                                                'equipment' => '359',
                                            ],
                                        ],
                                        'bookingPriceInfos' => [
                                            [
                                                'passengerFare' => [
                                                    'grandTotalFare' => [
                                                        'amount' => 12324.25,
                                                        'currencyCode' => 'TRY',
                                                    ],
                                                ],
                                                'bookingPriceType' => 'ECONOMY',
                                                'brandCodeList' => ['CL'],
                                                'resBookDesigCodeList' => ['Y'],
                                                'fareBasisCodeList' => ['Q'],
                                            ],
                                            [
                                                'passengerFare' => [
                                                    'grandTotalFare' => [
                                                        'amount' => 63584.5,
                                                        'currencyCode' => 'TRY',
                                                    ],
                                                ],
                                                'bookingPriceType' => 'BUSINESS',
                                                'brandCodeList' => ['BL'],
                                                'resBookDesigCodeList' => ['C'],
                                                'fareBasisCodeList' => ['Z'],
                                            ],
                                        ],
                                        'journeyDuration' => 14400000,
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ],
            ],
        ],
    ], [
        'origin' => 'IST',
        'destination' => 'LHR',
        'date' => '2026-08-06',
        'trip_type' => 'one_way',
    ]);

    expect($items)->toHaveCount(2)
        ->and($items[0]['flight_number'])->toBe('TK1979')
        ->and($items[0]['bundle'])->toBe('ECOFLY')
        ->and($items[0]['booking_class'])->toBe('Y')
        ->and($items[0]['currency'])->toBe('TRY')
        ->and($items[0]['base_price'])->toBe(12324.25)
        ->and($items[0]['duration_minutes'])->toBe(240)
        ->and($items[1]['bundle'])->toBe('BUSINESSPRIME')
        ->and($items[1]['cabin'])->toBe('BUSINESS');
});

it('maps round trip turkish airlines option groups as separate fare legs', function () {
    $items = app(TurkishAirlinesFareMapper::class)->fromMcpResponse([
        'result' => [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'originDestinationInformations' => [
                            [
                                'originDestinationOptions' => [
                                    [
                                        'flightSegments' => [[
                                            'departureAirportCode' => 'IST',
                                            'arrivalAirportCode' => 'ERC',
                                            'departureDateTime' => '06-08-2026 09:00',
                                            'arrivalDateTime' => '06-08-2026 10:45',
                                            'flightCode' => ['airlineCode' => 'TK', 'flightNumber' => '2652'],
                                        ]],
                                        'bookingPriceInfos' => [[
                                            'passengerFare' => ['grandTotalFare' => ['amount' => 3200, 'currencyCode' => 'TRY']],
                                            'bookingPriceType' => 'ECONOMY',
                                            'brandCodeList' => ['LG'],
                                            'resBookDesigCodeList' => ['Q'],
                                            'fareBasisCodeList' => ['Q'],
                                        ]],
                                    ],
                                ],
                            ],
                            [
                                'originDestinationOptions' => [
                                    [
                                        'flightSegments' => [[
                                            'departureAirportCode' => 'ERC',
                                            'arrivalAirportCode' => 'IST',
                                            'departureDateTime' => '09-08-2026 11:30',
                                            'arrivalDateTime' => '09-08-2026 13:15',
                                            'flightCode' => ['airlineCode' => 'TK', 'flightNumber' => '2653'],
                                        ]],
                                        'bookingPriceInfos' => [[
                                            'passengerFare' => ['grandTotalFare' => ['amount' => 3350, 'currencyCode' => 'TRY']],
                                            'bookingPriceType' => 'ECONOMY',
                                            'brandCodeList' => ['GN'],
                                            'resBookDesigCodeList' => ['Q'],
                                            'fareBasisCodeList' => ['Q'],
                                        ]],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ],
            ],
        ],
    ], [
        'origin' => 'IST',
        'destination' => 'ERC',
        'date' => '2026-08-06',
        'trip_type' => 'round_trip',
    ]);

    expect($items)->toHaveCount(2)
        ->and($items[0]['flight_number'])->toBe('TK2652')
        ->and($items[0]['leg_index'])->toBe(1)
        ->and($items[0]['trip_type'])->toBe('round_trip')
        ->and($items[1]['flight_number'])->toBe('TK2653')
        ->and($items[1]['leg_index'])->toBe(2)
        ->and($items[1]['bundle'])->toBe('PRIMEFLY');
});

it('resolves ERC country codes for THY MCP searches', function () {
    $client = new class extends StreamableHttpMcpClient
    {
        public array $arguments = [];

        public function isConfigured(): bool
        {
            return true;
        }

        public function callTool(string $toolName, array $arguments): array
        {
            $this->arguments = $arguments;

            return ['status' => 'ok', 'raw' => ['result' => ['content' => []]]];
        }
    };

    $gateway = new ConfiguredTurkishAirlinesMcpGateway(
        client: $client,
        mapper: app(TurkishAirlinesFareMapper::class),
        airports: app(TurkishAirlinesAirportLookup::class),
    );

    $result = $gateway->search([
        'origin' => 'IST',
        'destination' => 'ERC',
        'date' => '2026-08-06',
        'trip_type' => 'one_way',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ]);

    expect($result['status'])->toBe('ok')
        ->and($client->arguments['originDestinations'][0]['originCountryCode'])->toBe('TR')
        ->and($client->arguments['originDestinations'][0]['destinationCountryCode'])->toBe('TR');
});

it('previews mcp fares and imports only selected rows', function () {
    $this->app->instance(TurkishAirlinesMcpGateway::class, new class implements TurkishAirlinesMcpGateway
    {
        public function status(): array
        {
            return ['configured' => true, 'status' => 'configured', 'message' => 'Ready'];
        }

        public function search(array $parameters): array
        {
            return [
                'status' => 'ok',
                'message' => 'Two fares found.',
                'raw' => ['source' => 'fake'],
                'items' => [
                    [
                        'flight_number' => 'TK1981',
                        'origin' => 'IST',
                        'destination' => 'LHR',
                        'departure_at' => '2026-08-10 09:10:00',
                        'arrival_at' => '2026-08-10 11:20:00',
                        'duration_minutes' => 250,
                        'aircraft_type' => 'Airbus A321neo',
                        'cabin' => 'ECONOMY',
                        'booking_class' => 'Q',
                        'bundle' => 'EXTRAFLY',
                        'trip_type' => 'one_way',
                        'leg_index' => 1,
                        'currency' => 'USD',
                        'base_price' => 220,
                        'taxes' => 46,
                        'fees' => 28,
                        'fare_basis_template' => '{class}{bundle}{trip}{leg}',
                        'class_letters' => 'Q',
                        'available' => 7,
                        'capacity' => 12,
                    ],
                    [
                        'flight_number' => 'TK1983',
                        'origin' => 'IST',
                        'destination' => 'LHR',
                        'departure_at' => '2026-08-10 13:25:00',
                        'arrival_at' => '2026-08-10 15:35:00',
                        'duration_minutes' => 250,
                        'aircraft_type' => 'Airbus A330-300',
                        'cabin' => 'BUSINESS',
                        'booking_class' => 'J',
                        'bundle' => 'BUSINESSPRIME',
                        'trip_type' => 'one_way',
                        'leg_index' => 1,
                        'currency' => 'USD',
                        'base_price' => 720,
                        'taxes' => 46,
                        'fees' => 28,
                        'fare_basis_template' => '{class}{bundle}{trip}{leg}',
                        'class_letters' => 'J',
                        'available' => 3,
                        'capacity' => 8,
                    ],
                ],
            ];
        }
    });

    $importer = app(TurkishAirlinesFareImporter::class);
    $batch = $importer->preview([
        'origin' => 'ist',
        'destination' => 'lhr',
        'date' => '2026-08-10',
        'trip_type' => 'one_way',
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ], null);

    expect($batch->status)->toBe('preview')
        ->and($batch->items)->toHaveCount(2)
        ->and(Flight::query()->count())->toBe(0);

    $count = $importer->import($batch, [$batch->items()->first()->id]);

    expect($count)->toBe(1)
        ->and(Flight::query()->count())->toBe(1)
        ->and(BaseFare::query()->count())->toBe(1)
        ->and(FlightInventory::query()->first()->available)->toBe(7)
        ->and(PriceImportBatch::query()->first()->status)->toBe('partially_imported')
        ->and($batch->items()->where('status', 'preview')->count())->toBe(1);
});

it('creates one combined preview batch for bulk route date and trip searches', function () {
    $this->app->instance(TurkishAirlinesMcpGateway::class, new class implements TurkishAirlinesMcpGateway
    {
        public function status(): array
        {
            return ['configured' => true, 'status' => 'configured', 'message' => 'Ready'];
        }

        public function search(array $parameters): array
        {
            return [
                'status' => 'ok',
                'message' => 'One fare found.',
                'raw' => ['parameters' => $parameters],
                'items' => [[
                    'flight_number' => 'TK'.str_replace('-', '', substr($parameters['date'], -5)),
                    'origin' => $parameters['origin'],
                    'destination' => $parameters['destination'],
                    'departure_at' => "{$parameters['date']} 09:00:00",
                    'arrival_at' => "{$parameters['date']} 10:45:00",
                    'duration_minutes' => 105,
                    'aircraft_type' => 'Turkish Airlines aircraft',
                    'cabin' => 'ECONOMY',
                    'booking_class' => 'Q',
                    'bundle' => 'EXTRAFLY',
                    'trip_type' => $parameters['trip_type'],
                    'leg_index' => 1,
                    'currency' => 'TRY',
                    'base_price' => 3200,
                    'taxes' => 0,
                    'fees' => 0,
                    'fare_basis_template' => '{class}{bundle}{trip}{leg}',
                    'class_letters' => 'Q',
                    'available' => 9,
                    'capacity' => 9,
                ]],
            ];
        }
    });

    $batch = app(TurkishAirlinesFareImporter::class)->previewBulk([
        'routes' => "IST-ERC\nERC-IST",
        'date_from' => '2026-08-06',
        'date_until' => '2026-08-07',
        'trip_types' => ['one_way', 'round_trip'],
        'return_after_days' => 3,
        'adults' => 1,
        'children' => 0,
        'babies' => 0,
    ], null);

    expect($batch->status)->toBe('preview')
        ->and($batch->search_parameters['search_count'])->toBe(8)
        ->and($batch->items)->toHaveCount(8)
        ->and(data_get($batch->raw_response, 'searches.1.search_parameters.return_date'))->toBe('2026-08-09');
});
