<?php

namespace App\Services\TurkishAirlines;

use App\Models\Airport;

class TurkishAirlinesAirportLookup
{
    /**
     * @var array<string, array{name: string, city: string, country: string, timezone: string, icao?: string|null}>
     */
    private const array AIRPORTS = [
        'IST' => ['name' => 'Istanbul Airport', 'city' => 'Istanbul', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTFM'],
        'SAW' => ['name' => 'Sabiha Gokcen International Airport', 'city' => 'Istanbul', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTFJ'],
        'ESB' => ['name' => 'Ankara Esenboga Airport', 'city' => 'Ankara', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAC'],
        'ADB' => ['name' => 'Izmir Adnan Menderes Airport', 'city' => 'Izmir', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTBJ'],
        'AYT' => ['name' => 'Antalya Airport', 'city' => 'Antalya', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAI'],
        'ERC' => ['name' => 'Erzincan Yildirim Akbulut Airport', 'city' => 'Erzincan', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTCD'],
        'ERZ' => ['name' => 'Erzurum Airport', 'city' => 'Erzurum', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTCE'],
        'ASR' => ['name' => 'Kayseri Erkilet Airport', 'city' => 'Kayseri', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAU'],
        'TZX' => ['name' => 'Trabzon Airport', 'city' => 'Trabzon', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTCG'],
        'GZT' => ['name' => 'Gaziantep Oguzeli Airport', 'city' => 'Gaziantep', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAJ'],
        'DIY' => ['name' => 'Diyarbakir Airport', 'city' => 'Diyarbakir', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTCC'],
        'KYA' => ['name' => 'Konya Airport', 'city' => 'Konya', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAN'],
        'VAN' => ['name' => 'Van Ferit Melen Airport', 'city' => 'Van', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTCI'],
        'MLX' => ['name' => 'Malatya Erhac Airport', 'city' => 'Malatya', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAT'],
        'DLM' => ['name' => 'Dalaman Airport', 'city' => 'Mugla', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTBS'],
        'BJV' => ['name' => 'Milas Bodrum Airport', 'city' => 'Bodrum', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTFE'],
        'COV' => ['name' => 'Cukurova International Airport', 'city' => 'Mersin', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTDB'],
        'ADA' => ['name' => 'Adana Sakirpasa Airport', 'city' => 'Adana', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAF'],
        'SZF' => ['name' => 'Samsun Carsamba Airport', 'city' => 'Samsun', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTFH'],
        'RZV' => ['name' => 'Rize Artvin Airport', 'city' => 'Rize', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTFO'],
        'NAV' => ['name' => 'Nevsehir Kapadokya Airport', 'city' => 'Nevsehir', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'icao' => 'LTAZ'],
        'LHR' => ['name' => 'London Heathrow Airport', 'city' => 'London', 'country' => 'GB', 'timezone' => 'Europe/London', 'icao' => 'EGLL'],
        'LGW' => ['name' => 'London Gatwick Airport', 'city' => 'London', 'country' => 'GB', 'timezone' => 'Europe/London', 'icao' => 'EGKK'],
        'STN' => ['name' => 'London Stansted Airport', 'city' => 'London', 'country' => 'GB', 'timezone' => 'Europe/London', 'icao' => 'EGSS'],
        'CDG' => ['name' => 'Paris Charles de Gaulle Airport', 'city' => 'Paris', 'country' => 'FR', 'timezone' => 'Europe/Paris', 'icao' => 'LFPG'],
        'ORY' => ['name' => 'Paris Orly Airport', 'city' => 'Paris', 'country' => 'FR', 'timezone' => 'Europe/Paris', 'icao' => 'LFPO'],
        'AMS' => ['name' => 'Amsterdam Schiphol Airport', 'city' => 'Amsterdam', 'country' => 'NL', 'timezone' => 'Europe/Amsterdam', 'icao' => 'EHAM'],
        'FRA' => ['name' => 'Frankfurt Airport', 'city' => 'Frankfurt', 'country' => 'DE', 'timezone' => 'Europe/Berlin', 'icao' => 'EDDF'],
        'MUC' => ['name' => 'Munich Airport', 'city' => 'Munich', 'country' => 'DE', 'timezone' => 'Europe/Berlin', 'icao' => 'EDDM'],
        'DXB' => ['name' => 'Dubai International Airport', 'city' => 'Dubai', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'icao' => 'OMDB'],
        'JFK' => ['name' => 'John F. Kennedy International Airport', 'city' => 'New York', 'country' => 'US', 'timezone' => 'America/New_York', 'icao' => 'KJFK'],
        'EWR' => ['name' => 'Newark Liberty International Airport', 'city' => 'Newark', 'country' => 'US', 'timezone' => 'America/New_York', 'icao' => 'KEWR'],
        'SIN' => ['name' => 'Singapore Changi Airport', 'city' => 'Singapore', 'country' => 'SG', 'timezone' => 'Asia/Singapore', 'icao' => 'WSSS'],
        'HND' => ['name' => 'Tokyo Haneda Airport', 'city' => 'Tokyo', 'country' => 'JP', 'timezone' => 'Asia/Tokyo', 'icao' => 'RJTT'],
        'NRT' => ['name' => 'Narita International Airport', 'city' => 'Tokyo', 'country' => 'JP', 'timezone' => 'Asia/Tokyo', 'icao' => 'RJAA'],
        'KIX' => ['name' => 'Kansai International Airport', 'city' => 'Osaka', 'country' => 'JP', 'timezone' => 'Asia/Tokyo', 'icao' => 'RJBB'],
    ];

    public function countryCode(string $iataCode): ?string
    {
        $iataCode = mb_strtoupper($iataCode);
        $airport = Airport::query()->where('iata_code', $iataCode)->first();

        if ($airport) {
            return $airport->country;
        }

        return self::AIRPORTS[$iataCode]['country'] ?? null;
    }

    /**
     * @return array{name: string, city: string, country: string, timezone: string, icao?: string|null}
     */
    public function attributes(string $iataCode): array
    {
        $iataCode = mb_strtoupper($iataCode);

        return self::AIRPORTS[$iataCode] ?? [
            'name' => "{$iataCode} Airport",
            'city' => $iataCode,
            'country' => 'TR',
            'timezone' => 'Europe/Istanbul',
            'icao' => null,
        ];
    }
}
