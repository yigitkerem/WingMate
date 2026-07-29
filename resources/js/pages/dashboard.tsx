import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    CircleCheck,
    Luggage,
    Plane,
    Search,
    TicketCheck,
} from 'lucide-react';
import { dashboard, home } from '@/routes';

type Ticket = {
    id: number;
    state: 'upcoming' | 'past';
    pnr: {
        first_name: string;
        last_name: string;
        passport_number: string;
        booking_reference: string;
    };
    flight: {
        flight_number: string;
        plane_model: string;
        depart_at: string;
        depart_date: string;
        depart_time: string;
        arrival_time: string;
        duration: string;
        origin: {
            code: string;
            name: string;
        };
        destination: {
            code: string;
            name: string;
        };
    };
    fare: {
        class: string;
        class_letters: string;
        fare_type: string;
        base_price_usd: number;
        checked_baggage_kg: number;
        cabin_baggage_kg: number;
        seat_selection_free: boolean;
    };
    flown: boolean;
};

type Props = {
    tickets: {
        upcoming: Ticket[];
        past: Ticket[];
    };
    ticketStats: {
        upcoming: number;
        past: number;
        total: number;
    };
};

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

export default function Dashboard({ tickets, ticketStats }: Props) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto bg-slate-50 p-4 text-slate-950 md:p-8 dark:bg-slate-950 dark:text-white">
                <section className="relative overflow-hidden rounded-md bg-slate-950 text-white">
                    <div
                        className="absolute inset-0 bg-cover bg-center opacity-30"
                        style={{
                            backgroundImage: "url('/assets/team_hero.jpg')",
                        }}
                    />
                    <div className="absolute inset-0 bg-linear-to-r from-slate-950 via-slate-950/85 to-red-950/60" />
                    <div className="relative z-10 grid gap-8 p-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-end lg:p-8">
                        <div className="space-y-4">
                            <p className="text-xs font-black tracking-[0.22em] text-red-200 uppercase">
                                Passenger area
                            </p>
                            <div className="max-w-3xl space-y-3">
                                <h1 className="text-4xl leading-tight font-black tracking-normal md:text-5xl">
                                    Your journeys, ready when you are.
                                </h1>
                                <p className="max-w-2xl text-sm leading-6 font-medium text-white/80">
                                    Review upcoming tickets, baggage rights,
                                    fare bundles, and completed trips from one
                                    focused dashboard.
                                </p>
                            </div>
                            <Link
                                href={home()}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-white px-4 text-sm font-black text-red-900 transition-colors hover:bg-red-50 focus:outline-none focus-visible:ring-3 focus-visible:ring-white/40"
                            >
                                <Search className="size-4" />
                                Search flights
                            </Link>
                        </div>

                        <div className="grid grid-cols-3 gap-2 rounded-md border border-white/15 bg-white/10 p-3 text-center backdrop-blur">
                            <Stat
                                label="Upcoming"
                                value={ticketStats.upcoming}
                            />
                            <Stat label="Past" value={ticketStats.past} />
                            <Stat label="Total" value={ticketStats.total} />
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="rounded-md border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-black tracking-[0.18em] text-red-800 uppercase dark:text-red-300">
                                    Next step
                                </p>
                                <h2 className="mt-2 text-xl font-black">
                                    Keep planning with Wingo
                                </h2>
                                <p className="mt-2 max-w-2xl text-sm leading-6 font-medium text-slate-500 dark:text-slate-300">
                                    Compare fare bundles before booking and keep
                                    confirmed tickets attached to your passenger
                                    profile.
                                </p>
                            </div>
                            <Plane className="size-6 shrink-0 text-red-800 dark:text-red-300" />
                        </div>
                    </div>

                    <div className="grid grid-cols-3 gap-2 rounded-md border border-slate-200 bg-white p-3 lg:grid-cols-1 dark:border-slate-800 dark:bg-slate-900">
                        <QuickFact
                            icon={TicketCheck}
                            label="Tickets"
                            value={ticketStats.total}
                        />
                        <QuickFact
                            icon={CalendarDays}
                            label="Upcoming"
                            value={ticketStats.upcoming}
                        />
                        <QuickFact
                            icon={CircleCheck}
                            label="Completed"
                            value={ticketStats.past}
                        />
                    </div>
                </section>

                <div className="grid gap-6 xl:grid-cols-2">
                    <TicketSection
                        title="Upcoming tickets"
                        empty="No upcoming tickets yet."
                        tickets={tickets.upcoming}
                    />
                    <TicketSection
                        title="Past tickets"
                        empty="Past tickets will appear here after travel."
                        tickets={tickets.past}
                    />
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-md bg-white/10 px-3 py-4">
            <p className="text-2xl font-black">{value}</p>
            <p className="mt-1 text-[11px] font-bold tracking-[0.12em] text-white/65 uppercase">
                {label}
            </p>
        </div>
    );
}

