import {
    ArrowLeftRight,
    CalendarDays,
    ChevronDown,
    Minus,
    Plus,
    Search,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    clampPassengerCount,
    findAirportByCode,
    passengerLimit,
    totalPassengers,
} from '@/lib/flight-search';
import type {
    Airport,
    SearchFilters,
    SearchFormSubmit,
} from '@/types/flight-search';

type SearchCardProps = {
    airports: Airport[];
    filters: SearchFilters;
    canUseFullSearch: boolean;
    errors: Record<string, string>;
    onSubmit: (event: SearchFormSubmit) => void;
    onChange: <K extends keyof SearchFilters>(
        key: K,
        value: SearchFilters[K],
    ) => void;
    onQuickSearch: (
        originCode: string,
        destinationCode: string,
        date: string,
    ) => void;
    hasResults: boolean;
    variant?: 'home' | 'compact';
};

export function SearchCard({
    airports,
    filters,
    canUseFullSearch,
    errors,
    onSubmit,
    onChange,
    onQuickSearch,
    hasResults,
    variant = 'home',
}: SearchCardProps) {
    const [openPanel, setOpenPanel] = useState<string | null>(null);
    const isCompact = variant === 'compact';

    function closePanel() {
        setOpenPanel(null);
    }

    function swapAirports() {
        const origin = filters.origin_airport_id;
        const destination = filters.destination_airport_id;

        onChange('origin_airport_id', destination);
        onChange('destination_airport_id', origin);
    }

    return (
        <section
            id="booking"
            className={`relative z-20 ${isCompact ? '' : '-mt-14'}`}
        >
            <div className={isCompact ? '' : 'mx-auto max-w-6xl px-6'}>
                <div className="overflow-visible rounded-md border border-slate-200/80 bg-white opacity-100 shadow-sm transition-all duration-300 hover:border-slate-300 hover:shadow-md starting:opacity-0 motion-safe:starting:translate-y-2">
                    {!isCompact && <ServiceTabs />}
                    <form
                        className={isCompact ? 'p-3' : 'p-6'}
                        onSubmit={onSubmit}
                    >
                        <div
                            className={`grid grid-cols-1 items-end gap-x-1 gap-y-3 ${
                                isCompact
                                    ? 'xl:grid-cols-[minmax(0,1fr)_0_minmax(0,1fr)_minmax(210px,.9fr)_minmax(170px,.72fr)_132px]'
                                    : 'md:grid-cols-[minmax(0,1fr)_0_minmax(0,1fr)_minmax(210px,.95fr)_minmax(180px,.8fr)_148px]'
                            }`}
                        >
                            <AirportField
                                airports={airports}
                                label="From"
                                placeholder="Choose departure"
                                value={filters.origin_airport_id}
                                error={errors.origin_airport_id}
                                compact={isCompact}
                                isOpen={openPanel === 'origin'}
                                onToggle={() =>
                                    setOpenPanel(
                                        openPanel === 'origin'
                                            ? null
                                            : 'origin',
                                    )
                                }
                                onClose={closePanel}
                                onChange={(id) =>
                                    onChange('origin_airport_id', id)
                                }
                            />

                            <div className="relative z-[180] flex justify-center xl:-mx-6 xl:pb-px">
                                <button
                                    type="button"
                                    className="pointer-events-auto flex size-8 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 transition-colors hover:border-red-100 hover:bg-red-50 hover:text-red-800"
                                    aria-label="Swap departure and arrival airports"
                                    onClick={swapAirports}
                                >
                                    <ArrowLeftRight className="size-4" />
                                </button>
                            </div>

                            <AirportField
                                airports={airports}
                                label="To"
                                placeholder="Choose destination"
                                value={filters.destination_airport_id}
                                error={errors.destination_airport_id}
                                compact={isCompact}
                                isOpen={openPanel === 'destination'}
                                onToggle={() =>
                                    setOpenPanel(
                                        openPanel === 'destination'
                                            ? null
                                            : 'destination',
                                    )
                                }
                                onClose={closePanel}
                                onChange={(id) =>
                                    onChange('destination_airport_id', id)
                                }
                            />

                            <DateField
                                filters={filters}
                                errors={errors}
                                compact={isCompact}
                                onChange={onChange}
                            />

                            <PassengerField
                                filters={filters}
                                canUseFullSearch={canUseFullSearch}
                                errors={errors}
                                compact={isCompact}
                                isOpen={openPanel === 'passengers'}
                                onToggle={() =>
                                    setOpenPanel(
                                        openPanel === 'passengers'
                                            ? null
                                            : 'passengers',
                                    )
                                }
                                onChange={onChange}
                            />

                            <button
                                className={`inline-flex items-center justify-center gap-2 rounded-md bg-linear-to-br from-red-700 to-red-950 px-5 text-sm font-bold text-white transition-colors hover:bg-red-900 ${
                                    isCompact ? 'h-[50px]' : 'h-[58px]'
                                }`}
                                type="submit"
                            >
                                <Search className="size-4" />
                                Search
                            </button>
                        </div>

                        {hasResults && !isCompact && (
                            <div className="mt-4 flex items-center justify-end">
                                <button
                                    type="button"
                                    className="rounded-md border border-slate-200 px-3.5 py-2.5 text-xs font-bold text-slate-600 transition-colors hover:bg-slate-100"
                                    onClick={() =>
                                        onQuickSearch(
                                            'IST',
                                            'LHR',
                                            '2026-08-06',
                                        )
                                    }
                                >
                                    Reset sample route
                                </button>
                            </div>
                        )}
                    </form>
                </div>
            </div>
        </section>
    );
}

