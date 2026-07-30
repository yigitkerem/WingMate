import type {
    Airport,
    Fare,
    FlightResult,
    SearchFilters,
} from '@/types/flight-search';

export const passengerLimit = 9;
const lastDestinationAirportStorageKey =
    'dynamic-pricer:last-destination-airport-id';
const wingoSearchPrefillStorageKey = 'dynamic-pricer:wingo-search-prefill';

export const wingoSearchPrefillEvent = 'dynamic-pricer:wingo-search-prefill';
export const wingoLaunchEvent = 'dynamic-pricer:wingo-launch';

export type WingoSearchPrefill = {
    origin?: string;
    destination?: string;
    date?: string;
    tripType?: SearchFilters['trip_type'];
    returnDate?: string;
    adults?: number;
    children?: number;
    babies?: number;
};

export type WingoTriggerContext = {
    flight_number?: string;
    cabin?: string;
    origin?: string;
    destination?: string;
    date?: string;
    hour?: string;
    adults?: number;
    children?: number;
    babies?: number;
};

export type WingoLaunchDetail = {
    message?: string;
    trigger?: string;
    triggerContext?: WingoTriggerContext;
    prefill?: WingoSearchPrefill;
};

export const featuredRoutes = [
    {
        origin: 'IST',
        destination: 'LHR',
        date: '2026-08-06',
        route: 'Istanbul to London',
        price: '$158',
        image: '/assets/london.jpg',
    },
    {
        origin: 'AMS',
        destination: 'IST',
        date: '2026-08-09',
        route: 'Amsterdam to Istanbul',
        price: '$132',
        image: '/assets/amsterdam.jpg',
    },
    {
        origin: 'IST',
        destination: 'CDG',
        date: '2026-08-12',
        route: 'Istanbul to Paris',
        price: '$145',
        image: '/assets/paris.jpg',
    },
    {
        origin: 'IST',
        destination: 'JFK',
        date: '2026-08-18',
        route: 'Istanbul to New York',
        price: '$520',
        image: '/assets/new_york.jpg',
    },
] as const;

export function findAirportByCode(
    airports: Airport[],
    code: string,
): Airport | undefined {
    return airports.find((airport) => airport.code === code);
}

export function initializeSearchFilters(
    filters: SearchFilters,
    airports: Airport[],
): SearchFilters {
    const originAirportId =
        filters.origin_airport_id ??
        findAirportByCode(airports, 'IST')?.id ??
        null;
    const storedDestinationAirportId = readStoredDestinationAirportId(airports);

    return {
        ...filters,
        origin_airport_id: originAirportId,
        destination_airport_id:
            filters.destination_airport_id ?? storedDestinationAirportId,
    };
}

export function storeLastDestinationAirport(airportId: number | null): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (airportId === null) {
        window.sessionStorage.removeItem(lastDestinationAirportStorageKey);

        return;
    }

    window.sessionStorage.setItem(
        lastDestinationAirportStorageKey,
        String(airportId),
    );
}

export function publishWingoSearchPrefill(
    filters: SearchFilters,
    airports: Airport[],
): void {
    if (typeof window === 'undefined') {
        return;
    }

    const prefill = wingoSearchPrefillFromFilters(filters, airports);

    window.sessionStorage.setItem(
        wingoSearchPrefillStorageKey,
        JSON.stringify(prefill),
    );
    window.dispatchEvent(
        new CustomEvent<WingoSearchPrefill>(wingoSearchPrefillEvent, {
            detail: prefill,
        }),
    );
}

export function publishWingoLaunch(detail: WingoLaunchDetail): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (detail.prefill) {
        window.sessionStorage.setItem(
            wingoSearchPrefillStorageKey,
            JSON.stringify(detail.prefill),
        );
    }

    window.dispatchEvent(
        new CustomEvent<WingoLaunchDetail>(wingoLaunchEvent, {
            detail,
        }),
    );
}

export function readStoredWingoSearchPrefill(): WingoSearchPrefill | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const storedValue = window.sessionStorage.getItem(
        wingoSearchPrefillStorageKey,
    );

    if (!storedValue) {
        return null;
    }

    try {
        const value = JSON.parse(storedValue) as WingoSearchPrefill;

        return typeof value === 'object' && value !== null ? value : null;
    } catch {
        return null;
    }
}

function wingoSearchPrefillFromFilters(
    filters: SearchFilters,
    airports: Airport[],
): WingoSearchPrefill {
    const origin = airports.find(
        (airport) => airport.id === filters.origin_airport_id,
    );
    const destination = airports.find(
        (airport) => airport.id === filters.destination_airport_id,
    );

    return {
        origin: origin?.code,
        destination: destination?.code,
        date: filters.depart_date || undefined,
        tripType: filters.trip_type,
        returnDate:
            filters.trip_type === 'round_trip'
                ? filters.return_date || undefined
                : undefined,
        adults: filters.adults,
        children: filters.children,
        babies: filters.babies,
    };
}

