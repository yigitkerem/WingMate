import { Link } from '@inertiajs/react';
import {
    Briefcase,
    CalendarDays,
    MessageCircle,
    Plane,
    UserRound,
} from 'lucide-react';
import { LanguageSwitcher } from '@/components/language-switcher';
import { useTranslation } from '@/lib/i18n';
import { dashboard, login, register } from '@/routes';
import type { CustomerSummary } from '@/types/flight-search';

type PublicHeaderProps = {
    customer: CustomerSummary;
    onOpenChat?: () => void;
};

export function PublicHeader({ customer, onOpenChat }: PublicHeaderProps) {
    return (
        <header className="relative overflow-hidden bg-slate-950 text-white">
            <div
                className="absolute inset-0 bg-cover bg-center opacity-75"
                style={{ backgroundImage: "url('/assets/herobg_tk.jpg')" }}
            />
            <div className="absolute inset-0 bg-linear-to-b from-black/45 via-black/10 to-black/40" />

            <div className="relative z-10">
                <TopStrip />
                <MainNav customer={customer} onOpenChat={onOpenChat} />
                <HeroCopy customer={customer} />
            </div>
        </header>
    );
}

function TopStrip() {
    const { t } = useTranslation();

    return (
        <div className="border-b border-white/10 text-[13px] font-medium text-white/90">
            <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-2">
                <span>
                    {t('public.country')} · {t('public.currency')}
                </span>
                <LanguageSwitcher className="sm:hidden" variant="light" />
                <div className="hidden items-center gap-4 sm:flex">
                    <a className="hover:text-white" href="#services">
                        {t('public.help')}
                    </a>
                    <a className="hover:text-white" href="#featured-fares">
                        {t('public.campaigns')}
                    </a>
                    <span>Miles&Smiles</span>
                    <LanguageSwitcher variant="light" />
                </div>
            </div>
        </div>
    );
}

function MainNav({ customer, onOpenChat }: PublicHeaderProps) {
    const { t } = useTranslation();

    return (
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-5 px-6 py-4">
            <Link href="/" className="flex min-w-0 items-center gap-3">
                <img
                    src="/assets/oneliner_whitetext.svg"
                    className="h-8 w-auto shrink-0 object-contain sm:h-10"
                    alt="Turkish Airlines logo"
                />
            </Link>

            <nav className="hidden items-center gap-6 text-[15px] font-bold text-white/90 lg:flex">
                <a
                    className="inline-flex items-center gap-1.5 hover:text-white"
                    href="#booking"
                >
                    <Plane className="size-4" />
                    {t('public.flightPlanning')}
                </a>
                <a
                    className="inline-flex items-center gap-1.5 hover:text-white"
                    href="#services"
                >
                    <Briefcase className="size-4" />
                    {t('public.checkManage')}
                </a>
                <a
                    className="inline-flex items-center gap-1.5 hover:text-white"
                    href="#results"
                >
                    <CalendarDays className="size-4" />
                    {t('public.flightStatus')}
                </a>
                {onOpenChat && (
                    <button
                        type="button"
                        className="inline-flex items-center gap-1.5 hover:text-white"
                        onClick={onOpenChat}
                    >
                        <MessageCircle className="size-4" />
                        Wingo
                    </button>
                )}
            </nav>

            <div className="flex shrink-0 items-center gap-2">
                {customer.isAuthenticated ? (
                    <Link
                        href={dashboard()}
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-white/80 px-4 text-sm font-bold text-white transition-colors hover:bg-white hover:text-red-800"
                    >
                        <UserRound className="size-4" />
                        {t('app.dashboard')}
                    </Link>
                ) : (
                    <>
                        <Link
                            href={register()}
                            className="hidden h-10 items-center justify-center rounded-md border border-white/80 px-4 text-sm font-bold text-white transition-colors hover:bg-white hover:text-red-800 sm:inline-flex"
                        >
                            {t('public.join')}
                        </Link>
                        <Link
                            href={login()}
                            className="inline-flex h-10 items-center justify-center rounded-md border border-white/80 px-4 text-sm font-bold text-white transition-colors hover:bg-white hover:text-red-800"
                        >
                            {t('public.login')}
                        </Link>
                    </>
                )}
            </div>
        </div>
    );
}

function HeroCopy({ customer }: { customer: CustomerSummary }) {
    const { t } = useTranslation();
    const firstName = customer.firstName.trim();
    const title =
        customer.isAuthenticated && firstName
            ? t('public.heroTitleSignedIn').replace(':name', firstName)
            : t('public.heroTitle');

    return (
        <div className="mx-auto max-w-6xl px-6 pt-24 pb-48">
            <h1 className="max-w-3xl font-display text-4xl leading-tight font-black tracking-normal text-white md:text-[54px]">
                {title}
            </h1>
        </div>
    );
}
