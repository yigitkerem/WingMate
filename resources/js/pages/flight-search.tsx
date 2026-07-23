import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Baby,
    Briefcase,
    CalendarDays,
    ChevronDown,
    Luggage,
    Minus,
    Plane,
    Plus,
    Search,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { dashboard } from '@/routes';

type Airport = {
    id: number;
    name: string;
    code: string;
};

type Fare = {
    id?: number;
    class?: string;
    class_letters?: string;
    fare_type?: 'one_way' | 'round_trip';
    checked_baggage_kg?: number;
    cabin_baggage_kg?: number;
    seat_selection_free?: boolean;
    change_fee_usd?: number;
    refund_fee_usd?: number;
    latest_refund_hours?: number | null;
    latest_change_hours?: number | null;
    base_price_usd?: number;
    count_available?: number;
    available: boolean;
};

type FlightResult = {
    id: number;
    flight_number: string;
    plane_model: string;
    date: string;
    hour: string;
    origin: Airport;
    destination: Airport;
    fare_type: 'one_way' | 'round_trip';
    fares: Record<'A' | 'B' | 'C', Fare> | Fare[];
};

type Filters = {
    origin_airport_id: number | null;
    destination_airport_id: number | null;
    trip_type: 'one_way' | 'round_trip';
    depart_date: string;
    return_date: string;
    search_mode: 'basic' | 'full';
    adults: number;
    children: number;
    babies: number;
};

type Results = {
    seat_passengers: number;
    outbound: FlightResult[];
    return: FlightResult[];
} | null;

type Props = {
    airports: Airport[];
    filters: Filters;
    results: Results;
    canUseFullSearch: boolean;
    customer: {
        isAuthenticated: boolean;
        isAdmin: boolean;
        firstName: string;
        lastName: string;
    };
    errors?: Record<string, string>;
};

type PurchaseTarget = {
    flight: FlightResult;
    fare: Fare;
};

type FullFareFeatureFilter =
    | 'available'
    | 'checked_bag'
    | 'large_cabin_bag'
    | 'free_seat_selection'
    | 'free_change'
    | 'free_refund';

const inputClass =
    'h-12 w-full border border-zinc-300 bg-white px-3 text-left text-sm text-zinc-950 outline-none transition-colors focus:border-red-600';

const controlClass =
    'h-12 border border-zinc-300 bg-white px-4 text-sm font-medium text-zinc-950 outline-none transition-colors hover:border-zinc-500 focus:border-red-600';

const fullFareFeatureFilters: {
    key: FullFareFeatureFilter;
    label: string;
    matches: (fare: Fare) => boolean;
}[] = [
    {
        key: 'available',
        label: 'Available seats',
        matches: (fare) => fare.available,
    },
    {
        key: 'checked_bag',
        label: 'Checked bag',
        matches: (fare) => (fare.checked_baggage_kg ?? 0) > 0,
    },
    {
        key: 'large_cabin_bag',
        label: '8 kg cabin',
        matches: (fare) => (fare.cabin_baggage_kg ?? 0) >= 8,
    },
    {
        key: 'free_seat_selection',
        label: 'Free seat selection',
        matches: (fare) => fare.seat_selection_free === true,
    },
    {
        key: 'free_change',
        label: 'Free changes',
        matches: (fare) => fare.change_fee_usd === 0,
    },
    {
        key: 'free_refund',
        label: 'Free refunds',
        matches: (fare) => fare.refund_fee_usd === 0,
    },
];

function AirportBackground() {
    return (
        <div className="pointer-events-none absolute inset-x-0 top-0 h-[680px] overflow-hidden">
            <div
                className="absolute inset-0 bg-cover bg-center opacity-70"
                style={{
                    backgroundImage:
                        "url('https://unsplash.com/photos/GsVO12cQrzA/download?force=true&w=1800')",
                }}
            />
            <div className="absolute inset-0 bg-white/35" />
            <div className="absolute inset-0 bg-linear-to-b from-white/10 via-zinc-100/65 to-zinc-100" />
        </div>
    );
}

