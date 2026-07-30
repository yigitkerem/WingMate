import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { LanguageSwitcher } from '@/components/language-switcher';
import { PurchaseModal } from '@/components/public-flight/purchase-modal';
import {
    ResultsPanel,
    RoundTripSteps,
} from '@/components/public-flight/results-panel';
import type {
    RoundTripFareSelection,
    RoundTripStep,
} from '@/components/public-flight/results-panel';
import {
    routeCodesToIds,
    SearchCard,
} from '@/components/public-flight/search-card';
import {
    formatShortDate,
    initializeSearchFilters,
    publishWingoLaunch,
    publishWingoSearchPrefill,
    routeHeroImage,
    storeLastDestinationAirport,
    totalPassengers,
} from '@/lib/flight-search';
import { useTranslation } from '@/lib/i18n';
import { dashboard, login, register } from '@/routes';
import type {
    Airport,
    CustomerSummary,
    Fare,
    FlightResult,
    PurchaseTarget,
    SearchFilters,
    SearchFormSubmit,
    SearchResults,
} from '@/types/flight-search';

type Props = {
    airports: Airport[];
    filters: SearchFilters;
    results: SearchResults;
    canUseFullSearch: boolean;
    customer: CustomerSummary;
    errors?: Record<string, string>;
};