function readStoredDestinationAirportId(airports: Airport[]): number | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const storedValue = window.sessionStorage.getItem(
        lastDestinationAirportStorageKey,
    );
    const storedAirportId = storedValue ? Number(storedValue) : null;

    if (
        storedAirportId === null ||
        !airports.some((airport) => airport.id === storedAirportId)
    ) {
        return null;
    }

    return storedAirportId;
}

export function formatDisplayDate(value: string, locale = 'en'): string {
    return parseDate(value).toLocaleDateString(locale === 'tr' ? 'tr-TR' : 'en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

export function formatShortDate(value: string, locale = 'en'): string {
    return parseDate(value).toLocaleDateString(locale === 'tr' ? 'tr-TR' : 'en-US', {
        month: 'short',
        day: 'numeric',
    });
}

export function fareTypeLabel(fareType?: 'one_way' | 'round_trip'): string {
    return fareType === 'round_trip' ? 'Round trip' : 'One way';
}

export function feeLabel(
    feeUsd: number | undefined,
    latestHours?: number | null,
): string {
    const fee = (feeUsd ?? 0) === 0 ? 'Free' : `$${feeUsd}`;

    if (latestHours === null || latestHours === undefined) {
        return `${fee}, full penalty`;
    }

    return `${fee} until ${latestHours}h`;
}

export function fareRuleLabel(
    rule: 'change' | 'refund',
    feeUsd: number | null | undefined,
    latestHours?: number | null,
    feePercent?: number | null,
    locale = 'en',
): string {
    const isTurkish = locale === 'tr';

    if (latestHours === null || latestHours === undefined) {
        if (isTurkish) {
            return rule === 'change' ? 'Değişiklik yapılamaz' : 'İade yapılamaz';
        }

        return rule === 'change' ? 'No change allowed' : 'No refund allowed';
    }

    if (feePercent !== null && feePercent !== undefined) {
        if (isTurkish) {
            return `${latestHours} saate kadar %${feePercent} ücret`;
        }

        return `${feePercent}% fee until ${latestHours}h`;
    }

    if ((feeUsd ?? 0) === 0) {
        if (isTurkish) {
            return `${latestHours} saate kadar ücretsiz`;
        }

        return `No fee until ${latestHours}h`;
    }

    if (isTurkish) {
        return `${latestHours} saate kadar $${feeUsd} ücret`;
    }

    return `$${feeUsd} fee until ${latestHours}h`;
}

export function seatSelectionLabel(isFree?: boolean): string {
    return isFree ? 'Free seat selection' : 'Paid seat selection';
}

export function lowestFarePrice(flight: FlightResult): number | null {
    const fares = Array.isArray(flight.fares)
        ? flight.fares
        : Object.values(flight.fares);
    const prices = fares
        .filter((fare) => fare.available)
        .map((fare) => fare.base_price_usd)
        .filter((price): price is number => typeof price === 'number');

    return prices.length > 0 ? Math.min(...prices) : null;
}

export function clampPassengerCount(
    value: number,
    min: number,
    max: number,
): number {
    return Math.min(Math.max(value, min), Math.max(min, max));
}

export function routeHeroImage(destinationCode?: string): string {
    if (!destinationCode) {
        return '/assets/case_hero.png';
    }

    if (['AMS', 'SIN', 'BKK', 'KUL'].includes(destinationCode)) {
        return '/assets/amsterdam.jpg';
    }

    if (['LHR', 'LGW', 'MAN'].includes(destinationCode)) {
        return '/assets/london.jpg';
    }

    if (
        [
            'CDG',
            'FRA',
            'MUC',
            'BER',
            'DUS',
            'ZRH',
            'VIE',
            'MXP',
            'FCO',
            'MAD',
            'BCN',
        ].includes(destinationCode)
    ) {
        return '/assets/paris.jpg';
    }

    if (
        ['JFK', 'ORD', 'LAX', 'MIA', 'SFO', 'BOS', 'YYZ'].includes(
            destinationCode,
        )
    ) {
        return '/assets/new_york.jpg';
    }

    if (
        ['DXB', 'DOH', 'JED', 'MED', 'RUH', 'CAI', 'CMN'].includes(
            destinationCode,
        )
    ) {
        return '/assets/dubai.jpg';
    }

    if (['IST', 'SAW', 'ADB', 'ESB', 'AYT', 'TZX'].includes(destinationCode)) {
        return '/assets/istanbul.jpg';
    }

    return '/assets/case_hero.png';
}

export function totalPassengers(
    adults: number,
    children: number,
    babies: number,
): number {
    return adults + children + babies;
}

function parseDate(value: string): Date {
    return new Date(`${value}T00:00:00`);
}

export function safeFareKey(fare: Fare, fallback: string | number): string {
    return String(fare.id ?? fare.class_letters ?? fare.class ?? fallback);
}
