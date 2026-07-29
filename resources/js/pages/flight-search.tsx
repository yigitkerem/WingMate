import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PublicHeader } from '@/components/public-flight/public-header';
import {
    FeatureGrid,
    PopularRoutes,
    PublicFooter,
} from '@/components/public-flight/public-sections';
import {
    routeCodesToIds,
    SearchCard,
} from '@/components/public-flight/search-card';
import {
    initializeSearchFilters,
    publishWingoSearchPrefill,
    storeLastDestinationAirport,
} from '@/lib/flight-search';
import type {
    Airport,
    CustomerSummary,
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

export default function FlightSearch({
    airports,
    filters,
    results,
    canUseFullSearch,
    customer,
    errors = {},
}: Props) {
    const [form, setForm] = useState<SearchFilters>(() =>
        initializeSearchFilters(filters, airports),
    );

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

        router.post(
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

        setForm((current) => ({
            ...current,
            origin_airport_id: originId,
            destination_airport_id: destinationId,
            depart_date: date,
            trip_type: 'one_way',
            search_mode: canUseFullSearch ? current.search_mode : 'basic',
        }));
    }

    return (
        <>
            <Head title="Dynamic Pricer · Wingo" />
            <main className="min-h-screen bg-slate-50 font-sans text-slate-950">
                <PublicHeader customer={customer} />
                <SearchCard
                    airports={airports}
                    filters={form}
                    canUseFullSearch={canUseFullSearch}
                    errors={errors}
                    hasResults={results !== null}
                    onSubmit={submit}
                    onChange={update}
                    onQuickSearch={quickSearch}
                />
                {!results && (
                    <>
                        <PopularRoutes onQuickSearch={quickSearch} />
                        <FeatureGrid />
                    </>
                )}
                <PublicFooter />
            </main>
        </>
    );
}
