import {
    ArrowRight,
    Layers,
    MessageCircle,
    RefreshCcw,
    TrendingDown,
} from 'lucide-react';
import { featuredRoutes } from '@/lib/flight-search';

type PublicSectionsProps = {
    onQuickSearch: (
        originCode: string,
        destinationCode: string,
        date: string,
    ) => void;
};

export function PopularRoutes({ onQuickSearch }: PublicSectionsProps) {
    return (
        <section
            id="featured-fares"
            className="mx-auto mt-8 max-w-6xl rounded-md border border-slate-200 bg-white p-6 md:p-8"
        >
            <div className="mb-6 flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 pb-4">
                <div>
                    <span className="mb-1 block text-[11px] font-extrabold tracking-wider text-red-700 uppercase">
                        Featured fares
                    </span>
                    <h2 className="text-xl font-black tracking-normal text-slate-900 md:text-2xl">
                        Popular routes this week
                    </h2>
                    <p className="mt-1 text-xs font-medium text-slate-500 md:text-sm">
                        Direct Turkish Airlines fares with seasonal
                        availability.
                    </p>
                </div>
                <button
                    type="button"
                    className="inline-flex items-center gap-2 rounded-md border border-slate-900 px-4 py-2 text-xs font-bold text-slate-900 transition-colors hover:bg-slate-900 hover:text-white"
                    onClick={() => onQuickSearch('IST', 'LHR', '2026-08-06')}
                >
                    View all routes
                    <ArrowRight className="size-3.5" />
                </button>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {featuredRoutes.map((route) => (
                    <button
                        type="button"
                        key={`${route.origin}-${route.destination}`}
                        className="group relative h-44 overflow-hidden rounded-md border border-slate-200 text-left"
                        onClick={() =>
                            onQuickSearch(
                                route.origin,
                                route.destination,
                                route.date,
                            )
                        }
                    >
                        <img
                            src={route.image}
                            alt={route.route}
                            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                        />
                        <div className="absolute inset-0 bg-linear-to-t from-slate-950/90 via-slate-950/40 to-transparent" />
                        <div className="absolute top-3 left-3 flex w-[calc(100%-24px)] items-center justify-between gap-3">
                            <span className="rounded-md border border-white/20 bg-black/65 px-2.5 py-1 text-xs font-black tracking-wider text-white">
                                {route.origin} - {route.destination}
                            </span>
                            <span className="rounded-md bg-black/50 px-2.5 py-1 text-xs font-bold text-white">
                                From {route.price}
                            </span>
                        </div>
                        <div className="absolute right-3 bottom-3 left-3 text-white">
                            <p className="text-[11px] font-medium text-slate-300">
                                Travel from{' '}
                                {new Date(
                                    `${route.date}T00:00:00`,
                                ).toLocaleDateString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                })}
                            </p>
                            <h3 className="mt-0.5 text-base font-extrabold tracking-normal transition-colors group-hover:text-red-300">
                                {route.route}
                            </h3>
                        </div>
                    </button>
                ))}
            </div>
        </section>
    );
}

export function FeatureGrid() {
    const features = [
        {
            title: 'Dynamic pricing',
            description:
                'Departure timing, availability, and fare family rules are reflected in each search.',
            icon: TrendingDown,
        },
        {
            title: 'Round-trip advantage',
            description:
                'Round-trip searches can surface fare families that are not available as separate one-way tickets.',
            icon: RefreshCcw,
        },
        {
            title: 'AI-ready packaging',
            description:
                'Wingo can build personalized bundles from available fares and selectable ancillary products.',
            icon: Layers,
        },
        {
            title: 'Travel assistant',
            description:
                'Ask natural-language questions in the side panel and get grounded answers from flight and policy tools.',
            icon: MessageCircle,
        },
    ];

    return (
        <section className="mx-auto mt-12 grid max-w-6xl gap-5 px-6 md:grid-cols-4">
            {features.map((feature) => {
                const Icon = feature.icon;

                return (
                    <article
                        key={feature.title}
                        className="rounded-md border border-slate-200 bg-white p-6"
                    >
                        <div className="mb-4 flex size-11 items-center justify-center rounded-md bg-red-50 text-red-800">
                            <Icon className="size-5" />
                        </div>
                        <h3 className="mb-1.5 text-[15px] font-bold text-slate-900">
                            {feature.title}
                        </h3>
                        <p className="text-[13px] leading-relaxed font-medium text-slate-500">
                            {feature.description}
                        </p>
                    </article>
                );
            })}
        </section>
    );
}

export function PublicFooter() {
    return (
        <footer className="mt-16 bg-[#1B1E24] text-[11px] font-medium text-white/60">
            <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-6 py-6">
                <span>
                    © Dynamic Pricer demo. Turkish Airlines-inspired public
                    interface.
                </span>
                <span>Privacy Policy · Terms of Use · Contact</span>
            </div>
        </footer>
    );
}