export default function FlightSearch({
    airports,
    filters,
    results,
    canUseFullSearch,
    customer,
    errors = {},
}: Props) {
    const [form, setForm] = useState<Filters>(filters);
    const [openPanel, setOpenPanel] = useState<string | null>(null);
    const [purchaseTarget, setPurchaseTarget] = useState<PurchaseTarget | null>(
        null,
    );

    function update<K extends keyof Filters>(key: K, value: Filters[K]) {
        setForm((current) => ({ ...current, [key]: value }));
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        router.post(
            '/search',
            {
                ...form,
                search_mode: canUseFullSearch ? form.search_mode : 'basic',
                return_date:
                    form.trip_type === 'round_trip' ? form.return_date : undefined,
            },
            {
                preserveScroll: true,
                onStart: () => setOpenPanel(null),
            },
        );
    }

    const bookingForm = (
        <form
            onSubmit={submit}
            className="border border-zinc-300 bg-white p-4 shadow-[0_18px_55px_rgba(24,24,27,0.16)] sm:p-5"
        >
            <div className="mb-5 flex flex-col justify-between gap-4 border-b border-zinc-300 pb-4 sm:flex-row sm:items-end">
                <div>
                    <div className="text-xs font-semibold uppercase tracking-normal text-red-700">
                        Book a flight
                    </div>
                    <h2 className="mt-1 text-2xl font-semibold tracking-normal">
                        Find your fare
                    </h2>
                </div>
            </div>
            <div className="grid gap-4">
                <div className="grid gap-4 lg:grid-cols-[1.3fr_1.3fr_1fr]">
                    <SearchableAirport
                        airports={airports}
                        label="From"
                        value={form.origin_airport_id}
                        error={errors.origin_airport_id}
                        isOpen={openPanel === 'origin'}
                        onToggle={() =>
                            setOpenPanel(openPanel === 'origin' ? null : 'origin')
                        }
                        onClose={() => setOpenPanel(null)}
                        onChange={(id) => update('origin_airport_id', id)}
                    />
                    <SearchableAirport
                        airports={airports}
                        label="To"
                        value={form.destination_airport_id}
                        error={errors.destination_airport_id}
                        isOpen={openPanel === 'destination'}
                        onToggle={() =>
                            setOpenPanel(
                                openPanel === 'destination' ? null : 'destination',
                            )
                        }
                        onClose={() => setOpenPanel(null)}
                        onChange={(id) => update('destination_airport_id', id)}
                    />
                    <SegmentedControl
                        label="Trip"
                        value={form.trip_type}
                        options={[
                            ['one_way', 'One-way'],
                            ['round_trip', 'Roundtrip'],
                        ]}
                        onChange={(value) =>
                            update('trip_type', value as Filters['trip_type'])
                        }
                    />
                </div>
                <div className="grid gap-4 lg:grid-cols-[1fr_1fr_1.2fr_auto]">
                    <DatePicker
                        label="Depart"
                        value={form.depart_date}
                        error={errors.depart_date}
                        isOpen={openPanel === 'depart'}
                        onToggle={() =>
                            setOpenPanel(openPanel === 'depart' ? null : 'depart')
                        }
                        onClose={() => setOpenPanel(null)}
                        onChange={(date) => update('depart_date', date)}
                    />
                    <DatePicker
                        disabled={form.trip_type === 'one_way'}
                        label="Return"
                        value={form.return_date}
                        error={errors.return_date}
                        isOpen={openPanel === 'return'}
                        onToggle={() =>
                            setOpenPanel(openPanel === 'return' ? null : 'return')
                        }
                        onClose={() => setOpenPanel(null)}
                        onChange={(date) => update('return_date', date)}
                    />
                    <PassengerPicker
                        adults={form.adults}
                        children={form.children}
                        babies={form.babies}
                        errors={errors}
                        canUseFullSearch={canUseFullSearch}
                        searchMode={form.search_mode}
                        isOpen={openPanel === 'passengers'}
                        onToggle={() =>
                            setOpenPanel(
                                openPanel === 'passengers' ? null : 'passengers',
                            )
                        }
                        onChange={(key, value) => update(key, value)}
                        onSearchModeChange={(value) => update('search_mode', value)}
                    />
                    <div className="flex items-end">
                        <button
                            type="submit"
                            className="inline-flex h-12 w-full items-center justify-center gap-2 border border-red-700 bg-red-700 px-6 text-sm font-semibold text-white outline-none transition-colors hover:bg-red-800 focus:border-zinc-950 lg:w-auto"
                        >
                            <Search className="size-4" />
                            Search flights
                        </button>
                    </div>
                </div>
            </div>
        </form>
    );

    return (
        <>
            <Head title="AeroVista Airlines Price Search" />
            <main className="relative min-h-screen overflow-hidden bg-zinc-100 font-sans text-zinc-950">
                {!results && <AirportBackground />}
                <div className="relative z-10 mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8">
                    <AirlineHeader
                        customer={customer}
                        canUseAdmin={customer.isAdmin}
                    />

                    {results ? (
                        <section className="py-4">{bookingForm}</section>
                    ) : (
                        <section className="grid min-h-[470px] items-end gap-6 py-8 lg:grid-cols-[0.9fr_1.35fr] lg:py-12">
                            <div className="max-w-xl pb-2">
                                <div className="inline-flex border border-red-800 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-normal text-red-700">
                                    Summer network now open
                                </div>
                                <h1 className="mt-5 text-5xl font-semibold tracking-normal text-zinc-950 sm:text-6xl">
                                    Fly Istanbul, Europe, and beyond.
                                </h1>
                                <p className="mt-4 max-w-lg text-base font-medium leading-7 text-zinc-700">
                                    Book direct with AeroVista Airlines for clear
                                    fares, practical baggage choices, and live seat
                                    availability.
                                </p>
                                <div className="mt-6 grid grid-cols-3 border border-zinc-300 bg-white/90">
                                    {[
                                        ['86', 'Destinations'],
                                        ['31', 'Countries'],
                                        ['24h', 'Fare hold'],
                                    ].map(([value, label]) => (
                                        <div
                                            key={label}
                                            className="border-r border-zinc-300 p-3 last:border-r-0"
                                        >
                                            <div className="text-2xl font-semibold text-zinc-950">
                                                {value}
                                            </div>
                                            <div className="mt-1 text-xs font-semibold uppercase tracking-normal text-zinc-500">
                                                {label}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {bookingForm}
                        </section>
                    )}

                    <QuickActions />

                    {!results && <OfferCarousel />}

                    <ResultsPanel
                        results={results}
                        onPurchase={(flight, fare) =>
                            setPurchaseTarget({ flight, fare })
                        }
                    />
                </div>
                {purchaseTarget && (
                    <PurchaseModal
                        key={purchaseTarget.fare.id}
                        target={purchaseTarget}
                        filters={form}
                        customer={customer}
                        errors={errors}
                        onClose={() => setPurchaseTarget(null)}
                    />
                )}
            </main>
        </>
    );
}

function AirlineHeader({
    customer,
    canUseAdmin,
}: {
    customer: Props['customer'];
    canUseAdmin: boolean;
}) {
    return (
        <header className="border border-zinc-300 bg-white/95 shadow-[0_10px_35px_rgba(24,24,27,0.08)]">
            <div className="flex flex-col gap-4 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex items-center gap-3">
                    <div className="flex h-12 w-12 items-center justify-center border border-red-900 bg-red-700 text-white shadow-sm">
                        <Plane className="size-6 -rotate-45" />
                    </div>
                    <div>
                        <div className="text-xl font-semibold tracking-normal text-zinc-950">
                            AeroVista Airlines
                        </div>
                    </div>
                </div>

                <nav className="flex flex-wrap items-center gap-1 text-sm font-semibold text-zinc-700">
                    {['Book', 'Manage', 'Check-in', 'Flight status', 'Loyalty'].map(
                        (item) => (
                            <a
                                key={item}
                                className="border border-transparent px-3 py-2 outline-none hover:border-zinc-300 hover:bg-zinc-100 focus:border-red-600"
                                href="#"
                            >
                                {item}
                            </a>
                        ),
                    )}
                </nav>

                <div className="flex flex-wrap gap-2">
                    {!customer.isAuthenticated && (
                        <>
                            <a
                                className="inline-flex h-10 items-center justify-center border border-zinc-950 bg-white px-4 text-sm font-medium text-zinc-950 outline-none transition-colors hover:border-red-700 hover:text-red-700 focus:border-red-600"
                                href="/register"
                            >
                                Join
                            </a>
                            <a
                                className="inline-flex h-10 items-center justify-center border border-zinc-300 bg-white px-4 text-sm font-medium text-zinc-950 outline-none transition-colors hover:border-red-700 hover:text-red-700 focus:border-red-600"
                                href="/login"
                            >
                                Log in
                            </a>
                        </>
                    )}
                    {customer.isAuthenticated && (
                        <Link
                            className="inline-flex h-10 items-center justify-center border border-zinc-950 bg-white px-4 text-sm font-medium text-zinc-950 outline-none transition-colors hover:border-red-700 hover:text-red-700 focus:border-red-600"
                            href={dashboard()}
                        >
                            Dashboard
                        </Link>
                    )}
                    {canUseAdmin && (
                        <a
                            className="inline-flex h-10 items-center justify-center border border-zinc-950 bg-white px-4 text-sm font-medium text-zinc-950 outline-none transition-colors hover:border-red-700 hover:text-red-700 focus:border-red-600"
                            href="/admin"
                        >
                            Admin
                        </a>
                    )}
                </div>
            </div>
        </header>
    );
}

function QuickActions() {
    const actions = [
        {
            title: 'Manage booking',
            description: 'Change seats and passenger details',
            icon: <Briefcase className="size-5" />,
        },
        {
            title: 'Online check-in',
            description: 'Opens 24 hours before departure',
            icon: <Plane className="size-5" />,
        },
        {
            title: 'Flight status',
            description: 'Live departure and arrival updates',
            icon: <CalendarDays className="size-5" />,
        },
        {
            title: 'Baggage options',
            description: 'Cabin and checked allowance',
            icon: <Luggage className="size-5" />,
        },
    ];

    return (
        <section className="grid border border-zinc-300 bg-white md:grid-cols-4">
            {actions.map((action) => (
                <a
                    key={action.title}
                    href="#"
                    className="flex min-h-28 items-start gap-3 border-b border-zinc-300 p-4 outline-none hover:bg-zinc-100 focus:bg-red-50 md:border-r md:border-b-0 md:last:border-r-0"
                >
                    <span className="mt-1 text-red-700">{action.icon}</span>
                    <span>
                        <span className="block text-sm font-semibold text-zinc-950">
                            {action.title}
                        </span>
                        <span className="mt-1 block text-sm leading-5 text-zinc-600">
                            {action.description}
                        </span>
                    </span>
                </a>
            ))}
        </section>
    );
}

function OfferCarousel() {
    const offers = [
        {
            route: 'Istanbul to London',
            code: 'IST - LHR',
            date: 'Aug 6',
            price: '$158',
            image: 'https://unsplash.com/photos/S3N2cPMWhEA/download?force=true&w=900',
        },
        {
            route: 'Amsterdam to Istanbul',
            code: 'AMS - IST',
            date: 'Aug 9',
            price: '$132',
            image: 'https://unsplash.com/photos/GsVO12cQrzA/download?force=true&w=900',
        },
        {
            route: 'Istanbul to Paris',
            code: 'IST - CDG',
            date: 'Aug 12',
            price: '$145',
            image: 'https://unsplash.com/photos/RduE7aSO2SA/download?force=true&w=900',
        },
        {
            route: 'Frankfurt to New York',
            code: 'FRA - JFK',
            date: 'Aug 18',
            price: '$516',
            image: 'https://unsplash.com/photos/GsVO12cQrzA/download?force=true&w=900',
        },
    ];

    return (
        <section className="border border-zinc-300 bg-white p-4">
            <div className="mb-4 flex flex-col justify-between gap-4 border-b border-zinc-300 pb-4 sm:flex-row sm:items-end">
                <div>
                    <div className="text-xs font-semibold uppercase tracking-normal text-red-700">
                        Featured fares
                    </div>
                    <h2 className="mt-1 text-2xl font-semibold tracking-normal">
                        Popular routes this week
                    </h2>
                    <p className="text-sm text-zinc-600">
                        Direct AeroVista fares with seasonal availability.
                    </p>
                </div>
                <a
                    className="inline-flex h-10 items-center justify-center gap-2 border border-zinc-950 bg-white px-4 text-sm font-semibold text-zinc-950 outline-none hover:border-red-700 hover:text-red-700 focus:border-red-600"
                    href="#"
                >
                    View all routes
                    <ArrowRight className="size-4" />
                </a>
            </div>
            <div className="flex snap-x gap-3 overflow-x-auto pb-1">
                {offers.map((offer) => (
                    <article
                        key={`${offer.route}-${offer.date}`}
                        className="relative h-56 min-w-[280px] snap-start overflow-hidden border border-zinc-300 bg-zinc-900 text-white"
                    >
                        <div
                            className="absolute inset-0 bg-cover bg-center opacity-65"
                            style={{ backgroundImage: `url('${offer.image}')` }}
                        />
                        <div className="absolute inset-0 bg-linear-to-t from-zinc-950 via-zinc-950/30 to-transparent" />
                        <div className="relative flex h-full flex-col justify-between p-4">
                            <div className="flex items-center justify-between gap-3">
                                <span className="border border-white/70 bg-white/10 px-2 py-1 text-xs font-semibold uppercase tracking-normal">
                                    {offer.code}
                                </span>
                                <span className="text-sm font-medium">
                                    From {offer.price}
                                </span>
                            </div>
                            <div>
                                <div className="text-sm font-medium">
                                    Travel from {offer.date}
                                </div>
                                <div className="mt-1 text-2xl font-semibold text-white">
                                    {offer.route}
                                </div>
                            </div>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}

function SearchableAirport({
    airports,
    label,
    value,
    error,
    isOpen,
    onToggle,
    onClose,
    onChange,
}: {
    airports: Airport[];
    label: string;
    value: number | null;
    error?: string;
    isOpen: boolean;
    onToggle: () => void;
    onClose: () => void;
    onChange: (id: number) => void;
}) {
    const [query, setQuery] = useState('');
    const selected = airports.find((airport) => airport.id === value);
    const filtered = airports.filter((airport) => {
        const term = `${airport.code} ${airport.name}`.toLowerCase();

        return term.includes(query.toLowerCase());
    });

    return (
        <div
            className={`relative flex flex-col gap-2 ${isOpen ? 'z-40' : 'z-10'}`}
        >
            <span className="text-xs font-semibold uppercase tracking-normal text-zinc-600">
                {label}
            </span>
            <button
                type="button"
                className={`${inputClass} flex items-center justify-between gap-3`}
                onClick={onToggle}
            >
                <span className={selected ? 'text-zinc-950' : 'text-zinc-500'}>
                    {selected
                        ? `${selected.code} - ${selected.name}`
                        : `Choose ${label.toLowerCase()}`}
                </span>
                <ChevronDown className="size-4 text-zinc-500" />
            </button>
            {isOpen && (
                <div className="absolute top-full left-0 z-40 mt-2 w-[calc(100vw-2rem)] max-w-[22rem] border border-zinc-950 bg-white sm:w-[22rem]">
                    <input
                        autoFocus
                        className={inputClass}
                        placeholder="Search code or airport"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <div className="max-h-64 overflow-y-auto">
                        {filtered.map((airport) => (
                            <button
                                type="button"
                                key={airport.id}
                                className="flex w-full items-center justify-between border-t border-zinc-200 px-3 py-3 text-left text-sm outline-none hover:bg-zinc-100 focus:bg-red-50"
                                onClick={() => {
                                    onChange(airport.id);
                                    onClose();
                                    setQuery('');
                                }}
                            >
                                <span>{airport.name}</span>
                                <span className="font-semibold text-red-700">
                                    {airport.code}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            )}
            {error && <span className="text-xs text-red-700">{error}</span>}
        </div>
    );
}

function SegmentedControl({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: [string, string][];
    onChange: (value: string) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            <span className="text-xs font-semibold uppercase tracking-normal text-zinc-600">
                {label}
            </span>
            <div className="grid h-12 min-w-0 grid-cols-2 border border-zinc-300">
                {options.map(([optionValue, optionLabel]) => (
                    <button
                        type="button"
                        key={optionValue}
                        className={`min-w-0 whitespace-nowrap px-2 text-sm font-medium outline-none transition-colors focus:border focus:border-red-600 ${
                            value === optionValue
                                ? 'bg-zinc-950 text-white'
                                : 'bg-white text-zinc-950 hover:bg-zinc-100'
                        }`}
                        onClick={() => onChange(optionValue)}
                    >
                        {optionLabel}
                    </button>
                ))}
            </div>
        </div>
    );
}

function DatePicker({
    label,
    value,
    disabled = false,
    error,
    isOpen,
    onToggle,
    onClose,
    onChange,
}: {
    label: string;
    value: string;
    disabled?: boolean;
    error?: string;
    isOpen: boolean;
    onToggle: () => void;
    onClose: () => void;
    onChange: (date: string) => void;
}) {
    const [month, setMonth] = useState(() => startOfMonth(parseDate(value)));
    const days = useMemo(() => calendarDays(month), [month]);

    return (
        <div
            className={`relative flex flex-col gap-2 ${isOpen ? 'z-40' : 'z-10'}`}
        >
            <span className="text-xs font-semibold uppercase tracking-normal text-zinc-600">
                {label}
            </span>
            <button
                type="button"
                disabled={disabled}
                className={`${inputClass} flex items-center justify-between gap-3 disabled:bg-zinc-100 disabled:text-zinc-400`}
                onClick={onToggle}
            >
                <span>{formatDisplayDate(value)}</span>
                <CalendarDays className="size-4 text-zinc-500" />
            </button>
            {isOpen && !disabled && (
                <div className="absolute top-full left-0 z-40 mt-2 w-[calc(100vw-2rem)] max-w-[24rem] border border-zinc-950 bg-white p-3 sm:w-[24rem]">
                    <div className="mb-3 flex items-center justify-between">
                        <button
                            type="button"
                            className={controlClass}
                            onClick={() => setMonth(addMonths(month, -1))}
                        >
                            Prev
                        </button>
                        <span className="text-sm font-semibold">
                            {month.toLocaleDateString('en-US', {
                                month: 'long',
                                year: 'numeric',
                            })}
                        </span>
                        <button
                            type="button"
                            className={controlClass}
                            onClick={() => setMonth(addMonths(month, 1))}
                        >
                            Next
                        </button>
                    </div>
                    <div className="grid grid-cols-7 border border-zinc-200 text-center text-xs font-semibold text-zinc-500">
                        {['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].map(
                            (day) => (
                                <div key={day} className="border-r border-zinc-200 py-2 last:border-r-0">
                                    {day}
                                </div>
                            ),
                        )}
                    </div>
                    <div className="grid grid-cols-7 border-r border-b border-zinc-200">
                        {days.map((day) => {
                            const iso = toIsoDate(day.date);
                            const isSelected = iso === value;

                            return (
                                <button
                                    type="button"
                                    key={iso}
                                    className={`h-10 border-t border-l border-zinc-200 text-sm outline-none transition-colors focus:border-red-600 ${
                                        day.inMonth
                                            ? 'text-zinc-950'
                                            : 'text-zinc-400'
                                    } ${
                                        isSelected
                                            ? 'bg-red-700 text-white'
                                            : 'hover:bg-zinc-100'
                                    }`}
                                    onClick={() => {
                                        onChange(iso);
                                        onClose();
                                    }}
                                >
                                    {day.date.getDate()}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}
            {error && <span className="text-xs text-red-700">{error}</span>}
        </div>
    );
}

function PassengerPicker({
    adults,
    children,
    babies,
    errors,
    canUseFullSearch,
    searchMode,
    isOpen,
    onToggle,
    onChange,
    onSearchModeChange,
}: {
    adults: number;
    children: number;
    babies: number;
    errors: Record<string, string>;
    canUseFullSearch: boolean;
    searchMode: Filters['search_mode'];
    isOpen: boolean;
    onToggle: () => void;
    onChange: (
        key: 'adults' | 'children' | 'babies',
        value: number,
    ) => void;
    onSearchModeChange: (value: Filters['search_mode']) => void;
}) {
    const total = adults + children + babies;
    const label = `${total} ${total === 1 ? 'Passenger' : 'Passengers'}`;
    const maxPassengers = 9;

    return (
        <div
            className={`relative flex flex-col gap-2 ${isOpen ? 'z-40' : 'z-10'}`}
        >
            <span className="text-xs font-semibold uppercase tracking-normal text-zinc-600">
                Passengers
            </span>
            <button
                type="button"
                className={`${inputClass} flex items-center justify-between gap-3`}
                onClick={onToggle}
            >
                <span>{label}</span>
                <Users className="size-4 text-zinc-500" />
            </button>
            {isOpen && (
                <div className="absolute top-full left-0 z-40 mt-2 w-[calc(100vw-2rem)] max-w-[26rem] border border-zinc-950 bg-white p-3 sm:w-[26rem]">
                    <PassengerRow
                        icon={<Users className="size-4" />}
                        label="Adult"
                        value={adults}
                        min={1}
                        max={maxPassengers - children - babies}
                        onChange={(value) =>
                            onChange(
                                'adults',
                                clamp(value, 1, maxPassengers - children - babies),
                            )
                        }
                    />
                    <PassengerRow
                        icon={<Users className="size-4" />}
                        label="Child"
                        value={children}
                        min={0}
                        max={maxPassengers - adults - babies}
                        onChange={(value) =>
                            onChange(
                                'children',
                                clamp(value, 0, maxPassengers - adults - babies),
                            )
                        }
                    />
                    <PassengerRow
                        icon={<Baby className="size-4" />}
                        label="Baby"
                        value={babies}
                        min={0}
                        max={maxPassengers - adults - children}
                        onChange={(value) =>
                            onChange(
                                'babies',
                                clamp(value, 0, maxPassengers - adults - children),
                            )
                        }
                    />
                    {canUseFullSearch && (
                        <div className="mt-3 border-t border-zinc-300 pt-3">
                            <SegmentedControl
                                label="Fare view"
                                value={searchMode}
                                options={[
                                    ['basic', 'Best'],
                                    ['full', 'All fares'],
                                ]}
                                onChange={(value) =>
                                    onSearchModeChange(
                                        value as Filters['search_mode'],
                                    )
                                }
                            />
                        </div>
                    )}
                </div>
            )}
            {(errors.adults || errors.children || errors.babies) && (
                <span className="text-xs text-red-700">
                    {errors.adults || errors.children || errors.babies}
                </span>
            )}
        </div>
    );
}

function PassengerRow({
    icon,
    label,
    value,
    min,
    max,
    onChange,
}: {
    icon: ReactNode;
    label: string;
    value: number;
    min: number;
    max: number;
    onChange: (value: number) => void;
}) {
    return (
        <div className="flex items-center justify-between border-b border-zinc-200 py-3 last:border-b-0">
            <div className="flex items-center gap-2 text-sm font-medium">
                {icon}
                {label}
            </div>
            <div className="flex items-center border border-zinc-300">
                <button
                    type="button"
                    className="grid size-9 place-items-center outline-none hover:bg-zinc-100 focus:border focus:border-red-600 disabled:text-zinc-300"
                    disabled={value <= min}
                    onClick={() => onChange(value - 1)}
                >
                    <Minus className="size-4" />
                </button>
                <span className="grid h-9 w-10 place-items-center border-x border-zinc-300 text-sm font-semibold">
                    {value}
                </span>
                <button
                    type="button"
                    className="grid size-9 place-items-center outline-none hover:bg-zinc-100 focus:border focus:border-red-600 disabled:text-zinc-300"
                    disabled={value >= max}
                    onClick={() => onChange(value + 1)}
                >
                    <Plus className="size-4" />
                </button>
            </div>
        </div>
    );
}

function ResultsPanel({
    results,
    onPurchase,
}: {
    results: Results;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
}) {
    if (!results) {
        return (
            <section className="border border-dashed border-zinc-400 bg-white p-8 text-center text-sm font-medium text-zinc-600">
                Enter a route and date to search.
            </section>
        );
    }

    return (
        <div className="grid gap-6">
            <FlightLeg
                title="Outbound"
                flights={results.outbound}
                seatPassengers={results.seat_passengers}
                onPurchase={onPurchase}
            />
            {results.return.length > 0 && (
                <FlightLeg
                    title="Return"
                    flights={results.return}
                    seatPassengers={results.seat_passengers}
                    onPurchase={onPurchase}
                />
            )}
        </div>
    );
}

function FlightLeg({
    title,
    flights,
    seatPassengers,
    onPurchase,
}: {
    title: string;
    flights: FlightResult[];
    seatPassengers: number;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
}) {
    return (
        <section className="border border-zinc-300 bg-white">
            <div className="flex items-center justify-between border-b border-zinc-300 px-4 py-3">
                <h2 className="text-lg font-semibold">{title}</h2>
                <span className="text-sm text-zinc-600">
                    {flights.length} {flights.length === 1 ? 'flight' : 'flights'}
                </span>
            </div>
            {flights.length === 0 ? (
                <div className="p-6 text-sm font-medium text-zinc-600">
                    No flights found.
                </div>
            ) : (
                <div className="divide-y divide-zinc-300">
                    {flights.map((flight) => (
                        <FlightRow
                            key={flight.id}
                            flight={flight}
                            seatPassengers={seatPassengers}
                            onPurchase={onPurchase}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

function FlightRow({
    flight,
    seatPassengers,
    onPurchase,
}: {
    flight: FlightResult;
    seatPassengers: number;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
}) {
    const hasFullFareMatrix = Array.isArray(flight.fares);

    return (
        <article className="grid gap-4 p-4 xl:grid-cols-[280px_1fr]">
            <div className="border border-zinc-200 p-3">
                <div className="flex items-center justify-between">
                    <span className="text-xl font-semibold">
                        {flight.flight_number}
                    </span>
                    <span className="text-sm text-zinc-600">{flight.hour}</span>
                </div>
                <div className="mt-3 flex items-center gap-2 text-sm font-semibold">
                    <span>{flight.origin.code}</span>
                    <ArrowRight className="size-4 text-red-700" />
                    <span>{flight.destination.code}</span>
                </div>
                <div className="mt-2 text-sm text-zinc-600">
                    {formatDisplayDate(flight.date)}
                </div>
                <div className="mt-2 text-xs font-medium uppercase tracking-normal text-zinc-500">
                    {flight.plane_model}
                </div>
            </div>
            {hasFullFareMatrix ? (
                <FullFares
                    fares={flight.fares}
                    seatPassengers={seatPassengers}
                    flight={flight}
                    onPurchase={onPurchase}
                />
            ) : (
                <BasicFares
                    fares={flight.fares}
                    flight={flight}
                    onPurchase={onPurchase}
                />
            )}
        </article>
    );
}

function BasicFares({
    fares,
    flight,
    onPurchase,
}: {
    fares: Record<'A' | 'B' | 'C', Fare>;
    flight: FlightResult;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
}) {
    return (
        <div className="grid gap-3 md:grid-cols-3">
            {(['A', 'B', 'C'] as const).map((classLetter) => {
                const fare = fares[classLetter];

                return (
                    <div key={classLetter} className="border border-zinc-200 p-3">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-semibold">
                                Class {fare.class_letters ?? classLetter}
                            </span>
                            {fare.available ? (
                                <span className="text-xs font-semibold text-red-700">
                                    {fare.count_available} left
                                </span>
                            ) : (
                                <span className="text-xs font-semibold text-zinc-500">
                                    Not available
                                </span>
                            )}
                        </div>
                        {fare.available ? (
                            <>
                                <div className="mt-3 text-2xl font-semibold">
                                    ${fare.base_price_usd}
                                </div>
                                <FareTerms fare={fare} compact />
                                <button
                                    type="button"
                                    className="mt-4 h-10 w-full border border-red-700 bg-red-700 text-sm font-semibold text-white outline-none hover:bg-red-800 focus:border-zinc-950"
                                    onClick={() => onPurchase(flight, fare)}
                                >
                                    Buy
                                </button>
                            </>
                        ) : (
                            <div className="mt-3 text-sm text-zinc-500">
                                No seats for this party.
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function FullFares({
    fares,
    seatPassengers,
    flight,
    onPurchase,
}: {
    fares: Fare[];
    seatPassengers: number;
    flight: FlightResult;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
}) {
    const [selectedFeatureFilters, setSelectedFeatureFilters] = useState<
        FullFareFeatureFilter[]
    >([]);
    const filteredFares = useMemo(
        () =>
            fares.filter((fare) =>
                selectedFeatureFilters.every((filter) => {
                    const option = fullFareFeatureFilters.find(
                        (featureFilter) => featureFilter.key === filter,
                    );

                    return option ? option.matches(fare) : true;
                }),
            ),
        [fares, selectedFeatureFilters],
    );

    function toggleFeatureFilter(filter: FullFareFeatureFilter): void {
        setSelectedFeatureFilters((current) =>
            current.includes(filter)
                ? current.filter((currentFilter) => currentFilter !== filter)
                : [...current, filter],
        );
    }

    return (
        <div className="border border-zinc-200">
            <div className="border-b border-zinc-200 bg-zinc-50 p-3">
                <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-end">
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-normal text-zinc-600">
                            Filter by feature
                        </div>
                        <div className="mt-1 text-sm text-zinc-600">
                            Showing {filteredFares.length} of {fares.length} fares.
                        </div>
                    </div>
                    {selectedFeatureFilters.length > 0 && (
                        <button
                            type="button"
                            className="h-9 border border-zinc-300 bg-white px-3 text-sm font-semibold text-zinc-950 outline-none hover:border-red-700 hover:text-red-700 focus:border-red-600"
                            onClick={() => setSelectedFeatureFilters([])}
                        >
                            Clear filters
                        </button>
                    )}
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    {fullFareFeatureFilters.map((filter) => {
                        const isSelected = selectedFeatureFilters.includes(
                            filter.key,
                        );

                        return (
                            <label
                                key={filter.key}
                                className={`inline-flex h-9 cursor-pointer items-center gap-2 border px-3 text-sm font-semibold ${
                                    isSelected
                                        ? 'border-zinc-950 bg-zinc-950 text-white'
                                        : 'border-zinc-300 bg-white text-zinc-950 hover:border-red-700 hover:text-red-700'
                                }`}
                            >
                                <input
                                    type="checkbox"
                                    className="size-4 accent-red-700"
                                    checked={isSelected}
                                    onChange={() => toggleFeatureFilter(filter.key)}
                                />
                                {filter.label}
                            </label>
                        );
                    })}
                </div>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[860px] border-collapse text-left text-sm">
                    <thead className="bg-zinc-100 text-xs uppercase tracking-normal text-zinc-600">
                        <tr>
                            <th className="border-b border-zinc-300 px-3 py-3 font-semibold">
                                Class
                            </th>
                            <th className="border-b border-zinc-300 px-3 py-3 font-semibold">
                                Letters
                            </th>
                            <th className="border-b border-zinc-300 px-3 py-3 font-semibold">
                                Type
                            </th>
                            <th className="border-b border-zinc-300 px-3 py-3 font-semibold">
                                Price
                            </th>
                            <th className="border-b border-zinc-300 px-3 py-3 font-semibold">
                                Seats
                            </th>
                            <th className="border-b border-zinc-300 px-3 py-3 font-semibold">
                                Bags / seats
                            </th>
                            <th className="border-b border-zinc-300 px-3 py-3 font-semibold">
                                Change
                            </th>
                            <th className="border-b border-zinc-300 px-3 py-3 font-semibold">
                                Refund
                            </th>
                            <th className="border-b border-zinc-300 px-3 py-3 font-semibold">
                                Buy
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredFares.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={9}
                                    className="px-3 py-6 text-center text-sm font-medium text-zinc-600"
                                >
                                    No fares match the selected features.
                                </td>
                            </tr>
                        ) : (
                            filteredFares.map((fare) => (
                                <tr
                                    key={fare.id}
                                    className={
                                        fare.available
                                            ? 'bg-white'
                                            : 'bg-zinc-50 text-zinc-500'
                                    }
                                >
                                    <td className="border-b border-zinc-200 px-3 py-3 font-medium">
                                        {fare.class}
                                    </td>
                                    <td className="border-b border-zinc-200 px-3 py-3 font-semibold">
                                        {fare.class_letters}
                                    </td>
                                    <td className="border-b border-zinc-200 px-3 py-3">
                                        {fareTypeLabel(fare.fare_type)}
                                    </td>
                                    <td className="border-b border-zinc-200 px-3 py-3">
                                        ${fare.base_price_usd}
                                    </td>
                                    <td className="border-b border-zinc-200 px-3 py-3">
                                        {fare.count_available}{' '}
                                        {fare.count_available! >= seatPassengers
                                            ? 'available'
                                            : 'not available'}
                                    </td>
                                    <td className="border-b border-zinc-200 px-3 py-3">
                                        {fare.checked_baggage_kg} kg /{' '}
                                        {fare.cabin_baggage_kg} kg ·{' '}
                                        {seatSelectionLabel(
                                            fare.seat_selection_free,
                                        )}
                                    </td>
                                    <td className="border-b border-zinc-200 px-3 py-3">
                                        {feeLabel(
                                            fare.change_fee_usd!,
                                            fare.latest_change_hours,
                                        )}
                                    </td>
                                    <td className="border-b border-zinc-200 px-3 py-3">
                                        {feeLabel(
                                            fare.refund_fee_usd!,
                                            fare.latest_refund_hours,
                                        )}
                                    </td>
                                    <td className="border-b border-zinc-200 px-3 py-3">
                                        <button
                                            type="button"
                                            disabled={!fare.available}
                                            className="h-9 border border-red-700 bg-red-700 px-4 text-sm font-semibold text-white outline-none hover:bg-red-800 focus:border-zinc-950 disabled:border-zinc-300 disabled:bg-zinc-100 disabled:text-zinc-400"
                                            onClick={() => onPurchase(flight, fare)}
                                        >
                                            Buy
                                        </button>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function FareTerms({ fare, compact = false }: { fare: Fare; compact?: boolean }) {
    return (
        <div
            className={`mt-3 grid gap-2 text-xs text-zinc-600 ${
                compact ? '' : 'sm:grid-cols-2'
            }`}
        >
            <span className="inline-flex items-center gap-1">
                <Luggage className="size-3.5" />
                {fare.checked_baggage_kg} kg checked
            </span>
            <span className="inline-flex items-center gap-1">
                <Briefcase className="size-3.5" />
                {fare.cabin_baggage_kg} kg cabin
            </span>
            <span>{seatSelectionLabel(fare.seat_selection_free)}</span>
            <span>{feeLabel(fare.change_fee_usd!, fare.latest_change_hours)}</span>
            <span>{feeLabel(fare.refund_fee_usd!, fare.latest_refund_hours)}</span>
        </div>
    );
}

function PurchaseModal({
    target,
    filters,
    customer,
    errors,
    onClose,
}: {
    target: PurchaseTarget;
    filters: Filters;
    customer: Props['customer'];
    errors: Record<string, string>;
    onClose: () => void;
}) {
    const [buyer, setBuyer] = useState({
        first_name: customer.firstName,
        last_name: customer.lastName,
        passport_number: '',
    });
    const seatPassengers = filters.adults + filters.children;
    const totalPrice = (target.fare.base_price_usd ?? 0) * seatPassengers;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        router.post(
            '/purchase',
            {
                availability_id: target.fare.id,
                first_name: buyer.first_name,
                last_name: buyer.last_name,
                passport_number: buyer.passport_number,
                adults: filters.adults,
                children: filters.children,
                babies: filters.babies,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
            },
        );
    }

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-zinc-950/60 p-4">
            <form
                onSubmit={submit}
                className="w-full max-w-xl border border-zinc-950 bg-white p-5 shadow-[0_20px_80px_rgba(0,0,0,0.35)]"
            >
                <div className="flex items-start justify-between gap-4 border-b border-zinc-300 pb-4">
                    <div>
                        <h2 className="text-xl font-semibold">Buy ticket</h2>
                        <p className="mt-1 text-sm text-zinc-600">
                            {target.flight.flight_number} ·{' '}
                            {target.flight.origin.code} to{' '}
                            {target.flight.destination.code} ·{' '}
                            {target.fare.class_letters}
                        </p>
                    </div>
                    <button
                        type="button"
                        className="border border-zinc-300 px-3 py-2 text-sm font-medium outline-none hover:border-red-700 focus:border-red-600"
                        onClick={onClose}
                    >
                        Close
                    </button>
                </div>

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <label className="flex flex-col gap-2">
                        <span className="text-xs font-semibold uppercase tracking-normal text-zinc-600">
                            First name
                        </span>
                        <input
                            className={inputClass}
                            value={buyer.first_name}
                            onChange={(event) =>
                                setBuyer((current) => ({
                                    ...current,
                                    first_name: event.target.value,
                                }))
                            }
                        />
                        {errors.first_name && (
                            <span className="text-xs text-red-700">
                                {errors.first_name}
                            </span>
                        )}
                    </label>
                    <label className="flex flex-col gap-2">
                        <span className="text-xs font-semibold uppercase tracking-normal text-zinc-600">
                            Last name
                        </span>
                        <input
                            className={inputClass}
                            value={buyer.last_name}
                            onChange={(event) =>
                                setBuyer((current) => ({
                                    ...current,
                                    last_name: event.target.value,
                                }))
                            }
                        />
                        {errors.last_name && (
                            <span className="text-xs text-red-700">
                                {errors.last_name}
                            </span>
                        )}
                    </label>
                    <label className="flex flex-col gap-2 sm:col-span-2">
                        <span className="text-xs font-semibold uppercase tracking-normal text-zinc-600">
                            Passport number
                        </span>
                        <input
                            className={inputClass}
                            value={buyer.passport_number}
                            onChange={(event) =>
                                setBuyer((current) => ({
                                    ...current,
                                    passport_number: event.target.value,
                                }))
                            }
                        />
                        {errors.passport_number && (
                            <span className="text-xs text-red-700">
                                {errors.passport_number}
                            </span>
                        )}
                    </label>
                </div>

                <div className="mt-5 border border-zinc-300 bg-zinc-100 p-3 text-sm">
                    <div className="flex justify-between gap-4">
                        <span>Seated passengers</span>
                        <span className="font-semibold">{seatPassengers}</span>
                    </div>
                    <div className="mt-2 flex justify-between gap-4">
                        <span>Fare</span>
                        <span className="font-semibold">
                            ${target.fare.base_price_usd} × {seatPassengers}
                        </span>
                    </div>
                    <div className="mt-3 flex justify-between gap-4 border-t border-zinc-300 pt-3 text-base">
                        <span className="font-semibold">Total</span>
                        <span className="font-semibold">${totalPrice}</span>
                    </div>
                </div>

                {errors.availability_id && (
                    <div className="mt-4 border border-red-700 bg-red-50 p-3 text-sm font-medium text-red-700">
                        {errors.availability_id}
                    </div>
                )}

                <button
                    type="submit"
                    className="mt-5 h-12 w-full border border-red-700 bg-red-700 text-sm font-semibold text-white outline-none hover:bg-red-800 focus:border-zinc-950"
                >
                    Confirm purchase
                </button>
            </form>
        </div>
    );
}

function feeLabel(feeUsd: number, latestHours?: number | null) {
    const fee = feeUsd === 0 ? 'Free' : `$${feeUsd}`;

    if (latestHours === null || latestHours === undefined) {
        return `${fee}, full penalty`;
    }

    return `${fee} until ${latestHours}h`;
}

function seatSelectionLabel(isFree?: boolean) {
    return isFree ? 'Free seat selection' : 'Paid seat selection';
}

function fareTypeLabel(fareType?: 'one_way' | 'round_trip') {
    return fareType === 'round_trip' ? 'Round trip' : 'One way';
}

function parseDate(value: string) {
    return new Date(`${value}T00:00:00`);
}

function startOfMonth(date: Date) {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

function addMonths(date: Date, months: number) {
    return new Date(date.getFullYear(), date.getMonth() + months, 1);
}

function calendarDays(month: Date) {
    const first = startOfMonth(month);
    const mondayOffset = (first.getDay() + 6) % 7;
    const start = new Date(first);
    start.setDate(first.getDate() - mondayOffset);

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(start);
        date.setDate(start.getDate() + index);

        return {
            date,
            inMonth: date.getMonth() === month.getMonth(),
        };
    });
}

function toIsoDate(date: Date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function formatDisplayDate(value: string) {
    return parseDate(value).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function clamp(value: number, min: number, max: number) {
    return Math.min(Math.max(value, min), Math.max(min, max));
}
