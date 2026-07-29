import { Link } from '@inertiajs/react';
import {
    Briefcase,
    CalendarDays,
    MessageCircle,
    Plane,
    UserRound,
} from 'lucide-react';
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
                style={{ backgroundImage: "url('/assets/team_hero.jpg')" }}
            />
            <div className="absolute inset-0 bg-linear-to-b from-black/75 via-black/20 to-black/70" />

            <div className="relative z-10">
                <TopStrip />
                <MainNav customer={customer} onOpenChat={onOpenChat} />
                <HeroCopy />
            </div>
        </header>
    );
}

function TopStrip() {
    return (
        <div className="border-b border-white/10 text-[13px] font-medium text-white/90">
            <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-2">
                <span>Turkiye · English · USD</span>
                <div className="hidden items-center gap-4 sm:flex">
                    <a className="hover:text-white" href="#services">
                        Help
                    </a>
                    <a className="hover:text-white" href="#featured-fares">
                        Campaigns
                    </a>
                    <span>Miles&Smiles</span>
                </div>
            </div>
        </div>
    );
}

function MainNav({ customer, onOpenChat }: PublicHeaderProps) {
    return (
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-5 px-6 py-4">
            <Link href="/" className="flex min-w-0 items-center gap-3">
                <img
                    src="/assets/thy-emblem.svg"
                    className="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1"
                    alt="Turkish Airlines logo"
                />
                <span className="min-w-0 leading-none">
                    <span className="block text-[23px] font-black tracking-normal">
                        TURKISH AIRLINES
                    </span>
                    <span className="mt-1 block text-[11px] font-bold tracking-[0.25em] text-white/70">
                        WIDEN YOUR WORLD
                    </span>
                </span>
            </Link>

            <nav className="hidden items-center gap-6 text-[15px] font-bold text-white/90 lg:flex">
                <a
                    className="inline-flex items-center gap-1.5 hover:text-white"
                    href="#booking"
                >
                    <Plane className="size-4" />
                    Flight Planning
                </a>
                <a
                    className="inline-flex items-center gap-1.5 hover:text-white"
                    href="#services"
                >
                    <Briefcase className="size-4" />
                    Check-in and Manage
                </a>
                <a
                    className="inline-flex items-center gap-1.5 hover:text-white"
                    href="#results"
                >
                    <CalendarDays className="size-4" />
                    Flight Status
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
                        Dashboard
                    </Link>
                ) : (
                    <>
                        <Link
                            href={register()}
                            className="hidden h-10 items-center justify-center rounded-md border border-white/80 px-4 text-sm font-bold text-white transition-colors hover:bg-white hover:text-red-800 sm:inline-flex"
                        >
                            Join
                        </Link>
                        <Link
                            href={login()}
                            className="inline-flex h-10 items-center justify-center rounded-md border border-white/80 px-4 text-sm font-bold text-white transition-colors hover:bg-white hover:text-red-800"
                        >
                            Log in
                        </Link>
                    </>
                )}
            </div>
        </div>
    );
}

function HeroCopy() {
    return (
        <div className="mx-auto max-w-6xl px-6 pt-24 pb-48">
            <h1 className="max-w-3xl text-4xl leading-tight font-black tracking-normal text-white md:text-[54px]">
                Widen Your World
            </h1>
            <p className="mt-4 max-w-xl text-[15px] leading-6 font-medium text-white/90">
                Flights to more than 300 destinations, clear fare choices, and
                wingo guidance when you want help choosing.
            </p>
        </div>
    );
}
