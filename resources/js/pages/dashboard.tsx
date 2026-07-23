import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CalendarDays, Plane, Search } from 'lucide-react';
import { dashboard, home } from '@/routes';

type Ticket = {
    id: number;
    state: 'upcoming' | 'past';
    pnr: {
        first_name: string;
        last_name: string;
        passport_number: string;
    };
    flight: {
        flight_number: string;
        plane_model: string;
        depart_at: string;
        depart_date: string;
        depart_time: string;
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
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto bg-[#f7f4ef] p-4 text-[#191411] md:p-8 dark:bg-[#141210] dark:text-[#f6efe7]">
                <div className="flex flex-col justify-between gap-4 border border-[#251f1a] bg-white p-5 md:flex-row md:items-end dark:border-[#efe2d2] dark:bg-[#1c1815]">
                    <div className="space-y-2">
                        <p className="text-xs font-semibold tracking-[0.18em] text-[#c1121f] uppercase">
                            Passenger area
                        </p>
                        <h1 className="text-3xl font-semibold">
                            Your tickets
                        </h1>
                        <p className="max-w-2xl text-sm text-[#665d55] dark:text-[#cfc2b7]">
                            Upcoming flights, previous trips, fare class, and
                            baggage rights from your bookings.
                        </p>
                    </div>
                    <Link
                        href={home()}
                        className="inline-flex items-center justify-center gap-2 border border-[#c1121f] bg-[#c1121f] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[#9f0d18] focus:border-[#191411] focus:outline-none"
                    >
                        <Search className="size-4" />
                        Search flights
                    </Link>
                </div>

                <div className="grid gap-3 md:grid-cols-3">
                    <Stat label="Upcoming" value={ticketStats.upcoming} />
                    <Stat label="Past" value={ticketStats.past} />
                    <Stat label="Total tickets" value={ticketStats.total} />
                </div>

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
        <div className="border border-[#251f1a] bg-white p-4 dark:border-[#efe2d2] dark:bg-[#1c1815]">
            <p className="text-xs font-semibold tracking-[0.16em] text-[#8b8178] uppercase dark:text-[#cfc2b7]">
                {label}
            </p>
            <p className="mt-3 text-3xl font-semibold">{value}</p>
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
        <section className="border border-[#251f1a] bg-white dark:border-[#efe2d2] dark:bg-[#1c1815]">
            <div className="flex items-center justify-between border-b border-[#251f1a] p-4 dark:border-[#efe2d2]">
                <h2 className="text-lg font-semibold">{title}</h2>
                <CalendarDays className="size-5 text-[#c1121f]" />
            </div>

            {tickets.length === 0 ? (
                <div className="flex min-h-36 flex-col items-center justify-center gap-3 p-6 text-center text-sm text-[#665d55] dark:text-[#cfc2b7]">
                    <Plane className="size-7 text-[#c1121f]" />
                    {empty}
                </div>
            ) : (
                <div className="divide-y divide-[#251f1a] dark:divide-[#efe2d2]">
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
        <article className="grid gap-4 p-4 lg:grid-cols-[1.1fr_1fr_auto] lg:items-center">
            <div className="space-y-3">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="text-2xl font-semibold">
                        {ticket.flight.origin.code}
                    </span>
                    <ArrowRight className="size-5 text-[#c1121f]" />
                    <span className="text-2xl font-semibold">
                        {ticket.flight.destination.code}
                    </span>
                    <span className="border border-[#251f1a] px-2 py-1 text-xs font-semibold dark:border-[#efe2d2]">
                        {ticket.fare.class_letters}
                    </span>
                </div>
                <p className="text-sm text-[#665d55] dark:text-[#cfc2b7]">
                    {ticket.flight.origin.name} to{' '}
                    {ticket.flight.destination.name}
                </p>
            </div>

            <div className="grid gap-1 text-sm">
                <p className="font-semibold">
                    {ticket.flight.depart_date} at {ticket.flight.depart_time}
                </p>
                <p className="text-[#665d55] dark:text-[#cfc2b7]">
                    {ticket.flight.flight_number} · {ticket.flight.plane_model}
                </p>
                <p className="text-[#665d55] dark:text-[#cfc2b7]">
                    {ticket.pnr.first_name} {ticket.pnr.last_name} · Passport{' '}
                    {ticket.pnr.passport_number}
                </p>
            </div>

            <div className="grid gap-1 text-sm lg:text-right">
                <p className="font-semibold">
                    {money.format(ticket.fare.base_price_usd)}
                </p>
                <p className="text-[#665d55] capitalize dark:text-[#cfc2b7]">
                    {ticket.fare.fare_type.replace('_', ' ')} ·{' '}
                    {ticket.fare.class}
                </p>
                <p className="text-[#665d55] dark:text-[#cfc2b7]">
                    {ticket.fare.checked_baggage_kg} kg checked ·{' '}
                    {ticket.fare.cabin_baggage_kg} kg cabin ·{' '}
                    {ticket.fare.seat_selection_free ? 'free' : 'paid'} seats
                </p>
            </div>
        </article>
    );
}
