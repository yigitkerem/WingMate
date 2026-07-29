import { Link, usePage } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <main className="grid min-h-svh bg-slate-50 text-slate-950 lg:grid-cols-[minmax(0,1.05fr)_minmax(420px,.95fr)]">
            <section className="relative hidden overflow-hidden bg-slate-950 text-white lg:block">
                <div
                    className="absolute inset-0 bg-cover bg-center opacity-75"
                    style={{ backgroundImage: "url('/assets/team_hero.jpg')" }}
                />
                <div className="absolute inset-0 bg-linear-to-b from-black/80 via-black/20 to-black/80" />
                <div className="relative z-10 flex min-h-svh flex-col justify-between p-10">
                    <Link href={home()} className="flex items-center gap-3">
                        <img
                            src="/assets/thy-emblem.svg"
                            className="h-12 w-12 rounded-md bg-white object-contain p-1"
                            alt="Turkish Airlines logo"
                        />
                        <span className="leading-none">
                            <span className="block text-[22px] font-black tracking-normal">
                                TURKISH AIRLINES
                            </span>
                            <span className="mt-1 block text-[11px] font-bold tracking-[0.25em] text-white/70">
                                WIDEN YOUR WORLD
                            </span>
                        </span>
                    </Link>

                    <div className="max-w-xl pb-10">
                        <p className="text-sm font-bold tracking-[0.22em] text-red-200 uppercase">
                            {name}
                        </p>
                        <h2 className="mt-5 text-5xl leading-tight font-black tracking-normal">
                            Smart fares, clearer trips.
                        </h2>
                        <p className="mt-5 max-w-md text-base leading-7 font-medium text-white/85">
                            Search flights, compare bundled fares, and keep
                            every confirmed journey in one passenger area.
                        </p>
                        <div className="mt-8 grid max-w-md grid-cols-3 gap-3 text-sm font-bold">
                            {[
                                '300+ destinations',
                                'Live fare rules',
                                'Wingo help',
                            ].map((item) => (
                                <span
                                    key={item}
                                    className="rounded-md border border-white/20 bg-white/10 px-3 py-3 text-center"
                                >
                                    {item}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            <section className="flex min-h-svh items-center justify-center px-5 py-8 sm:px-8">
                <div className="w-full max-w-[420px] rounded-md border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
                    <Link
                        href={home()}
                        className="mb-8 flex items-center gap-3 lg:hidden"
                    >
                        <img
                            src="/assets/thy-emblem.svg"
                            className="h-11 w-11 rounded-md bg-white object-contain p-1 shadow-sm ring-1 ring-slate-200"
                            alt="Turkish Airlines logo"
                        />
                        <span className="leading-none">
                            <span className="block text-lg font-black tracking-normal">
                                TURKISH AIRLINES
                            </span>
                            <span className="mt-1 block text-[10px] font-bold tracking-[0.2em] text-slate-500">
                                WIDEN YOUR WORLD
                            </span>
                        </span>
                    </Link>

                    <div className="mb-7 space-y-2">
                        <p className="text-xs font-black tracking-[0.2em] text-red-800 uppercase">
                            Passenger area
                        </p>
                        <h1 className="text-3xl font-black tracking-normal text-slate-950">
                            {title}
                        </h1>
                        <p className="text-sm leading-6 font-medium text-slate-500">
                            {description}
                        </p>
                    </div>

                    {children}
                </div>
            </section>
        </main>
    );
}
