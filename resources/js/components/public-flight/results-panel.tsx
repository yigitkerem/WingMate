import {
    ArrowRight,
    Briefcase,
    BriefcaseBusiness,
    CheckCircle2,
    Clock,
    Info,
    Luggage,
    RotateCcw,
    Star,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { fareRuleLabel } from '@/lib/flight-search';
import type { Fare, FlightResult, SearchResults } from '@/types/flight-search';

export type RoundTripStep = 'outbound' | 'return';

export type RoundTripFareSelection = {
    flight: FlightResult;
    fare: Fare;
};

type OpenFareCategory = {
    flightId: number;
    cabin: 'economy' | 'business';
} | null;

type ResultsPanelProps = {
    results: SearchResults;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
    className?: string;
    roundTripStep?: RoundTripStep;
    roundTripSelection?: {
        outbound?: RoundTripFareSelection;
        return?: RoundTripFareSelection;
    };
    onRoundTripStepChange?: (step: RoundTripStep) => void;
};

export function ResultsPanel({
    results,
    onPurchase,
    className = '',
    roundTripStep = 'outbound',
    roundTripSelection = {},
    onRoundTripStepChange,
}: ResultsPanelProps) {
    const [openFareCategory, setOpenFareCategory] =
        useState<OpenFareCategory>(null);

    if (!results) {
        return null;
    }

    const isRoundTrip = results.return.length > 0;
    const activeFlights =
        isRoundTrip && roundTripStep === 'return'
            ? results.return
            : results.outbound;

    function toggleFareCategory(
        flightId: number,
        cabin: 'economy' | 'business',
    ) {
        setOpenFareCategory((current) =>
            current?.flightId === flightId && current.cabin === cabin
                ? null
                : { flightId, cabin },
        );
    }

    return (
        <section id="results" className={`mt-8 w-full ${className}`}>
            {isRoundTrip ? (
                <div className="grid gap-4">
                    <RoundTripSteps
                        activeStep={roundTripStep}
                        selection={roundTripSelection}
                        onStepChange={onRoundTripStepChange}
                    />
                    <FlightLeg
                        key={roundTripStep}
                        flights={activeFlights}
                        emptyMessage={`No sellable ${roundTripStep} flights found for this route and date.`}
                        openFareCategory={openFareCategory}
                        onToggleFareCategory={toggleFareCategory}
                        onPurchase={onPurchase}
                    />
                </div>
            ) : (
                <div className="grid gap-4">
                    <FlightLeg
                        flights={results.outbound}
                        emptyMessage="No sellable flights found for this route and date."
                        openFareCategory={openFareCategory}
                        onToggleFareCategory={toggleFareCategory}
                        onPurchase={onPurchase}
                    />
                    {results.return.length > 0 && (
                        <FlightLeg
                            flights={results.return}
                            emptyMessage="No sellable return flights found for this route and date."
                            openFareCategory={openFareCategory}
                            onToggleFareCategory={toggleFareCategory}
                            onPurchase={onPurchase}
                        />
                    )}
                </div>
            )}
        </section>
    );
}

function RoundTripSteps({
    activeStep,
    selection,
    onStepChange,
}: {
    activeStep: RoundTripStep;
    selection: {
        outbound?: RoundTripFareSelection;
        return?: RoundTripFareSelection;
    };
    onStepChange?: (step: RoundTripStep) => void;
}) {
    return (
        <div className="grid gap-3 rounded-md border border-slate-200 bg-white p-3 shadow-sm transition-all duration-500 md:grid-cols-2 starting:opacity-0 motion-safe:starting:translate-y-2">
            <RoundTripStepButton
                step="outbound"
                title="Outbound"
                isActive={activeStep === 'outbound'}
                selection={selection.outbound}
                onStepChange={onStepChange}
            />
            <RoundTripStepButton
                step="return"
                title="Return"
                isActive={activeStep === 'return'}
                isDisabled={!selection.outbound}
                selection={selection.return}
                onStepChange={onStepChange}
            />
        </div>
    );
}

function RoundTripStepButton({
    step,
    title,
    isActive,
    isDisabled = false,
    selection,
    onStepChange,
}: {
    step: RoundTripStep;
    title: string;
    isActive: boolean;
    isDisabled?: boolean;
    selection?: RoundTripFareSelection;
    onStepChange?: (step: RoundTripStep) => void;
}) {
    return (
        <button
            type="button"
            disabled={isDisabled}
            className={`group flex min-h-24 w-full cursor-pointer items-center justify-between gap-4 rounded-md border p-4 text-left transition-all duration-200 ${
                isActive
                    ? 'border-slate-950 bg-slate-950 text-white shadow-sm'
                    : 'border-slate-200 bg-white text-slate-950 hover:border-slate-300 hover:bg-slate-50'
            } disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400`}
            onClick={() => onStepChange?.(step)}
        >
            <span className="min-w-0">
                <span
                    className={`block text-[11px] font-medium tracking-wide uppercase ${
                        isActive ? 'text-slate-300' : 'text-slate-500'
                    }`}
                >
                    Step {step === 'outbound' ? '1' : '2'}
                </span>
                <span className="mt-1 block text-lg leading-6 font-semibold">
                    {title}
                </span>
                <span
                    className={`mt-1 block text-sm leading-5 font-medium ${
                        isActive ? 'text-slate-200' : 'text-slate-600'
                    }`}
                >
                    {selection
                        ? selectedFareSummary(selection)
                        : isDisabled
                          ? 'Choose outbound first'
                          : 'Select flight and fare'}
                </span>
            </span>
            <span
                className={`grid size-9 shrink-0 place-items-center rounded-md border transition-colors ${
                    isActive
                        ? 'border-white/20 bg-white/10 text-white'
                        : selection
                          ? 'border-slate-300 bg-slate-100 text-slate-700'
                          : 'border-slate-200 bg-white text-slate-400 group-hover:border-slate-300'
                }`}
            >
                {selection ? (
                    <CheckCircle2 className="size-4" />
                ) : (
                    <ArrowRight className="size-4" />
                )}
            </span>
        </button>
    );
}

function selectedFareSummary(selection: RoundTripFareSelection): string {
    const { flight, fare } = selection;
    const price =
        typeof fare.base_price_usd === 'number'
            ? ` · ${formatFarePrice(fare)}`
            : '';

    return `${flight.flight_number} · ${flight.origin.code}-${flight.destination.code}${price}`;
}

function FlightLeg({
    flights,
    emptyMessage,
    openFareCategory,
    onToggleFareCategory,
    onPurchase,
}: {
    flights: FlightResult[];
    emptyMessage: string;
    openFareCategory: OpenFareCategory;
    onToggleFareCategory: (
        flightId: number,
        cabin: 'economy' | 'business',
    ) => void;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
}) {
    return (
        <div className="grid gap-4">
            {flights.length === 0 ? (
                <div className="rounded-md border border-slate-200 bg-white p-8 text-center text-sm font-medium text-slate-500 opacity-100 transition-all duration-500 starting:opacity-0 motion-safe:starting:translate-y-2">
                    {emptyMessage}
                </div>
            ) : (
                flights.map((flight) => (
                    <FlightCard
                        key={flight.id}
                        flight={flight}
                        openCabin={
                            openFareCategory?.flightId === flight.id
                                ? openFareCategory.cabin
                                : null
                        }
                        onToggleFareCategory={onToggleFareCategory}
                        onPurchase={onPurchase}
                    />
                ))
            )}
        </div>
    );
}

function FlightCard({
    flight,
    openCabin,
    onToggleFareCategory,
    onPurchase,
}: {
    flight: FlightResult;
    openCabin: 'economy' | 'business' | null;
    onToggleFareCategory: (
        flightId: number,
        cabin: 'economy' | 'business',
    ) => void;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
}) {
    const fares = faresForFlight(flight);
    const economyFares = cabinFares(fares, 'economy');
    const businessFares = cabinFares(fares, 'business');
    const selectedFares =
        openCabin === 'business' ? businessFares : economyFares;

    return (
        <article className="overflow-hidden rounded-md border border-slate-200 bg-white opacity-100 shadow-sm transition-all duration-500 hover:border-slate-300 hover:shadow-md starting:opacity-0 motion-safe:starting:translate-y-3">
            <div className="grid divide-y divide-slate-200 xl:grid-cols-[minmax(520px,1fr)_minmax(260px,320px)_minmax(260px,320px)] xl:divide-x xl:divide-y-0">
                <FlightTimeline flight={flight} />
                <FareAction
                    cabin="economy"
                    fares={economyFares}
                    isOpen={openCabin === 'economy'}
                    onOpen={() => onToggleFareCategory(flight.id, 'economy')}
                />
                <FareAction
                    cabin="business"
                    fares={businessFares}
                    isOpen={openCabin === 'business'}
                    onOpen={() => onToggleFareCategory(flight.id, 'business')}
                />
            </div>
            <PackageTable
                cabin={openCabin ?? 'economy'}
                fares={selectedFares}
                flight={flight}
                isOpen={openCabin !== null && selectedFares.length > 0}
                onPurchase={onPurchase}
            />
        </article>
    );
}

function FlightTimeline({ flight }: { flight: FlightResult }) {
    return (
        <div className="bg-white p-4 transition-colors duration-300">
            <div className="grid items-center gap-4 sm:grid-cols-[1fr_auto_1fr]">
                <AirportTime
                    time={flight.hour}
                    airport={flight.origin.code}
                    name={flight.origin.name}
                />
                <div className="min-w-[150px]">
                    <div className="mb-2 flex items-center justify-center gap-2 text-[11px] font-medium text-slate-500 uppercase">
                        <Clock className="size-3.5" />
                        {flight.duration}
                    </div>
                    <div className="relative flex items-center">
                        <span className="size-2 rounded-full border border-slate-500 bg-white" />
                        <div className="h-px flex-1 bg-slate-300" />
                        <img
                            src="/assets/thy-emblem.svg"
                            className="mx-2 size-7 shrink-0 object-contain"
                            alt="Turkish Airlines"
                        />
                        <div className="h-px flex-1 bg-slate-300" />
                        <span className="size-2 rounded-full border border-slate-500 bg-white" />
                    </div>
                </div>
                <AirportTime
                    time={flight.arrival_time}
                    airport={flight.destination.code}
                    name={flight.destination.name}
                    align="right"
                />
            </div>
            <div className="mt-4 flex items-center justify-between gap-4 border-t border-slate-100 pt-3 text-[12px] font-medium text-slate-600">
                <span className="font-medium text-slate-900">
                    {flight.flight_number}
                </span>
                <span className="text-right">{flight.plane_model}</span>
            </div>
        </div>
    );
}

function AirportTime({
    time,
    airport,
    name,
    align = 'left',
}: {
    time: string;
    airport: string;
    name: string;
    align?: 'left' | 'right';
}) {
    return (
        <div
            className={`min-w-0 text-center ${align === 'right' ? 'sm:text-right' : 'sm:text-left'}`}
        >
            <div className="text-2xl leading-none font-semibold text-slate-950 md:text-[28px]">
                {time}
            </div>
            <div className="mt-1 text-xs font-medium text-slate-900">
                {airport}
            </div>
            <div className="mt-0.5 text-[11px] leading-4 font-medium text-slate-500">
                {name}
            </div>
        </div>
    );
}

function FareAction({
    cabin,
    fares,
    isOpen,
    onOpen,
}: {
    cabin: 'economy' | 'business';
    fares: Fare[];
    isOpen: boolean;
    onOpen: () => void;
}) {
    const isBusiness = cabin === 'business';
    const label = isBusiness ? 'Business' : 'Economy';
    const startingFare = preferredFare(fares);
    const hasFares = fares.length > 0;
    const price = startingFare ? `$${startingFare.base_price_usd}` : '-';

    return (
        <button
            type="button"
            disabled={!hasFares}
            aria-expanded={isOpen}
            className={`flex h-full min-h-0 w-full cursor-pointer flex-col justify-center bg-slate-50/70 p-4 text-left transition-all duration-200 xl:bg-white ${
                isOpen
                    ? 'bg-slate-950 text-white xl:bg-slate-950'
                    : 'hover:bg-slate-50'
            } disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400`}
            onClick={onOpen}
        >
            <div className="flex items-start justify-between gap-3">
                <span
                    className={`text-xs font-semibold tracking-wide uppercase ${
                        isOpen ? 'text-slate-300' : 'text-slate-500'
                    }`}
                >
                    {label} from {price}
                </span>
                {isBusiness && (
                    <BriefcaseBusiness
                        className={`size-4 ${isOpen ? 'text-slate-300' : 'text-slate-400'}`}
                    />
                )}
            </div>
            <div
                className={`mt-3 text-3xl leading-none font-semibold ${
                    isOpen ? 'text-white' : 'text-slate-950'
                }`}
            >
                {price}
            </div>
            <div
                className={`mt-3 inline-flex items-center gap-2 text-xs font-semibold ${
                    isOpen ? 'text-white' : 'text-red-800'
                }`}
            >
                {hasFares ? (
                    <>
                        View packages
                        <ArrowRight className="size-4" />
                    </>
                ) : (
                    <span>No {label.toLowerCase()} fare returned</span>
                )}
            </div>
        </button>
    );
}

function faresForFlight(flight: FlightResult): Fare[] {
    return Array.isArray(flight.fares)
        ? flight.fares
        : Object.values(flight.fares);
}

function cabinFares(fares: Fare[], cabin: 'economy' | 'business'): Fare[] {
    return fares
        .filter((fare) =>
            cabin === 'business' ? isBusinessFare(fare) : !isBusinessFare(fare),
        )
        .sort(
            (firstFare, secondFare) =>
                (firstFare.base_price_usd ?? Number.MAX_SAFE_INTEGER) -
                (secondFare.base_price_usd ?? Number.MAX_SAFE_INTEGER),
        );
}

function preferredFare(fares: Fare[]): Fare | null {
    const availableFares = fares.filter((fare) => fare.available);
    const candidates = availableFares.length > 0 ? availableFares : fares;

    return (
        [...candidates].sort(
            (firstFare, secondFare) =>
                (firstFare.base_price_usd ?? Number.MAX_SAFE_INTEGER) -
                (secondFare.base_price_usd ?? Number.MAX_SAFE_INTEGER),
        )[0] ?? null
    );
}

function isBusinessFare(fare: Fare): boolean {
    const fareText = [
        fare.product,
        fare.package_code,
        fare.class,
        fare.fare_basis_code,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return fareText.includes('business');
}

function PackageTable({
    cabin,
    fares,
    flight,
    isOpen,
    onPurchase,
}: {
    cabin: 'economy' | 'business';
    fares: Fare[];
    flight: FlightResult;
    isOpen: boolean;
    onPurchase: (flight: FlightResult, fare: Fare) => void;
}) {
    const label = cabin === 'business' ? 'Business' : 'Economy';
    const comparisonRows: ComparisonRowDefinition[] = [
        {
            label: 'Cabin bag',
            helper: 'Carry-on allowance',
            icon: <Briefcase className="size-4" />,
            render: (fare) => `${fare.cabin_baggage_kg ?? 0} kg`,
        },
        {
            label: 'Checked bag',
            helper: 'Checked baggage allowance included with the fare.',
            icon: <Luggage className="size-4" />,
            render: (fare) =>
                (fare.checked_baggage_kg ?? 0) > 0
                    ? `${fare.checked_baggage_kg} kg`
                    : 'Not included',
        },
        {
            label: 'Seat selection',
            helper: 'Seat assignment policy',
            icon: <CheckCircle2 className="size-4" />,
            render: (fare) =>
                fare.seat_selection_free ? 'Complimentary' : 'Paid',
        },
        {
            label: 'Change',
            helper: 'Change allowance',
            icon: <RotateCcw className="size-4" />,
            render: (fare) =>
                fareRuleLabel(
                    'change',
                    fare.change_fee_usd,
                    fare.latest_change_hours,
                ),
        },
        {
            label: 'Refund',
            helper: 'Refund allowance',
            icon: <Star className="size-4" />,
            render: (fare) =>
                fareRuleLabel(
                    'refund',
                    fare.refund_fee_usd,
                    fare.latest_refund_hours,
                ),
        },
    ];

    return (
        <div
            className={`grid transition-[grid-template-rows,opacity] duration-300 ease-out ${
                isOpen
                    ? 'grid-rows-[1fr] opacity-100'
                    : 'grid-rows-[0fr] opacity-0'
            }`}
            aria-hidden={!isOpen}
        >
            <div className="min-h-0 overflow-hidden border-t border-slate-200 bg-white">
                <div className="overflow-x-auto bg-white">
                    <table
                        className="w-full table-fixed text-left text-sm"
                        style={{
                            minWidth: `${220 + fares.length * 230}px`,
                        }}
                    >
                        <colgroup>
                            <col className="w-[220px]" />
                            {fares.map((fare, index) => (
                                <col
                                    key={`col-${fare.id ?? fare.uuid ?? fare.class ?? index}`}
                                    className="w-[230px]"
                                />
                            ))}
                        </colgroup>
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50">
                                <th className="px-4 py-4 align-bottom">
                                    <span className="text-xs font-medium tracking-wide text-slate-500 uppercase">
                                        Compare
                                    </span>
                                </th>
                                {fares.map((fare, index) => {
                                    const lowInventory =
                                        lowInventoryLabel(fare);

                                    return (
                                        <th
                                            key={`package-${fare.id ?? fare.uuid ?? fare.class ?? index}`}
                                            className="border-l border-slate-200 px-4 py-4 align-bottom"
                                        >
                                            {lowInventory && (
                                                <span className="mb-3 inline-flex rounded-md border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-semibold text-red-800">
                                                    {lowInventory}
                                                </span>
                                            )}
                                            <span className="block text-base font-semibold text-slate-950">
                                                {fare.class ??
                                                    `${label} package`}
                                            </span>
                                            <span className="mt-1 block text-xs font-semibold text-slate-500">
                                                {[
                                                    fare.product,
                                                    fare.class_letters,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' / ') ||
                                                    'Fare package'}
                                            </span>
                                            <span className="mt-3 block text-2xl leading-none font-semibold text-slate-950">
                                                {formatFarePrice(fare)}
                                            </span>
                                        </th>
                                    );
                                })}
                            </tr>
                        </thead>
                        <tbody>
                            {comparisonRows.map((row) => (
                                <PackageComparisonRow
                                    key={row.label}
                                    row={row}
                                    fares={fares}
                                />
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t border-slate-200 bg-white">
                                <td className="px-4 py-4 text-xs font-medium tracking-wide text-slate-500 uppercase">
                                    Choose package
                                </td>
                                {fares.map((fare, index) => (
                                    <td
                                        key={`select-${fare.id ?? fare.uuid ?? fare.class ?? index}`}
                                        className="border-l border-slate-200 px-4 py-4"
                                    >
                                        <button
                                            type="button"
                                            disabled={!fare.available}
                                            className="inline-flex h-10 w-full items-center justify-center rounded-md bg-red-800 px-3 text-sm font-semibold text-white transition-all duration-200 hover:bg-red-950 disabled:bg-slate-100 disabled:text-slate-400"
                                            onClick={() =>
                                                onPurchase(flight, fare)
                                            }
                                        >
                                            Select
                                        </button>
                                    </td>
                                ))}
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    );
}

type ComparisonRowDefinition = {
    label: string;
    helper: string;
    icon: ReactNode;
    render: (fare: Fare) => string;
};

function PackageComparisonRow({
    row,
    fares,
}: {
    row: ComparisonRowDefinition;
    fares: Fare[];
}) {
    return (
        <tr className="border-b border-slate-100 last:border-b-0">
            <td className="bg-slate-50 px-4 py-3 align-middle">
                <div className="flex items-center gap-3">
                    <span className="grid size-7 shrink-0 place-items-center rounded-md border border-slate-200 bg-white text-slate-600">
                        {row.icon}
                    </span>
                    <span className="flex min-w-0 items-center gap-1.5 leading-none">
                        <span className="text-sm leading-none font-semibold text-slate-950">
                            {row.label}
                        </span>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    className="inline-flex size-4 shrink-0 items-center justify-center rounded-full text-slate-400 transition-colors hover:bg-slate-200 hover:text-slate-800"
                                    aria-label={`${row.label} details`}
                                >
                                    <Info className="size-3" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent>{row.helper}</TooltipContent>
                        </Tooltip>
                    </span>
                </div>
            </td>
            {fares.map((fare, index) => (
                <td
                    key={`${row.label}-${fare.id ?? fare.uuid ?? fare.class ?? index}`}
                    className="border-l border-slate-200 px-4 py-3 align-middle text-sm font-medium text-slate-800 transition-colors duration-200 hover:bg-slate-50"
                >
                    {row.render(fare)}
                </td>
            ))}
        </tr>
    );
}

function formatFarePrice(fare: Fare): string {
    return typeof fare.base_price_usd === 'number'
        ? `$${fare.base_price_usd}`
        : '-';
}

function lowInventoryLabel(fare: Fare): string | null {
    if (
        typeof fare.count_available !== 'number' ||
        fare.count_available < 1 ||
        fare.count_available > 5
    ) {
        return null;
    }

    return `${fare.count_available} left at this price`;
}
