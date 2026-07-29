import type { FormEvent } from 'react';

export type Airport = {
    id: number;
    name: string;
    code: string;
};

export type Fare = {
    id?: number;
    uuid?: string;
    class?: string;
    package_code?: string;
    product?: string;
    class_letters?: string;
    fare_basis_code?: string;
    fare_type?: 'one_way' | 'round_trip';
    leg_index?: number;
    checked_baggage_kg?: number;
    cabin_baggage_kg?: number;
    seat_selection_free?: boolean;
    change_fee_usd?: number;
    refund_fee_usd?: number;
    latest_refund_hours?: number | null;
    latest_change_hours?: number | null;
    base_price_usd?: number;
    per_passenger_price_usd?: number;
    count_available?: number;
    available: boolean;
    services?: {
        code: string;
        name: string;
        category: string;
        value: unknown;
        quantity: number;
        included: boolean;
        price: number;
        source: string;
    }[];
};

export type FlightResult = {
    id: number;
    flight_number: string;
    plane_model: string;
    date: string;
    hour: string;
    duration_minutes: number;
    duration: string;
    arrival_time: string;
    origin: Airport;
    destination: Airport;
    fare_type: 'one_way' | 'round_trip';
    fares: Record<'A' | 'B' | 'C', Fare> | Fare[];
};

export type SearchFilters = {
    origin_airport_id: number | null;
    destination_airport_id: number | null;
    trip_type: 'one_way' | 'round_trip';
    depart_date: string;
    return_date: string;
    search_mode: 'basic' | 'full';
    adults: number;
    children: number;
    babies: number;
};

export type SearchResults = {
    seat_passengers: number;
    outbound: FlightResult[];
    return: FlightResult[];
} | null;

export type CustomerSummary = {
    isAuthenticated: boolean;
    isAdmin: boolean;
    firstName: string;
    lastName: string;
    email: string;
    passportNumber: string;
};

export type PurchaseTarget = {
    flight: FlightResult;
    fare: Fare;
    offerIds: number[];
    totalPrice: number;
};

export type SearchFormSubmit = FormEvent<HTMLFormElement>;
