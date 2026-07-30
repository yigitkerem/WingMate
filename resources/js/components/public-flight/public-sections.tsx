import {
    ArrowRight,
    Layers,
    MessageCircle,
    RefreshCcw,
    TrendingDown,
} from 'lucide-react';
import { featuredRoutes } from '@/lib/flight-search';
import { useTranslation } from '@/lib/i18n';

type PublicSectionsProps = {
    onQuickSearch: (
        originCode: string,
        destinationCode: string,
        date: string,
    ) => void;
};

export function PopularRoutes({ onQuickSearch }: PublicSectionsProps) {
    const { locale, t } = useTranslation();

    return (
        <section
            id="featured-fares"
            className="mx-auto mt-8 max-w-6xl rounded-md border border-slate-200 bg-white p-6 md:p-8"
        >
            <div className="mb-6 flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 pb-4">
                <div>
                    <span className="mb-1 block font-condensed text-[11px] font-extrabold tracking-wider text-red-700 uppercase">
                        {t('routes.featuredFares')}
                    </span>
                    <h2 className="font-display text-xl font-black tracking-normal text-slate-900 md:text-2xl">
                        {t('routes.popularThisWeek')}
                    </h2>
                    <p className="mt-1 text-xs font-medium text-slate-500 md:text-sm">
                        {t('routes.availability')}
                    </p>
                </div>
                <button
                    type="button"
                    className="inline-flex items-center gap-2 rounded-md border border-slate-900 px-4 py-2 text-xs font-bold text-slate-900 transition-colors hover:bg-slate-900 hover:text-white"
                    onClick={() => onQuickSearch('IST', 'LHR', '2026-08-06')}
                >
                    {t('routes.viewAll')}
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
                            <span className="rounded-md border border-white/20 bg-black/65 px-2.5 py-1 font-condensed text-xs font-black tracking-wider text-white">
                                {route.origin} - {route.destination}
                            </span>
                            <span className="rounded-md bg-black/50 px-2.5 py-1 text-xs font-bold text-white">
                                {t('routes.from')} {route.price}
                            </span>
                        </div>
                        <div className="absolute right-3 bottom-3 left-3 text-white">
                            <p className="text-[11px] font-medium text-slate-300">
                                {t('routes.travelFrom')}{' '}
                                {new Date(
                                    `${route.date}T00:00:00`,
                                ).toLocaleDateString(
                                    locale === 'tr' ? 'tr-TR' : 'en-US',
                                    {
                                        month: 'short',
                                        day: 'numeric',
                                    },
                                )}
                            </p>
                            <h3 className="mt-0.5 font-display text-base font-extrabold tracking-normal transition-colors group-hover:text-red-300">
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
    const { t } = useTranslation();
    const features = [
        {
            title: t('features.dynamicPricing'),
            description: t('features.dynamicPricingDesc'),
            icon: TrendingDown,
        },
        {
            title: t('features.roundTrip'),
            description: t('features.roundTripDesc'),
            icon: RefreshCcw,
        },
        {
            title: t('features.aiPackaging'),
            description: t('features.aiPackagingDesc'),
            icon: Layers,
        },
        {
            title: t('features.assistant'),
            description: t('features.assistantDesc'),
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
                        <h3 className="mb-1.5 font-display text-[15px] font-bold text-slate-900">
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
    const { t } = useTranslation();

    return (
        <footer className="mt-16 bg-[#1B1E24] text-[11px] font-medium text-white/60">
            <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-6 py-6">
                <span>© {t('footer.demo')}</span>
                <span>{t('footer.links')}</span>
            </div>
        </footer>
    );
}