function QuickFact({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof TicketCheck;
    label: string;
    value: number;
}) {
    return (
        <div className="flex min-w-0 items-center gap-3 rounded-md bg-slate-50 px-3 py-3 dark:bg-slate-950">
            <Icon className="size-4 shrink-0 text-red-800 dark:text-red-300" />
            <div className="min-w-0">
                <p className="text-lg leading-none font-black">{value}</p>
                <p className="mt-1 truncate text-xs font-bold text-slate-500 dark:text-slate-400">
                    {label}
                </p>
            </div>
        </div>
    );
}

function TicketSection({
    title,
    empty,
    tickets,
}: {
    title: string;
    empty: string;
    tickets: Ticket[];
}) {
    return (
        <section className="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <div>
                    <p className="text-xs font-black tracking-[0.18em] text-red-800 uppercase dark:text-red-300">
                        Tickets
                    </p>
                    <h2 className="mt-1 text-lg font-black">{title}</h2>
                </div>
                <CalendarDays className="size-5 text-red-800 dark:text-red-300" />
            </div>

            {tickets.length === 0 ? (
                <div className="flex min-h-56 flex-col items-center justify-center gap-3 p-6 text-center text-sm font-medium text-slate-500 dark:text-slate-400">
                    <Plane className="size-8 text-red-800 dark:text-red-300" />
                    {empty}
                </div>
            ) : (
                <div className="divide-y divide-slate-200 dark:divide-slate-800">
                    {tickets.map((ticket) => (
                        <TicketRow key={ticket.id} ticket={ticket} />
                    ))}
                </div>
            )}
        </section>
    );
}

function TicketRow({ ticket }: { ticket: Ticket }) {
    return (
        <article className="grid gap-5 p-5">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="space-y-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="text-3xl font-black tracking-normal">
                            {ticket.flight.origin.code}
                        </span>
                        <ArrowRight className="size-5 text-red-800 dark:text-red-300" />
                        <span className="text-3xl font-black tracking-normal">
                            {ticket.flight.destination.code}
                        </span>
                        <span className="rounded-md border border-red-100 bg-red-50 px-2.5 py-1 text-xs font-black text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-100">
                            {ticket.fare.class_letters}
                        </span>
                    </div>
                    <p className="text-sm font-medium text-slate-500 dark:text-slate-400">
                        {ticket.flight.origin.name} to{' '}
                        {ticket.flight.destination.name}
                    </p>
                </div>

                <div className="rounded-md bg-slate-50 px-3 py-2 text-right dark:bg-slate-950">
                    <p className="text-lg font-black">
                        {money.format(ticket.fare.base_price_usd)}
                    </p>
                    <p className="text-xs font-bold text-slate-500 capitalize dark:text-slate-400">
                        {ticket.fare.fare_type.replace('_', ' ')}
                    </p>
                </div>
            </div>

            <div className="grid gap-3 text-sm md:grid-cols-2">
                <div className="rounded-md bg-slate-50 p-3 dark:bg-slate-950">
                    <p className="font-black">
                        {ticket.flight.depart_date} at{' '}
                        {ticket.flight.depart_time} to{' '}
                        {ticket.flight.arrival_time}
                    </p>
                    <p className="mt-1 font-medium text-slate-500 dark:text-slate-400">
                        {ticket.flight.flight_number} |{' '}
                        {ticket.flight.plane_model} | {ticket.flight.duration}
                    </p>
                </div>

                <div className="rounded-md bg-slate-50 p-3 dark:bg-slate-950">
                    <p className="font-black">
                        {ticket.pnr.first_name} {ticket.pnr.last_name}
                    </p>
                    <p className="mt-1 font-medium text-slate-500 dark:text-slate-400">
                        Booking {ticket.pnr.booking_reference} | Passport{' '}
                        {ticket.pnr.passport_number}
                    </p>
                </div>
            </div>

            <div className="flex flex-wrap gap-2 text-xs font-bold text-slate-600 dark:text-slate-300">
                <span className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 dark:border-slate-700">
                    <Luggage className="size-3.5 text-red-800 dark:text-red-300" />
                    {ticket.fare.checked_baggage_kg} kg checked
                </span>
                <span className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 dark:border-slate-700">
                    <Luggage className="size-3.5 text-red-800 dark:text-red-300" />
                    {ticket.fare.cabin_baggage_kg} kg cabin
                </span>
                <span className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 dark:border-slate-700">
                    <CircleCheck className="size-3.5 text-red-800 dark:text-red-300" />
                    {ticket.fare.seat_selection_free ? 'Free' : 'Paid'} seats
                </span>
                <span className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 dark:border-slate-700">
                    {ticket.fare.class}
                </span>
            </div>
        </article>
    );
}
