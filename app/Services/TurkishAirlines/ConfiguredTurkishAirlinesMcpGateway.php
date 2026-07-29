<?php

namespace App\Services\TurkishAirlines;

class ConfiguredTurkishAirlinesMcpGateway implements TurkishAirlinesMcpGateway
{
    public function __construct(
        private readonly StreamableHttpMcpClient $client,
        private readonly TurkishAirlinesFareMapper $mapper,
        private readonly TurkishAirlinesAirportLookup $airports,
    ) {}

    public function status(): array
    {
        $configured = $this->client->isConfigured();

        return [
            'configured' => $configured,
            'status' => $configured ? 'configured' : 'not_configured',
            'message' => $configured
                ? 'THY MCP endpoint is configured. Import searches can call the gateway.'
                : 'Set THY_MCP_URL to enable live Turkish Airlines MCP imports.',
        ];
    }

    public function search(array $parameters): array
    {
        if (! $this->client->isConfigured()) {
            return [
                'status' => 'not_configured',
                'message' => 'Set THY_MCP_URL before running a live import.',
                'raw' => ['parameters' => $parameters],
                'items' => [],
            ];
        }

        $origin = mb_strtoupper((string) $parameters['origin']);
        $destination = mb_strtoupper((string) $parameters['destination']);
        $originCountry = $this->airports->countryCode($origin);
        $destinationCountry = $this->airports->countryCode($destination);

        if (! $originCountry || ! $destinationCountry) {
            return [
                'status' => 'error',
                'message' => "Unknown country code for {$origin} or {$destination}.",
                'raw' => ['parameters' => $parameters],
                'items' => [],
            ];
        }

        try {
            $mcpArguments = $this->mcpArguments($parameters, $origin, $destination, $originCountry, $destinationCountry);
        } catch (\InvalidArgumentException $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
                'raw' => ['parameters' => $parameters],
                'items' => [],
            ];
        }

        $result = $this->client->callTool('search_flights', $mcpArguments);

        if (($result['status'] ?? null) !== 'ok') {
            return [
                'status' => $result['status'],
                'message' => $result['message'] ?? null,
                'raw' => $result['raw'] ?? ['parameters' => $parameters, 'mcp_arguments' => $mcpArguments],
                'items' => [],
            ];
        }

        $items = $this->mapper->fromMcpResponse($result['raw'] ?? [], $parameters);

        return [
            'status' => 'ok',
            'message' => count($items) === 0 ? 'THY MCP returned no importable fares for this search.' : 'THY MCP fares are ready to preview.',
            'raw' => $result['raw'],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mcpArguments(array $parameters, string $origin, string $destination, string $originCountry, string $destinationCountry): array
    {
        $tripType = ($parameters['trip_type'] ?? 'one_way') === 'round_trip' ? 'round' : 'one_way';

        if ($tripType === 'round' && blank($parameters['return_date'] ?? null)) {
            throw new \InvalidArgumentException('return_date is required for round trip THY MCP searches.');
        }

        $originDestinations = [
            [
                'departureDateTime' => ['departureDate' => $this->thyDate((string) $parameters['date'])],
                'originAirportCode' => $origin,
                'originCountryCode' => $originCountry,
                'destinationAirportCode' => $destination,
                'destinationCountryCode' => $destinationCountry,
            ],
        ];

        if ($tripType === 'round') {
            $originDestinations[] = [
                'departureDateTime' => ['departureDate' => $this->thyDate((string) $parameters['return_date'])],
                'originAirportCode' => $destination,
                'originCountryCode' => $destinationCountry,
                'destinationAirportCode' => $origin,
                'destinationCountryCode' => $originCountry,
            ];
        }

        $passengers = [
            ['passengerType' => 'ADT', 'quantity' => (int) ($parameters['adults'] ?? 1)],
        ];

        if ((int) ($parameters['children'] ?? 0) > 0) {
            $passengers[] = ['passengerType' => 'CHD', 'quantity' => (int) $parameters['children']];
        }

        if ((int) ($parameters['babies'] ?? 0) > 0) {
            $passengers[] = ['passengerType' => 'INF', 'quantity' => (int) $parameters['babies']];
        }

        return [
            'originDestinations' => $originDestinations,
            'passengers' => $passengers,
            'tripType' => $tripType,
        ];
    }

    private function thyDate(string $isoDate): string
    {
        [$year, $month, $day] = explode('-', $isoDate);

        return "{$day}-{$month}-{$year} 00:00";
    }
}
