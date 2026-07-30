import { Head, Link, usePage } from '@inertiajs/react';
import { Plane, Search, UserRound } from 'lucide-react';
import { dashboard, home, login, register } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <main className="relative min-h-screen overflow-hidden bg-slate-950 text-white">
                <div
                    className="absolute inset-0 bg-cover bg-center opacity-65"
                    style={{ backgroundImage: "url('/assets/team_hero.jpg')" }}
                />
                <div className="absolute inset-0 bg-linear-to-b from-black/80 via-black/25 to-black/80" />

                <div className="relative z-10 mx-auto flex min-h-screen max-w-6xl flex-col px-6 py-6">
                    <header className="flex items-center justify-between gap-4">
                        <Link href={home()} className="flex items-center gap-3">
                            <img
                                src="/assets/thy-emblem.svg"
                                className="h-12 w-12 rounded-md bg-white object-contain p-1"
                                alt="Turkish Airlines logo"
                            />
                            <span className="leading-none">
                                <span className="block font-display text-[22px] font-black tracking-normal">
                                    TURKISH AIRLINES
                                </span>
                                <span className="mt-1 block font-condensed text-[11px] font-bold tracking-[0.25em] text-white/70">
                                    WIDEN YOUR WORLD
                                </span>
                            </span>
                        </Link>

                        <nav className="flex items-center gap-2">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-white/80 px-4 text-sm font-bold transition-colors hover:bg-white hover:text-red-900"
                                >
                                    <UserRound className="size-4" />
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="inline-flex h-10 items-center justify-center rounded-md border border-white/80 px-4 text-sm font-bold transition-colors hover:bg-white hover:text-red-900"
                                    >
                                        Log in
                                    </Link>
                                    <Link
                                        href={register()}
                                        className="hidden h-10 items-center justify-center rounded-md bg-white px-4 text-sm font-bold text-red-900 transition-colors hover:bg-red-50 sm:inline-flex"
                                    >
                                        Join
                                    </Link>
                                </>
                            )}
                        </nav>
                    </header>

                    <section className="flex flex-1 items-center py-16">
                        <div className="max-w-3xl">
                            <p className="font-condensed text-sm font-black tracking-[0.22em] text-red-200 uppercase">
                                Dynamic Pricer
                            </p>
                            <h1 className="mt-5 font-display text-5xl leading-tight font-black tracking-normal md:text-7xl">
                                Widen Your World
                            </h1>
                            <p className="mt-6 max-w-xl text-base leading-7 font-medium text-white/85">
                                Search routes, compare fare bundles, and keep
                                every confirmed trip close at hand.
                            </p>
                            <Link
                                href={home()}
                                className="mt-8 inline-flex h-12 items-center justify-center gap-2 rounded-md bg-white px-5 text-sm font-black text-red-900 transition-colors hover:bg-red-50"
                            >
                                <Search className="size-4" />
                                Search flights
                            </Link>
                        </div>
                    </section>

                    <footer className="grid gap-3 border-t border-white/15 py-5 text-sm font-bold text-white/75 sm:grid-cols-3">
                        <span className="inline-flex items-center gap-2">
                            <Plane className="size-4 text-red-200" />
                            300+ destinations
                        </span>
                        <span>Live fare rules</span>
                        <span>Wingo trip guidance</span>
                    </footer>
                </div>
            </main>
        </>
    );
}