function ServiceTabs() {
    return (
        <div id="services" className="border-b border-slate-100">
            <div className="flex overflow-x-auto text-[13px] font-bold text-slate-500">
                <span className="inline-flex shrink-0 items-center gap-2 px-5 py-3.5 text-red-800 [box-shadow:inset_0_-3px_0_#991b1b]">
                    <Search className="size-4" />
                    Flight search
                </span>
                {['Check-in', 'Manage booking', 'Flight status'].map((item) => (
                    <button
                        key={item}
                        type="button"
                        className="inline-flex shrink-0 items-center gap-2 px-5 py-3.5 transition-colors hover:text-red-800"
                        title="Coming soon"
                    >
                        {item}
                    </button>
                ))}
            </div>
        </div>
    );
}

function AirportField({
    airports,
    label,
    placeholder,
    value,
    error,
    compact,
    isOpen,
    onToggle,
    onClose,
    onChange,
}: {
    airports: Airport[];
    label: string;
    placeholder: string;
    value: number | null;
    error?: string;
    compact: boolean;
    isOpen: boolean;
    onToggle: () => void;
    onClose: () => void;
    onChange: (id: number) => void;
}) {
    const [query, setQuery] = useState('');
    const selected = airports.find((airport) => airport.id === value);
    const filtered = useMemo(
        () =>
            airports.filter((airport) =>
                `${airport.code} ${airport.name}`
                    .toLowerCase()
                    .includes(query.toLowerCase()),
            ),
        [airports, query],
    );

    return (
        <div className={`relative ${isOpen ? 'z-[120]' : 'z-20'}`}>
            <button
                type="button"
                className={`flex w-full flex-col justify-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-3 text-left transition-colors hover:border-red-800 hover:bg-white ${
                    compact ? 'h-[50px]' : 'h-[58px]'
                }`}
                aria-expanded={isOpen}
                onClick={onToggle}
            >
                <span className="text-[10px] leading-none font-semibold text-slate-500 uppercase">
                    {label}
                </span>
                <span className="flex min-w-0 items-center justify-between gap-3 text-sm font-semibold text-slate-800">
                    <span className="truncate">
                        {selected
                            ? `${selected.code} - ${selected.name}`
                            : placeholder}
                    </span>
                    <ChevronDown className="size-4 shrink-0 text-slate-500" />
                </span>
            </button>
            {isOpen && (
                <div className="absolute top-full left-0 z-[140] mt-1 w-[min(440px,calc(100vw-48px))] rounded-md border border-slate-200 bg-white">
                    <input
                        autoFocus
                        className="h-11 w-full border-b border-slate-200 px-3 text-sm font-semibold outline-none focus:border-red-800"
                        placeholder="Search airport or code"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <div className="max-h-64 overflow-y-auto">
                        {filtered.map((airport) => (
                            <button
                                type="button"
                                key={airport.id}
                                className="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-3 py-3 text-left text-sm transition-colors last:border-b-0 hover:bg-red-50"
                                onClick={() => {
                                    onChange(airport.id);
                                    onClose();
                                    setQuery('');
                                }}
                            >
                                <span className="font-semibold text-slate-800">
                                    {airport.name}
                                </span>
                                <span className="rounded-md bg-red-50 px-2 py-1 text-xs font-bold text-red-800">
                                    {airport.code}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            )}
            {error && (
                <span className="mt-1 block text-xs text-red-700">{error}</span>
            )}
        </div>
    );
}

function DateField({
    filters,
    errors,
    compact,
    onChange,
}: {
    filters: SearchFilters;
    errors: Record<string, string>;
    compact: boolean;
    onChange: <K extends keyof SearchFilters>(
        key: K,
        value: SearchFilters[K],
    ) => void;
}) {
    const isRoundTrip = filters.trip_type === 'round_trip';
    const rootRef = useRef<HTMLDivElement>(null);
    const [isOpen, setIsOpen] = useState(false);
    const [calendarCursor, setCalendarCursor] = useState(() =>
        startOfMonth(parseIsoDate(filters.depart_date) ?? new Date()),
    );
    const [pickingReturn, setPickingReturn] = useState(false);
    const [hoverDate, setHoverDate] = useState<Date | null>(null);
    const departDate = parseIsoDate(filters.depart_date);
    const returnDate = parseIsoDate(filters.return_date);
    const previewEnd =
        isRoundTrip && pickingReturn && hoverDate ? hoverDate : returnDate;
    const calendarDays = useMemo(
        () => daysForMonth(calendarCursor),
        [calendarCursor],
    );

    useEffect(() => {
        function closeWhenClickingAway(event: MouseEvent) {
            if (
                rootRef.current &&
                !rootRef.current.contains(event.target as Node)
            ) {
                setIsOpen(false);
            }
        }

        document.addEventListener('mousedown', closeWhenClickingAway);

        return () => {
            document.removeEventListener('mousedown', closeWhenClickingAway);
        };
    }, []);

    function selectTripType(value: SearchFilters['trip_type']) {
        onChange('trip_type', value);

        if (value === 'round_trip') {
            if (
                departDate &&
                (!returnDate || compareDay(returnDate, departDate) < 0)
            ) {
                onChange('return_date', '');
                setCalendarCursor(startOfMonth(departDate));
                setPickingReturn(true);
                setHoverDate(null);

                return;
            }

            setPickingReturn(Boolean(departDate && !returnDate));
            setHoverDate(null);

            return;
        }

        onChange('return_date', '');
        setPickingReturn(false);
        setHoverDate(null);
    }

    function selectDate(date: Date) {
        if (isRoundTrip && pickingReturn) {
            if (departDate && compareDay(date, departDate) < 0) {
                onChange('depart_date', isoDate(date));
                onChange('return_date', isoDate(departDate));
                setPickingReturn(false);
                setHoverDate(null);
                setIsOpen(false);

                return;
            }

            onChange('return_date', isoDate(date));
            setPickingReturn(false);
            setHoverDate(null);
            setIsOpen(false);

            return;
        }

        onChange('depart_date', isoDate(date));

        if (isRoundTrip) {
            setPickingReturn(true);

            if (returnDate && compareDay(returnDate, date) < 0) {
                onChange('return_date', '');
            }

            setHoverDate(null);

            return;
        }

        setIsOpen(false);
    }

    function toggleDatePicker() {
        if (!isOpen && departDate) {
            setCalendarCursor(startOfMonth(departDate));
        }

        setIsOpen((current) => !current);
    }

    return (
        <div ref={rootRef} className="relative z-[90]">
            <button
                type="button"
                className={`grid w-full overflow-hidden rounded-md border border-slate-200 bg-slate-50 text-left transition-colors hover:border-red-800 hover:bg-white ${
                    isOpen ? 'border-red-800 bg-white' : ''
                } ${isRoundTrip ? 'grid-cols-2' : 'grid-cols-1'} ${
                    compact ? 'h-[50px]' : 'h-[58px]'
                }`}
                aria-expanded={isOpen}
                aria-controls="date-picker-popup"
                onClick={toggleDatePicker}
            >
                <span className="flex min-w-0 flex-col justify-center gap-1 px-3">
                    <span className="text-[10px] leading-none font-semibold text-slate-500 uppercase">
                        Depart
                    </span>
                    <span className="flex min-h-[18px] items-center gap-1">
                        <CalendarDays className="size-4 text-slate-400" />
                        <span className="text-[14px] leading-none font-bold text-slate-800">
                            {departDate
                                ? String(departDate.getDate()).padStart(2, '0')
                                : ''}
                        </span>
                        <span className="text-[14px] leading-none font-bold text-slate-800">
                            {departDate
                                ? monthNames[departDate.getMonth()]
                                : ''}
                        </span>
                    </span>
                </span>

                {isRoundTrip && (
                    <span className="flex min-w-0 flex-col justify-center gap-1 border-l border-slate-200 px-3">
                        <span className="text-[10px] leading-none font-semibold text-slate-500 uppercase">
                            Return
                        </span>
                        <span className="flex min-h-[18px] items-center gap-1">
                            <span className="text-[14px] leading-none font-bold text-slate-800">
                                {returnDate
                                    ? String(returnDate.getDate()).padStart(
                                          2,
                                          '0',
                                      )
                                    : 'Select'}
                            </span>
                            <span className="text-[14px] leading-none font-bold text-slate-800">
                                {returnDate
                                    ? monthNames[returnDate.getMonth()]
                                    : ''}
                            </span>
                        </span>
                    </span>
                )}
            </button>

            {isOpen && (
                <div
                    id="date-picker-popup"
                    className="absolute top-full left-0 z-[160] mt-1 w-[min(340px,calc(100vw-48px))] rounded-md border border-slate-200 bg-white p-2.5"
                >
                    <div
                        className="mb-2.5 grid grid-cols-2 gap-2"
                        role="radiogroup"
                        aria-label="Trip type"
                    >
                        {[
                            ['one_way', 'One-way'],
                            ['round_trip', 'Roundtrip'],
                        ].map(([value, label]) => (
                            <button
                                type="button"
                                key={value}
                                className={`px-1 py-1.5 text-xs font-bold transition-colors ${
                                    filters.trip_type === value
                                        ? 'text-red-800'
                                        : 'text-slate-500 hover:text-red-800'
                                }`}
                                role="radio"
                                aria-checked={filters.trip_type === value}
                                onClick={() =>
                                    selectTripType(
                                        value as SearchFilters['trip_type'],
                                    )
                                }
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    <div className="mb-1.5 flex items-center justify-between">
                        <button
                            type="button"
                            className="size-8 rounded-md border border-slate-200 hover:bg-slate-50"
                            onClick={() =>
                                setCalendarCursor((current) =>
                                    addMonths(current, -1),
                                )
                            }
                        >
                            ‹
                        </button>
                        <div className="text-sm font-bold text-slate-800">
                            {monthNames[calendarCursor.getMonth()]}{' '}
                            {calendarCursor.getFullYear()}
                        </div>
                        <button
                            type="button"
                            className="size-8 rounded-md border border-slate-200 hover:bg-slate-50"
                            onClick={() =>
                                setCalendarCursor((current) =>
                                    addMonths(current, 1),
                                )
                            }
                        >
                            ›
                        </button>
                    </div>

                    <div
                        className="grid grid-cols-7 gap-0.5 text-center text-sm"
                        onMouseLeave={() => setHoverDate(null)}
                    >
                        {weekdayNames.map((weekday) => (
                            <div
                                key={weekday}
                                className="py-1 text-[11px] font-bold text-slate-400"
                            >
                                {weekday}
                            </div>
                        ))}
                        {calendarDays.map((day, index) =>
                            day ? (
                                <button
                                    type="button"
                                    key={isoDate(day)}
                                    className={calendarDayClass(
                                        day,
                                        departDate,
                                        returnDate,
                                        previewEnd,
                                        isRoundTrip,
                                        pickingReturn,
                                        hoverDate,
                                    )}
                                    onClick={() => selectDate(day)}
                                    onMouseEnter={() => {
                                        if (isRoundTrip && departDate) {
                                            setHoverDate(day);
                                        }
                                    }}
                                    onFocus={() => {
                                        if (isRoundTrip && departDate) {
                                            setHoverDate(day);
                                        }
                                    }}
                                >
                                    {day.getDate()}
                                </button>
                            ) : (
                                <div key={`blank-${index}`} />
                            ),
                        )}
                    </div>

                    <div className="mt-2 text-[11px] font-medium text-slate-400">
                        {isRoundTrip
                            ? pickingReturn
                                ? 'Choose return date'
                                : 'Choose departure date'
                            : 'Choose departure date'}
                    </div>
                </div>
            )}

            {(errors.depart_date || errors.return_date) && (
                <span className="mt-1 block text-xs text-red-700">
                    {errors.depart_date || errors.return_date}
                </span>
            )}
        </div>
    );
}

function PassengerField({
    filters,
    canUseFullSearch,
    errors,
    compact,
    isOpen,
    onToggle,
    onChange,
}: {
    filters: SearchFilters;
    canUseFullSearch: boolean;
    errors: Record<string, string>;
    compact: boolean;
    isOpen: boolean;
    onToggle: () => void;
    onChange: <K extends keyof SearchFilters>(
        key: K,
        value: SearchFilters[K],
    ) => void;
}) {
    const total = totalPassengers(
        filters.adults,
        filters.children,
        filters.babies,
    );

    function setPassenger(
        key: 'adults' | 'children' | 'babies',
        value: number,
    ) {
        const siblings = {
            adults: filters.children + filters.babies,
            children: filters.adults + filters.babies,
            babies: filters.adults + filters.children,
        };
        const minimum = key === 'adults' ? 1 : 0;

        onChange(
            key,
            clampPassengerCount(value, minimum, passengerLimit - siblings[key]),
        );
    }

    return (
        <div className={`relative ${isOpen ? 'z-[120]' : 'z-20'}`}>
            <button
                type="button"
                className={`flex w-full flex-col justify-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-3 text-left transition-colors hover:border-red-800 hover:bg-white ${
                    compact ? 'h-[50px]' : 'h-[58px]'
                }`}
                aria-expanded={isOpen}
                onClick={onToggle}
            >
                <span className="text-[10px] leading-none font-semibold text-slate-500 uppercase">
                    Passengers
                </span>
                <span className="inline-flex items-center gap-2 text-sm font-semibold text-slate-800">
                    <Users className="size-4 text-slate-400" />
                    {total} {total === 1 ? 'Passenger' : 'Passengers'}
                </span>
            </button>

            {isOpen && (
                <div className="absolute top-full right-0 z-[140] mt-1 w-[min(320px,calc(100vw-48px))] space-y-3 rounded-md border border-slate-200 bg-white p-4">
                    <PassengerRow
                        label="Adult"
                        helper="12+"
                        value={filters.adults}
                        min={1}
                        max={passengerLimit - filters.children - filters.babies}
                        onChange={(value) => setPassenger('adults', value)}
                    />
                    <PassengerRow
                        label="Child"
                        helper="2-11"
                        value={filters.children}
                        min={0}
                        max={passengerLimit - filters.adults - filters.babies}
                        onChange={(value) => setPassenger('children', value)}
                    />
                    <PassengerRow
                        label="Baby"
                        helper="0-2"
                        value={filters.babies}
                        min={0}
                        max={passengerLimit - filters.adults - filters.children}
                        onChange={(value) => setPassenger('babies', value)}
                    />
                    {canUseFullSearch && (
                        <div className="border-t border-slate-100 pt-3">
                            <div className="mb-1 text-[10px] font-semibold text-slate-500 uppercase">
                                Fare view
                            </div>
                            <div className="grid grid-cols-2 gap-1 rounded-md border border-slate-200 bg-slate-50 p-1">
                                {[
                                    ['basic', 'Best'],
                                    ['full', 'All fares'],
                                ].map(([value, label]) => (
                                    <button
                                        type="button"
                                        key={value}
                                        className={`rounded-md px-3 py-1.5 text-xs font-bold ${
                                            filters.search_mode === value
                                                ? 'bg-slate-950 text-white'
                                                : 'bg-white text-slate-600'
                                        }`}
                                        onClick={() =>
                                            onChange(
                                                'search_mode',
                                                value as SearchFilters['search_mode'],
                                            )
                                        }
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}

            {(errors.adults || errors.children || errors.babies) && (
                <span className="mt-1 block text-xs text-red-700">
                    {errors.adults || errors.children || errors.babies}
                </span>
            )}
        </div>
    );
}

function PassengerRow({
    label,
    helper,
    value,
    min,
    max,
    onChange,
}: {
    label: string;
    helper: string;
    value: number;
    min: number;
    max: number;
    onChange: (value: number) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-3">
            <div>
                <div className="text-sm font-bold text-slate-900 uppercase">
                    {label}
                </div>
                <div className="text-xs text-slate-400">{helper}</div>
            </div>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    className="grid size-8 place-items-center rounded-md border border-slate-200 hover:bg-slate-50 disabled:text-slate-300"
                    disabled={value <= min}
                    onClick={() => onChange(value - 1)}
                >
                    <Minus className="size-4" />
                </button>
                <span className="w-7 text-center font-bold">{value}</span>
                <button
                    type="button"
                    className="grid size-8 place-items-center rounded-md border border-slate-200 hover:bg-slate-50 disabled:text-slate-300"
                    disabled={value >= max}
                    onClick={() => onChange(value + 1)}
                >
                    <Plus className="size-4" />
                </button>
            </div>
        </div>
    );
}

const monthNames = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

const weekdayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function parseIsoDate(value: string): Date | null {
    if (!value) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);

    if (!year || !month || !day) {
        return null;
    }

    return new Date(year, month - 1, day);
}

function isoDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function startOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

function addMonths(date: Date, months: number): Date {
    return new Date(date.getFullYear(), date.getMonth() + months, 1);
}

function daysForMonth(month: Date): (Date | null)[] {
    const first = startOfMonth(month);
    const startOffset = (first.getDay() + 6) % 7;
    const daysInMonth = new Date(
        first.getFullYear(),
        first.getMonth() + 1,
        0,
    ).getDate();

    return [
        ...Array.from({ length: startOffset }, () => null),
        ...Array.from({ length: daysInMonth }, (_, index) => {
            return new Date(first.getFullYear(), first.getMonth(), index + 1);
        }),
    ];
}

function isSameDay(firstDate: Date | null, secondDate: Date | null): boolean {
    return Boolean(
        firstDate &&
        secondDate &&
        firstDate.getFullYear() === secondDate.getFullYear() &&
        firstDate.getMonth() === secondDate.getMonth() &&
        firstDate.getDate() === secondDate.getDate(),
    );
}

function compareDay(firstDate: Date, secondDate: Date): number {
    return (
        new Date(
            firstDate.getFullYear(),
            firstDate.getMonth(),
            firstDate.getDate(),
        ).getTime() -
        new Date(
            secondDate.getFullYear(),
            secondDate.getMonth(),
            secondDate.getDate(),
        ).getTime()
    );
}

function isBetweenDays(date: Date, firstDate: Date, secondDate: Date): boolean {
    const start =
        compareDay(firstDate, secondDate) <= 0 ? firstDate : secondDate;
    const end = start === firstDate ? secondDate : firstDate;

    return compareDay(date, start) >= 0 && compareDay(date, end) <= 0;
}

function calendarDayClass(
    date: Date,
    departDate: Date | null,
    returnDate: Date | null,
    previewEnd: Date | null,
    isRoundTrip: boolean,
    pickingReturn: boolean,
    hoverDate: Date | null,
): string {
    const isSelected =
        isSameDay(date, departDate) || isSameDay(date, returnDate);
    const isInRange = Boolean(
        isRoundTrip &&
        departDate &&
        previewEnd &&
        isBetweenDays(date, departDate, previewEnd),
    );
    const isPreview = Boolean(
        isRoundTrip && pickingReturn && hoverDate && isSameDay(date, hoverDate),
    );

    return [
        'min-h-7 rounded-md border text-sm font-bold transition-colors',
        isSelected
            ? 'border-red-800 bg-red-800 text-white'
            : 'border-transparent text-slate-700 hover:border-red-100 hover:bg-red-50',
        isInRange && !isSelected ? 'border-red-100 bg-red-50 text-red-950' : '',
        isPreview && !isSelected
            ? 'border-red-300 bg-red-100 text-red-950'
            : '',
    ].join(' ');
}

export function routeCodesToIds(
    airports: Airport[],
    originCode: string,
    destinationCode: string,
): [number | null, number | null] {
    return [
        findAirportByCode(airports, originCode)?.id ?? null,
        findAirportByCode(airports, destinationCode)?.id ?? null,
    ];
}