export default function FlightResults({
    airports,
    filters,
    results,
    canUseFullSearch,
    customer,
    errors = {},
}: Props) {
    const { locale, t } = useTranslation();
    const [form, setForm] = useState<SearchFilters>(() =>
        initializeSearchFilters(filters, airports),
    );
    const [purchaseTarget, setPurchaseTarget] = useState<PurchaseTarget | null>(
        null,
    );
    const [roundTripSelection, setRoundTripSelection] = useState<{
        outbound?: RoundTripFareSelection;
        return?: RoundTripFareSelection;
    }>({});
    const [roundTripStep, setRoundTripStep] =
        useState<RoundTripStep>('outbound');
    const destination = results?.outbound[0]?.destination;
    const destinationCode = destination?.code;
    const destinationName = cityName(destination?.name, destinationCode);
    const tripContext = tripSummary(form, locale, t);

    useEffect(() => {
        storeLastDestinationAirport(form.destination_airport_id);
    }, [form.destination_airport_id]);

    useEffect(() => {
        publishWingoSearchPrefill(form, airports);
    }, [airports, form]);

    function update<K extends keyof SearchFilters>(
        key: K,
        value: SearchFilters[K],
    ) {
        setForm((current) => ({ ...current, [key]: value }));
    }

    function submit(event: SearchFormSubmit) {
        event.preventDefault();
        setRoundTripSelection({});
        setRoundTripStep('outbound');

        router.get(
            '/search',
            {
                ...form,
                search_mode: canUseFullSearch ? form.search_mode : 'basic',
                return_date:
                    form.trip_type === 'round_trip'
                        ? form.return_date
                        : undefined,
            },
            {
                preserveScroll: true,
            },
        );
    }

    function quickSearch(
        originCode: string,
        destinationCode: string,
        date: string,
    ) {
        const [originId, destinationId] = routeCodesToIds(
            airports,
            originCode,
            destinationCode,
        );

        setRoundTripSelection({});
        setRoundTripStep('outbound');

        setForm((current) => ({
            ...current,
            origin_airport_id: originId,
            destination_airport_id: destinationId,
            depart_date: date,
            trip_type: 'one_way',
            search_mode: canUseFullSearch ? current.search_mode : 'basic',
        }));
    }

    function startPurchase(flight: FlightResult, fare: Fare) {
        if (form.trip_type !== 'round_trip') {
            setPurchaseTarget({
                flight,
                fare,
                offerIds: fare.id ? [fare.id] : [],
                totalPrice: fare.base_price_usd ?? 0,
            });

            return;
        }

        if (roundTripStep === 'outbound') {
            setRoundTripSelection({
                outbound: { flight, fare },
            });
            setRoundTripStep('return');

            return;
        }

        const outboundSelection = roundTripSelection.outbound;

        if (!outboundSelection) {
            setRoundTripStep('outbound');

            return;
        }

        const nextSelection = {
            outbound: outboundSelection,
            return: { flight, fare },
        };

        setRoundTripSelection(nextSelection);

        const outboundFare = outboundSelection.fare;
        const returnFare = nextSelection.return.fare;

        setPurchaseTarget({
            flight: outboundSelection.flight,
            fare: outboundFare,
            offerIds: [outboundFare.id, returnFare.id].filter(
                (id): id is number => typeof id === 'number',
            ),
            totalPrice:
                (outboundFare.base_price_usd ?? 0) +
                (returnFare.base_price_usd ?? 0),
        });
    }

    function startCustomBundle(
        flight: FlightResult,
        cabin: 'economy' | 'business',
    ) {
        const prefill = {
            origin: flight.origin.code,
            destination: flight.destination.code,
            date: flight.date,
            tripType: form.trip_type,
            returnDate:
                form.trip_type === 'round_trip'
                    ? form.return_date || undefined
                    : undefined,
            adults: form.adults,
            children: form.children,
            babies: form.babies,
        };
        publishWingoLaunch({
            prefill,
            trigger: 'custom_bundle',
            triggerContext: {
                flight_number: flight.flight_number,
                cabin,
                origin: flight.origin.code,
                destination: flight.destination.code,
                date: flight.date,
                hour: flight.hour,
                adults: form.adults,
                children: form.children,
                babies: form.babies,
            },
        });
    }

    function changeRoundTripStep(step: RoundTripStep) {
        if (step === 'return' && !roundTripSelection.outbound) {
            return;
        }

        setRoundTripStep(step);
    }

    return (
        <>
            <Head title={`${t('results.results')} · Dynamic Pricer`} />
            <main className="min-h-screen bg-[#f4f6f8] font-sans text-slate-950">
                <ResultsHero
                    customer={customer}
                    destinationCode={destinationCode}
                    destinationName={destinationName}
                    locale={locale}
                    roundTripSelection={roundTripSelection}
                    roundTripStep={roundTripStep}
                    showRoundTripSteps={Boolean(results?.return.length)}
                    tripContext={tripContext}
                    onRoundTripStepChange={changeRoundTripStep}
                >
                    <SearchCard
                        airports={airports}
                        filters={form}
                        canUseFullSearch={canUseFullSearch}
                        errors={errors}
                        hasResults={results !== null}
                        variant="compact"
                        onSubmit={submit}
                        onChange={update}
                        onQuickSearch={quickSearch}
                    />
                </ResultsHero>

                <div className="mx-auto max-w-6xl px-4 py-8 opacity-100 transition-all duration-500 sm:px-6 starting:opacity-0 motion-safe:starting:translate-y-3">
                    <ResultsPanel
                        results={results}
                        onPurchase={startPurchase}
                        onCustomBundle={startCustomBundle}
                        roundTripStep={roundTripStep}
                        className="px-0 pb-12"
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

function ResultsHero({
    children,
    customer,
    destinationCode,
    destinationName,
    locale,
    roundTripSelection,
    roundTripStep,
    showRoundTripSteps,
    tripContext,
    onRoundTripStepChange,
}: {
    children: ReactNode;
    customer: CustomerSummary;
    destinationCode?: string;
    destinationName: string;
    locale: 'en' | 'tr';
    roundTripSelection: {
        outbound?: RoundTripFareSelection;
        return?: RoundTripFareSelection;
    };
    roundTripStep: RoundTripStep;
    showRoundTripSteps: boolean;
    tripContext: string;
    onRoundTripStepChange: (step: RoundTripStep) => void;
}) {
    const { t } = useTranslation();

    return (
        <section className="border-b border-slate-200 bg-white">
            <ResultsMenuBar customer={customer} />
            <div className="mx-auto grid max-w-6xl gap-5 px-4 py-6 sm:px-6 lg:py-7">
                {showRoundTripSteps && (
                    <RoundTripSteps
                        activeStep={roundTripStep}
                        selection={roundTripSelection}
                        onStepChange={onRoundTripStepChange}
                    />
                )}
                <div className="opacity-100 transition-all duration-500 starting:opacity-0 motion-safe:starting:translate-y-2">
                    <DestinationImageCard
                        destinationCode={destinationCode}
                        destinationName={destinationName}
                        title={resultsTitle(destinationName, locale, t)}
                        tripContext={tripContext}
                    />
                </div>
                <div className="min-w-0 opacity-100 transition-all delay-100 duration-500 starting:opacity-0 motion-safe:starting:translate-y-2">
                    {children}
                </div>
            </div>
        </section>
    );
}

function DestinationImageCard({
    destinationCode,
    destinationName,
    title,
    tripContext,
}: {
    destinationCode?: string;
    destinationName: string;
    title: string;
    tripContext: string;
}) {
    return (
        <div className="relative min-h-[240px] overflow-hidden rounded-md bg-slate-100 opacity-100 shadow-[0_14px_34px_-28px_rgba(15,23,42,0.45)] transition-all delay-75 duration-500 lg:min-h-[300px] starting:opacity-0 motion-safe:starting:translate-y-2">
            <img
                src={routeHeroImage(destinationCode)}
                alt={destinationName}
                className="absolute inset-0 size-full object-cover transition-transform duration-500 hover:scale-[1.025]"
            />
            <div className="absolute inset-0 bg-gradient-to-t from-slate-950/82 via-slate-950/28 to-transparent" />
            <div className="absolute inset-x-0 bottom-0 p-5 text-white sm:p-6">
                <h1 className="font-display text-3xl leading-tight font-semibold tracking-normal sm:text-4xl">
                    {title}
                </h1>
                <p className="mt-2 max-w-xl text-sm leading-6 font-semibold text-white/90">
                    {tripContext}
                </p>
            </div>
        </div>
    );
}

function ResultsMenuBar({ customer }: { customer: CustomerSummary }) {
    const { t } = useTranslation();

    return (
        <div className="relative z-40 border-b border-thy-navy bg-thy-navy">
            <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <Link href="/" className="flex min-w-0 items-center gap-3">
                    <img
                        src="/assets/oneliner_whitetext.svg"
                        className="h-7 w-auto shrink-0 object-contain sm:h-8"
                        alt="Turkish Airlines logo"
                    />
                </Link>

                <nav className="hidden items-center gap-5 text-sm font-medium text-white/80 md:flex">
                    <Link className="hover:text-white" href="/">
                        {t('results.book')}
                    </Link>
                    <a className="hover:text-white" href="#results">
                        {t('results.results')}
                    </a>
                </nav>

                <div className="flex shrink-0 items-center gap-2">
                    <LanguageSwitcher variant="light" />
                    {customer.isAuthenticated ? (
                        <Link
                            href={dashboard()}
                            className="inline-flex h-9 items-center rounded-md border border-white/35 px-3 text-sm font-medium text-white transition-colors hover:bg-white hover:text-thy-navy"
                        >
                            {t('app.dashboard')}
                        </Link>
                    ) : (
                        <>
                            <Link
                                href={register()}
                                className="hidden h-9 items-center rounded-md border border-white/35 px-3 text-sm font-medium text-white transition-colors hover:bg-white hover:text-thy-navy sm:inline-flex"
                            >
                                {t('public.join')}
                            </Link>
                            <Link
                                href={login()}
                                className="inline-flex h-9 items-center rounded-md bg-red-800 px-3 text-sm font-semibold text-white transition-colors hover:bg-red-950"
                            >
                                {t('public.login')}
                            </Link>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

function cityName(name?: string, fallback?: string): string {
    if (!name) {
        return fallback ?? 'Destination';
    }

    return name
        .replace(/\s+(International\s+)?Airport$/i, '')
        .replace(/\s+Havalimani$/i, '')
        .trim();
}

function resultsTitle(
    destinationName: string,
    locale: 'en' | 'tr',
    t: (key: 'results.tripTo') => string,
): string {
    if (locale === 'tr') {
        return `${destinationName} seyahatiniz`;
    }

    return `${t('results.tripTo')} ${destinationName}`;
}

function tripSummary(
    filters: SearchFilters,
    locale: 'en' | 'tr',
    t: (key: 'search.passenger' | 'search.passengers') => string,
): string {
    const passengers = totalPassengers(
        filters.adults,
        filters.children,
        filters.babies,
    );
    const passengerLabel =
        passengers === 1 ? t('search.passenger') : t('search.passengers');

    return `${passengers} ${passengerLabel} · ${tripDateLabel(filters, locale)}`;
}

function tripDateLabel(filters: SearchFilters, locale: 'en' | 'tr'): string {
    if (filters.trip_type !== 'round_trip' || !filters.return_date) {
        return formatShortDate(filters.depart_date, locale);
    }

    const departureDate = parseTripDate(filters.depart_date);
    const returnDate = parseTripDate(filters.return_date);

    if (
        departureDate.getFullYear() === returnDate.getFullYear() &&
        departureDate.getMonth() === returnDate.getMonth()
    ) {
        const month = departureDate.toLocaleDateString(
            locale === 'tr' ? 'tr-TR' : 'en-US',
            {
                month: 'short',
            },
        );

        return `${month} ${departureDate.getDate()}-${returnDate.getDate()}`;
    }

    return `${formatShortDate(filters.depart_date, locale)} - ${formatShortDate(filters.return_date, locale)}`;
}

function parseTripDate(value: string): Date {
    return new Date(`${value}T00:00:00`);
}
