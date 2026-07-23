import { Head, router } from '@inertiajs/react';
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

const inputClass =
    'h-12 w-full border border-zinc-300 bg-white px-3 text-left text-sm text-zinc-950 outline-none transition-colors focus:border-red-600';

const controlClass =
    'h-12 border border-zinc-300 bg-white px-4 text-sm font-medium text-zinc-950 outline-none transition-colors hover:border-zinc-500 focus:border-red-600';

function AirportBackground() {
    return (
        <div className="pointer-events-none absolute inset-0 overflow-hidden">
            <div
                className="absolute inset-0 bg-cover bg-center opacity-20"
                style={{
                    backgroundImage:
                        "url('https://unsplash.com/photos/GsVO12cQrzA/download?force=true&w=1800')",
                }}
            />
            <div className="absolute inset-0 bg-zinc-100/80" />
            <div className="absolute inset-0 bg-[linear-gradient(90deg,rgba(39,39,42,0.055)_1px,transparent_1px),linear-gradient(0deg,rgba(39,39,42,0.045)_1px,transparent_1px)] bg-[size:72px_72px]" />
            <div className="absolute top-20 left-[8%] h-px w-[78%] rotate-[-8deg] bg-red-700/20" />
            <div className="absolute top-56 left-[18%] h-px w-[58%] rotate-[12deg] bg-zinc-700/15" />
            <div className="absolute right-[10%] bottom-32 h-px w-[46%] rotate-[-18deg] bg-red-700/15" />
            <div className="absolute top-16 right-[17%] size-2 border border-red-700 bg-white" />
            <div className="absolute top-48 left-[13%] size-2 border border-zinc-700 bg-white" />
            <div className="absolute right-[27%] bottom-44 size-2 border border-red-700 bg-white" />
            <div className="absolute -right-28 top-28 h-80 w-80 border border-zinc-300/70" />
            <div className="absolute -left-20 bottom-12 h-64 w-64 border border-zinc-300/70" />
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

    return (
        <>
            <Head title="Airline Price Search" />
            <main className="relative min-h-screen overflow-hidden bg-zinc-100 font-sans text-zinc-950">
                <AirportBackground />
                <div className="relative z-10 mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8">
                    <header className="flex flex-col justify-between gap-3 border-b border-zinc-300 pb-5 sm:flex-row sm:items-end">
                        <div>
                            <div className="flex items-center gap-2 text-sm font-semibold uppercase tracking-normal text-red-700">
                                <Plane className="size-4" />
                                Dynamic Pricer Air
                            </div>
                            <h1 className="mt-2 text-3xl font-semibold tracking-normal text-zinc-950">
                                Price search
                            </h1>
                        </div>
                        <nav className="flex flex-wrap gap-2">
                            {!customer.isAuthenticated && (
                                <>
                                    <a
                                        className="inline-flex h-10 items-center justify-center border border-zinc-950 bg-white px-4 text-sm font-medium text-zinc-950 outline-none transition-colors hover:border-red-700 hover:text-red-700 focus:border-red-600"
                                        href="/register"
                                    >
                                        Sign up
                                    </a>
                                    <a
                                        className="inline-flex h-10 items-center justify-center border border-zinc-300 bg-white px-4 text-sm font-medium text-zinc-950 outline-none transition-colors hover:border-red-700 hover:text-red-700 focus:border-red-600"
                                        href="/login"
                                    >
                                        Log in
                                    </a>
                                </>
                            )}
                            {customer.isAdmin && (
                                <a
                                    className="inline-flex h-10 items-center justify-center border border-zinc-950 bg-white px-4 text-sm font-medium text-zinc-950 outline-none transition-colors hover:border-red-700 hover:text-red-700 focus:border-red-600"
                                    href="/admin"
                                >
                                    Admin
                                </a>
                            )}
                        </nav>
                    </header>

                    <form
                        onSubmit={submit}
                        className="border border-zinc-300 bg-white/95 p-4 shadow-[0_12px_40px_rgba(24,24,27,0.08)] sm:p-5"
                    >
                        <div className="grid gap-4">
                            <div className="grid gap-4 lg:grid-cols-[1.3fr_1.3fr_1fr_1fr]">
                                <SearchableAirport
                                    airports={airports}
                                    label="Origin"
                                    value={form.origin_airport_id}
                                    error={errors.origin_airport_id}
                                    isOpen={openPanel === 'origin'}
                                    onToggle={() =>
                                        setOpenPanel(
                                            openPanel === 'origin' ? null : 'origin',
                                        )
                                    }
                                    onClose={() => setOpenPanel(null)}
                                    onChange={(id) =>
                                        update('origin_airport_id', id)
                                    }
                                />
                                <SearchableAirport
                                    airports={airports}
                                    label="Destination"
                                    value={form.destination_airport_id}
                                    error={errors.destination_airport_id}
                                    isOpen={openPanel === 'destination'}
                                    onToggle={() =>
                                        setOpenPanel(
                                            openPanel === 'destination'
                                                ? null
                                                : 'destination',
                                        )
                                    }
                                    onClose={() => setOpenPanel(null)}
                                    onChange={(id) =>
                                        update('destination_airport_id', id)
                                    }
                                />
                                <SegmentedControl
                                    label="Trip"
                                    value={form.trip_type}
                                    options={[
                                        ['one_way', 'One way'],
                                        ['round_trip', 'Round trip'],
                                    ]}
                                    onChange={(value) =>
                                        update(
                                            'trip_type',
                                            value as Filters['trip_type'],
                                        )
                                    }
                                />
                                {canUseFullSearch ? (
                                    <SegmentedControl
                                        label="Mode"
                                        value={form.search_mode}
                                        options={[
                                            ['basic', 'Basic'],
                                            ['full', 'Full'],
                                        ]}
                                        onChange={(value) =>
                                            update(
                                                'search_mode',
                                                value as Filters['search_mode'],
                                            )
                                        }
                                    />
                                ) : (
                                    <div className="flex flex-col gap-2">
                                        <span className="text-xs font-semibold uppercase tracking-normal text-zinc-600">
                                            Mode
                                        </span>
                                        <div className="flex h-12 items-center border border-zinc-300 bg-zinc-100 px-3 text-sm font-medium text-zinc-600">
                                            Basic
                                        </div>
                                    </div>
                                )}
                            </div>
                            <div className="grid gap-4 lg:grid-cols-[1fr_1fr_1.2fr_auto]">
                                <DatePicker
                                    label="Depart"
                                    value={form.depart_date}
                                    error={errors.depart_date}
                                    isOpen={openPanel === 'depart'}
                                    onToggle={() =>
                                        setOpenPanel(
                                            openPanel === 'depart' ? null : 'depart',
                                        )
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
                                        setOpenPanel(
                                            openPanel === 'return' ? null : 'return',
                                        )
                                    }
                                    onClose={() => setOpenPanel(null)}
                                    onChange={(date) => update('return_date', date)}
                                />
                                <PassengerPicker
                                    adults={form.adults}
                                    children={form.children}
                                    babies={form.babies}
                                    errors={errors}
                                    isOpen={openPanel === 'passengers'}
                                    onToggle={() =>
                                        setOpenPanel(
                                            openPanel === 'passengers'
                                                ? null
                                                : 'passengers',
                                        )
                                    }
                                    onChange={(key, value) => update(key, value)}
                                />
                                <div className="flex items-end">
                                    <button
                                        type="submit"
                                        className="inline-flex h-12 w-full items-center justify-center gap-2 border border-red-700 bg-red-700 px-6 text-sm font-semibold text-white outline-none transition-colors hover:bg-red-800 focus:border-zinc-950 lg:w-auto"
                                    >
                                        <Search className="size-4" />
                                        Search
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                    <OfferCarousel />

                    <ResultsPanel
                        mode={canUseFullSearch ? form.search_mode : 'basic'}
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

function OfferCarousel() {
    const offers = [
        {
            route: 'IST to LHR',
            date: 'Aug 6',
            price: '$158',
            image: 'https://unsplash.com/photos/S3N2cPMWhEA/download?force=true&w=900',
        },
        {
            route: 'AMS to IST',
            date: 'Aug 9',
            price: '$132',
            image: 'https://unsplash.com/photos/GsVO12cQrzA/download?force=true&w=900',
        },
        {
            route: 'IST to CDG',
            date: 'Aug 12',
            price: '$145',
            image: 'https://unsplash.com/photos/RduE7aSO2SA/download?force=true&w=900',
        },
        {
            route: 'FRA to JFK',
            date: 'Aug 18',
            price: '$516',
            image: 'https://unsplash.com/photos/GsVO12cQrzA/download?force=true&w=900',
        },
    ];

    return (
        <section className="border border-zinc-300 bg-white/95 p-4">
            <div className="mb-3 flex items-end justify-between gap-4">
                <div>
                    <h2 className="text-lg font-semibold">Cheap dates</h2>
                    <p className="text-sm text-zinc-600">
                        Highlighted low-fare travel windows.
                    </p>
                </div>
            </div>
            <div className="flex snap-x gap-3 overflow-x-auto pb-1">
                {offers.map((offer) => (
                    <article
                        key={`${offer.route}-${offer.date}`}
                        className="relative h-40 min-w-[260px] snap-start overflow-hidden border border-zinc-300 bg-zinc-900 text-white"
                    >
                        <div
                            className="absolute inset-0 bg-cover bg-center opacity-65"
                            style={{ backgroundImage: `url('${offer.image}')` }}
                        />
                        <div className="absolute inset-0 bg-linear-to-t from-zinc-950 via-zinc-950/30 to-transparent" />
                        <div className="relative flex h-full flex-col justify-end p-4">
                            <div className="text-sm font-medium">{offer.date}</div>
                            <div className="mt-1 flex items-end justify-between gap-4">
                                <div className="text-xl font-semibold">
                                    {offer.route}
                                </div>
                                <div className="text-2xl font-semibold text-white">
                                    {offer.price}
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
        <label className="relative flex flex-col gap-2">
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
                <div className="absolute top-full right-0 left-0 z-20 border border-zinc-950 bg-white">
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
        </label>
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
            <div className="grid h-12 grid-cols-2 border border-zinc-300">
                {options.map(([optionValue, optionLabel]) => (
                    <button
                        type="button"
                        key={optionValue}
                        className={`px-3 text-sm font-medium outline-none transition-colors focus:border focus:border-red-600 ${
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
        <label className="relative flex flex-col gap-2">
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
                <div className="absolute top-full right-0 left-0 z-20 border border-zinc-950 bg-white p-3">
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
        </label>
    );
}

function PassengerPicker({
    adults,
    children,
    babies,
    errors,
    isOpen,
    onToggle,
    onChange,
}: {
    adults: number;
    children: number;
    babies: number;
    errors: Record<string, string>;
    isOpen: boolean;
    onToggle: () => void;
    onChange: (
        key: 'adults' | 'children' | 'babies',
        value: number,
    ) => void;
}) {
    const total = adults + children + babies;
    const label = `${total} ${total === 1 ? 'Passenger' : 'Passengers'}`;
    const maxPassengers = 9;

    return (
        <div className="relative flex flex-col gap-2">
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
                <div className="absolute top-full right-0 left-0 z-20 border border-zinc-950 bg-white p-3">
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
    mode,
    results,
    onPurchase,
}: {
    mode: Filters['search_mode'];
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
                mode={mode}
                title="Outbound"
                flights={results.outbound}
                seatPassengers={results.seat_passengers}
                onPurchase={onPurchase}
            />
            {results.return.length > 0 && (
                <FlightLeg
                    mode={mode}
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
    mode,
    flights,
    seatPassengers,
    onPurchase,
}: {
    title: string;
    mode: Filters['search_mode'];
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
                            mode={mode}
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
    mode,
    seatPassengers,
    onPurchase,
}: {
    flight: FlightResult;
    mode: Filters['search_mode'];
    seatPassengers: number;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
}) {
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
            {mode === 'basic' ? (
                <BasicFares
                    fares={flight.fares as Record<'A' | 'B' | 'C', Fare>}
                    flight={flight}
                    onPurchase={onPurchase}
                />
            ) : (
                <FullFares
                    fares={flight.fares as Fare[]}
                    seatPassengers={seatPassengers}
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
    return (
        <div className="overflow-x-auto border border-zinc-200">
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
                            Baggage
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
                    {fares.map((fare) => (
                        <tr
                            key={fare.id}
                            className={
                                fare.available ? 'bg-white' : 'bg-zinc-50 text-zinc-500'
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
                                {fare.cabin_baggage_kg} kg
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
                    ))}
                </tbody>
            </table>
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
