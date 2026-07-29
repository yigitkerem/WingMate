import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import type {
    CustomerSummary,
    PurchaseTarget,
    SearchFilters,
    SearchFormSubmit,
} from '@/types/flight-search';

type PurchaseModalProps = {
    target: PurchaseTarget;
    filters: SearchFilters;
    customer: CustomerSummary;
    errors: Record<string, string>;
    onClose: () => void;
};

export function PurchaseModal({
    target,
    filters,
    customer,
    errors,
    onClose,
}: PurchaseModalProps) {
    const [buyer, setBuyer] = useState({
        first_name: customer.firstName,
        last_name: customer.lastName,
        email: customer.email,
        passport_number: customer.passportNumber,
    });
    const seatPassengers = filters.adults + filters.children;
    const totalPrice = target.totalPrice;

    function submit(event: SearchFormSubmit) {
        event.preventDefault();

        router.post(
            '/purchase',
            {
                offer_ids: target.offerIds,
                first_name: buyer.first_name,
                last_name: buyer.last_name,
                email: buyer.email,
                passport_number: buyer.passport_number,
                adults: filters.adults,
                children: filters.children,
                babies: filters.babies,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
            },
        );
    }

    return (
        <div className="fixed inset-0 z-[60] grid place-items-center bg-slate-950/60 p-4">
            <form
                onSubmit={submit}
                className="w-full max-w-xl overflow-hidden rounded-md border border-slate-950 bg-white"
            >
                <div className="flex items-start justify-between gap-4 bg-linear-to-br from-red-950 via-red-800 to-red-600 px-5 py-4 text-white">
                    <div>
                        <h2 className="text-xl font-black">Buy ticket</h2>
                        <p className="mt-1 text-sm text-white/80">
                            {target.flight.flight_number} ·{' '}
                            {target.flight.origin.code} to{' '}
                            {target.flight.destination.code} ·{' '}
                            {target.fare.class_letters}
                            {target.offerIds.length === 2
                                ? ' · Round trip'
                                : ''}
                        </p>
                    </div>
                    <button
                        type="button"
                        className="grid size-9 place-items-center rounded-md text-white/80 transition-colors hover:bg-white/10 hover:text-white"
                        onClick={onClose}
                    >
                        <X className="size-5" />
                    </button>
                </div>

                <div className="grid gap-4 p-5 sm:grid-cols-2">
                    <TextField
                        label="First name"
                        value={buyer.first_name}
                        error={errors.first_name}
                        onChange={(value) =>
                            setBuyer((current) => ({
                                ...current,
                                first_name: value,
                            }))
                        }
                    />
                    <TextField
                        label="Last name"
                        value={buyer.last_name}
                        error={errors.last_name}
                        onChange={(value) =>
                            setBuyer((current) => ({
                                ...current,
                                last_name: value,
                            }))
                        }
                    />
                    <TextField
                        label="Email"
                        value={buyer.email}
                        error={errors.email}
                        className="sm:col-span-2"
                        onChange={(value) =>
                            setBuyer((current) => ({
                                ...current,
                                email: value,
                            }))
                        }
                    />
                    <TextField
                        label="Passport number"
                        value={buyer.passport_number}
                        error={errors.passport_number}
                        className="sm:col-span-2"
                        onChange={(value) =>
                            setBuyer((current) => ({
                                ...current,
                                passport_number: value,
                            }))
                        }
                    />

                    <div className="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm sm:col-span-2">
                        <SummaryRow
                            label="Seated passengers"
                            value={String(seatPassengers)}
                        />
                        <SummaryRow
                            label="Selected offers"
                            value={String(target.offerIds.length)}
                        />
                        <div className="mt-3 flex justify-between gap-4 border-t border-slate-200 pt-3 text-base">
                            <span className="font-semibold">Total</span>
                            <span className="font-black">${totalPrice}</span>
                        </div>
                    </div>

                    {errors.offer_ids && (
                        <div className="rounded-md border border-red-700 bg-red-50 p-3 text-sm font-medium text-red-700 sm:col-span-2">
                            {errors.offer_ids}
                        </div>
                    )}

                    <button
                        type="submit"
                        className="h-12 rounded-md bg-red-800 text-sm font-black text-white transition-colors hover:bg-red-900 sm:col-span-2"
                    >
                        Confirm purchase
                    </button>
                </div>
            </form>
        </div>
    );
}

function TextField({
    label,
    value,
    error,
    className = '',
    onChange,
}: {
    label: string;
    value: string;
    error?: string;
    className?: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className={`flex flex-col gap-2 ${className}`}>
            <span className="text-xs font-semibold tracking-normal text-slate-600 uppercase">
                {label}
            </span>
            <input
                className="h-12 rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-950 transition-colors outline-none focus:border-red-800"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
            {error && <span className="text-xs text-red-700">{error}</span>}
        </label>
    );
}

function SummaryRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="mb-2 flex justify-between gap-4 last:mb-0">
            <span>{label}</span>
            <span className="font-semibold">{value}</span>
        </div>
    );
}
