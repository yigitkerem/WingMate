import { router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarRange,
    ChevronDown,
    CircleCheck,
    CircleDollarSign,
    Clock,
    Luggage,
    Maximize2,
    Mic,
    Minimize2,
    Send,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    formatShortDate,
    readStoredWingoSearchPrefill,
    wingoSearchPrefillEvent,
} from '@/lib/flight-search';
import { useTranslation } from '@/lib/i18n';
import type { Locale } from '@/lib/i18n';
import type { WingoSearchPrefill } from '@/lib/flight-search';
import type { CustomerSummary } from '@/types/flight-search';

type ChatMessage = {
    id: string;
    role: 'assistant' | 'user';
    text: string;
    status?: 'pending' | 'failed' | 'sent';
    toolTrace?: ToolTrace[];
};

type ToolTrace = {
    tool: string;
    result: Record<string, unknown>;
};

type GuidedChoice = {
    label: string;
    message: string;
};

type ChatbotMessageReadyEvent = {
    message_id: string;
    reply: string;
    failed: boolean;
    tool_trace: ToolTrace[];
};

type FlightCard = {
    key: string;
    badge: string;
    title: string;
    price: string;
    totalPrice: number;
    offerIds: number[];
    passengers: PassengerCounts;
    route?: string;
    segments: FlightSegment[];
    details: string[];
    featureRows: FeatureRow[];
    packageCode?: string;
    classLetters?: string;
    isPrivate?: boolean;
    isCustom?: boolean;
    highlightPills: string[];
};

type FlightSegment = {
    flightNumber?: string;
    origin?: string;
    destination?: string;
    date?: string;
    hour?: string;
    arrivalHour?: string;
    duration?: string;
};

type PassengerCounts = {
    adults: number;
    children: number;
    babies: number;
};

type FeatureRow = {
    label: string;
    value: string;
    source: string;
};

type CheckoutTarget = FlightCard;

type ChatbotMessageStatusEvent =
    | (ChatbotMessageReadyEvent & { status: 'ready' })
    | { status: 'pending'; message_id: string };

type WingoChatProps = {
    isOpen: boolean;
    onOpen: () => void;
    onClose: () => void;
};

const replyPollDelayMs = 1200;
const replyPollMaxAttempts = 35;

function randomId() {
    return (
        globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`
    );
}

function csrfToken() {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

function storedSessionId() {
    const key = 'wingo-chat-session-id';
    const current = window.localStorage.getItem(key);

    if (current) {
        return current;
    }

    const next = randomId();
    window.localStorage.setItem(key, next);

    return next;
}

function replyStatusUrl(chatSessionId: string, messageId: string) {
    return `/api/message/${encodeURIComponent(chatSessionId)}/${encodeURIComponent(messageId)}`;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function textValue(value: unknown): string | undefined {
    return typeof value === 'string' && value.trim() !== '' ? value : undefined;
}

function numberValue(value: unknown): number | undefined {
    return typeof value === 'number' && Number.isFinite(value)
        ? value
        : undefined;
}

function booleanValue(value: unknown): boolean | undefined {
    return typeof value === 'boolean' ? value : undefined;
}

function formatMoney(value: unknown): string | undefined {
    const amount = numberValue(value);

    if (amount === undefined) {
        return undefined;
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    }).format(amount);
}

function asRecords(value: unknown): Record<string, unknown>[] {
    return Array.isArray(value) ? value.filter(isRecord) : [];
}

function numberList(value: unknown): number[] {
    return Array.isArray(value)
        ? value.filter(
              (item): item is number =>
                  typeof item === 'number' && Number.isInteger(item),
          )
        : [];
}

function stringList(value: unknown): string[] {
    return Array.isArray(value)
        ? value.filter(
              (item): item is string =>
                  typeof item === 'string' && item.trim() !== '',
          )
        : [];
}

function serviceValueLabel(value: unknown): string {
    if (isRecord(value)) {
        if (value.allowed === true && typeof value.window_hours === 'number') {
            const feeAmount = numberValue(value.fee_amount) ?? 0;
            const feeType = textValue(value.fee_type);
            const fee =
                feeType === 'percent'
                    ? `${feeAmount}% fee`
                    : feeAmount === 0
                      ? 'No fee'
                      : `$${feeAmount} fee`;

            return `${fee} until ${value.window_hours}h`;
        }

        if (value.unlimited === true) {
            return 'Unlimited';
        }

        if (typeof value.data_mb === 'number') {
            return value.data_mb >= 1024
                ? `${Math.round(value.data_mb / 1024)} GB`
                : `${value.data_mb} MB`;
        }

        if (typeof value.seat_type === 'string') {
            return value.seat_type === 'exit_row' ? 'Exit row' : 'Standard';
        }

        const amount = value.amount;

        if (amount === null || amount === false || amount === 0) {
            return 'Not included';
        }

        if (typeof amount === 'number' || typeof amount === 'string') {
            return String(amount);
        }

        return Object.values(value).filter(Boolean).join(', ') || 'Included';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'number') {
        return String(value);
    }

    return textValue(value) ?? 'Included';
}

function sourceLabel(value: unknown): string {
    const source = textValue(value);

    if (source === 'customer') {
        return 'Custom choice';
    }

    if (source === 'rule') {
        return 'Rule benefit';
    }

    return 'Package';
}

function serviceIsEnabled(service?: Record<string, unknown>): boolean {
    if (!service) {
        return false;
    }

    const value = service.value;

    if (isRecord(value)) {
        if (typeof value.allowed === 'boolean') {
            return value.allowed;
        }

        if (value.unlimited === true) {
            return true;
        }

        if (
            typeof value.window_hours === 'number' ||
            typeof value.data_mb === 'number' ||
            typeof value.seat_type === 'string'
        ) {
            return true;
        }

        return Number(value.amount ?? 0) > 0;
    }

    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'number') {
        return value > 0;
    }

    return textValue(value) !== undefined;
}

function serviceByCode(
    services: Record<string, unknown>[],
    code: string,
): Record<string, unknown> | undefined {
    return services.find((service) => textValue(service.code) === code);
}

function serviceSource(
    services: Record<string, unknown>[],
    code: string,
): string {
    const service = serviceByCode(services, code);

    if (!service) {
        return 'Not included';
    }

    return sourceLabel(service.source);
}

function serviceAmount(
    services: Record<string, unknown>[],
    code: string,
): number {
    const service = serviceByCode(services, code);

    if (!service) {
        return 0;
    }

    const value = service.value;

    if (isRecord(value)) {
        return Number(value.amount ?? 0);
    }

    return numberValue(value) ?? 0;
}

function featureRowsFromFare(fare: Record<string, unknown>): FeatureRow[] {
    const services = asRecords(fare.services);
    const checkedBag =
        numberValue(fare.checked_baggage_kg) ??
        serviceAmount(services, 'CHECKED_BAG');
    const cabinBag =
        numberValue(fare.cabin_baggage_kg) ??
        serviceAmount(services, 'CABIN_BAG');
    const seatSelection =
        booleanValue(fare.seat_selection_free) ??
        serviceIsEnabled(serviceByCode(services, 'SEAT_SELECTION'));
    const changeFee = numberValue(fare.change_fee_usd);
    const refundFee = numberValue(fare.refund_fee_usd);
    const changeFeePercent = numberValue(fare.change_fee_percent);
    const refundFeePercent = numberValue(fare.refund_fee_percent);
    const changeHours = numberValue(fare.latest_change_hours);
    const refundHours = numberValue(fare.latest_refund_hours);
    const coreCodes = new Set([
        'CHECKED_BAG',
        'CABIN_BAG',
        'SEAT_SELECTION',
        'CHANGE_ALLOWED',
        'CHANGE_FEE',
        'REFUNDABLE',
        'REFUND_FEE',
        'SEAT_STANDARD',
        'SEAT_EXIT_ROW',
    ]);

    const rows: FeatureRow[] = [
        {
            label: 'Checked bag',
            value: checkedBag > 0 ? `${checkedBag} kg` : 'Not included',
            source: serviceSource(services, 'CHECKED_BAG'),
        },
        {
            label: 'Cabin bag',
            value: cabinBag > 0 ? `${cabinBag} kg` : 'Not included',
            source: serviceSource(services, 'CABIN_BAG'),
        },
        {
            label: 'Seat selection',
            value: serviceIsEnabled(serviceByCode(services, 'SEAT_EXIT_ROW'))
                ? 'Exit row'
                : serviceIsEnabled(serviceByCode(services, 'SEAT_STANDARD'))
                  ? 'Standard'
                  : seatSelection
                    ? 'Included'
                    : 'Not included',
            source: serviceSource(services, 'SEAT_SELECTION'),
        },
        {
            label: 'Changes',
            value:
                changeHours !== undefined
                    ? changeFeePercent !== undefined
                        ? `${changeFeePercent}% fee until ${changeHours}h`
                        : changeFee === 0
                          ? `No fee until ${changeHours}h`
                          : `$${changeFee ?? 0} fee until ${changeHours}h`
                    : 'Not included',
            source: serviceSource(services, 'CHANGE_ALLOWED'),
        },
        {
            label: 'Refunds',
            value:
                refundHours !== undefined
                    ? refundFeePercent !== undefined
                        ? `${refundFeePercent}% fee until ${refundHours}h`
                        : refundFee === 0
                          ? `No fee until ${refundHours}h`
                          : `$${refundFee ?? 0} fee until ${refundHours}h`
                    : 'Not included',
            source: serviceSource(services, 'REFUNDABLE'),
        },
    ];

    const extraRows = services
        .filter((service) => {
            const code = textValue(service.code);

            return code !== undefined && !coreCodes.has(code);
        })
        .map((service) => ({
            label:
                textValue(service.name) ?? textValue(service.code) ?? 'Service',
            value: serviceIsEnabled(service)
                ? serviceValueLabel(service.value)
                : 'Not included',
            source: sourceLabel(service.source),
        }));

    return [...rows, ...extraRows];
}

function formatFlightDate(value: string | undefined, locale: Locale): string {
    if (!value) {
        return locale === 'tr' ? 'tarih' : 'date';
    }

    try {
        return formatShortDate(value, locale);
    } catch {
        return value;
    }
}

function customerFirstName(customer?: CustomerSummary): string | undefined {
    return customer?.firstName.trim() || undefined;
}

function cardTitle(
    card: FlightCard,
    customer?: CustomerSummary,
    locale: Locale = 'en',
): string {
    if (!card.isCustom) {
        return card.title;
    }

    const firstName = customerFirstName(customer);

    if (locale === 'tr') {
        return firstName ? `${firstName} için özel teklif` : 'Özel teklif';
    }

    return firstName ? `${firstName}'s custom offer` : 'Custom offer';
}

function routeDestination(card: FlightCard, locale: Locale): string {
    const lastSegment = card.segments[card.segments.length - 1];

    return lastSegment?.destination ?? (locale === 'tr' ? 'seyahatiniz' : 'your trip');
}

function detailIncludes(card: FlightCard, pattern: RegExp): boolean {
    return card.details.some((detail) => pattern.test(detail));
}

function bridgeSuggestion(
    first: FlightCard,
    second: FlightCard,
    locale: Locale,
): string {
    const destination = routeDestination(first, locale);

    if (
        detailIncludes(second, /^\d+ kg checked bag/i) &&
        detailIncludes(first, /no checked bag/i)
    ) {
        if (locale === 'tr') {
            return `${destination} için daha fazla alan istersen kayıtlı bagaj ekleyebiliriz.`;
        }

        return `Or, we can add checked bags so you have more room for ${destination}.`;
    }

    if (
        detailIncludes(second, /^(free change|change allowed)/i) &&
        detailIncludes(first, /no change/i)
    ) {
        if (locale === 'tr') {
            return 'Planınız değişebilirse değişiklik esnekliği ekleyebiliriz.';
        }

        return 'Or, we can add change flexibility so the plan can move with you.';
    }

    if (
        detailIncludes(second, /^(free refund|refundable)/i) &&
        detailIncludes(first, /no refund/i)
    ) {
        if (locale === 'tr') {
            return 'Daha yumuşak bir güvence istersen iade esnekliği ekleyebiliriz.';
        }

        return 'Or, we can add refund flexibility if you want a softer fallback.';
    }

    if (locale === 'tr') {
        return `Seyahatinizin ihtiyacına göre ${destination} için daha konforlu bir seçenek de hazırlayabiliriz.`;
    }

    return `Or, we can tune this with more comfort for ${destination} if the trip needs it.`;
}

function translateFareCardText(value: string, locale: Locale): string {
    if (locale !== 'tr') {
        return value;
    }

    const normalized = value.trim().toLowerCase();
    const checkedBag = /^(\d+)\s*kg\s+checked bag$/i.exec(value);
    const cabinBag = /^(\d+)\s*kg\s+cabin bag$/i.exec(value);
    const percentChange = /^(\d+)% change fee$/i.exec(value);
    const percentRefund = /^(\d+)% refund fee$/i.exec(value);
    const changeFee = /^change allowed, \$(\d+(?:\.\d+)?) fee$/i.exec(value);
    const refundFee = /^refundable, \$(\d+(?:\.\d+)?) fee$/i.exec(value);
    const feeUntil = /^(\$?\d+(?:\.\d+)?%?) fee until (\d+)h$/i.exec(value);
    const noFeeUntil = /^no fee until (\d+)h$/i.exec(value);

    if (checkedBag) {
        return `${checkedBag[1]} kg kayıtlı bagaj`;
    }

    if (cabinBag) {
        return `${cabinBag[1]} kg kabin bagajı`;
    }

    if (percentChange) {
        return `%${percentChange[1]} değişiklik ücreti`;
    }

    if (percentRefund) {
        return `%${percentRefund[1]} iade ücreti`;
    }

    if (changeFee) {
        return `Değişiklik yapılabilir, $${changeFee[1]} ücret`;
    }

    if (refundFee) {
        return `İade edilebilir, $${refundFee[1]} ücret`;
    }

    if (feeUntil) {
        return `${feeUntil[1]} ücret, ${feeUntil[2]} saate kadar`;
    }

    if (noFeeUntil) {
        return `${noFeeUntil[1]} saate kadar ücretsiz`;
    }

    const translations: Record<string, string> = {
        'lowest fare': 'En düşük ücret',
        'bag included': 'Bagaj dahil',
        'flexible fare': 'Esnek ücret',
        'custom offer': 'Özel teklif',
        option: 'Seçenek',
        refundable: 'İade edilebilir',
        changeable: 'Değiştirilebilir',
        'no checked bag': 'Kayıtlı bagaj yok',
        'no change allowed': 'Değişiklik yapılamaz',
        'no refund allowed': 'İade yapılamaz',
        'seat selection paid': 'Koltuk seçimi ücretli',
        'seat selection included': 'Koltuk seçimi dahil',
        'checked bag': 'Kayıtlı bagaj',
        'cabin bag': 'Kabin bagajı',
        'seat selection': 'Koltuk seçimi',
        changes: 'Değişiklikler',
        refunds: 'İadeler',
        included: 'Dahil',
        'not included': 'Dahil değil',
        standard: 'Standart',
        'exit row': 'Acil çıkış sırası',
        unlimited: 'Sınırsız',
        yes: 'Evet',
        no: 'Hayır',
        package: 'Paket',
        'custom choice': 'Özel seçim',
        'rule benefit': 'Kural avantajı',
        service: 'Hizmet',
        'free change': 'Ücretsiz değişiklik',
        'free refund': 'Ücretsiz iade',
    };

    return translations[normalized] ?? value;
}

function messageWithSearchPrefill(
    message: string,
    prefill: WingoSearchPrefill | null,
): string {
    if (
        !shouldAttachSearchPrefill(message) ||
        !prefill?.origin ||
        !prefill.destination ||
        !prefill.date
    ) {
        return message;
    }

    const returnText =
        prefill.tripType === 'round_trip' && prefill.returnDate
            ? ` Return on ${prefill.returnDate}.`
            : '';
    const passengerText = [
        `adults ${prefill.adults ?? 1}`,
        `children ${prefill.children ?? 0}`,
        `babies ${prefill.babies ?? 0}`,
    ].join(', ');

    const messageWithContext = `${message}\n\nCurrent search form: from ${prefill.origin} to ${prefill.destination} on ${prefill.date}.${returnText} Passengers: ${passengerText}. Use this prefill only for flight search, fare recommendation, bundle building, checkout, or purchase questions. Ignore the prefill for policy, passenger rights, cancellation, compensation, refund-rule, baggage-rule, or other knowledge-base questions.`;

    return messageWithContext.length <= 2000 ? messageWithContext : message;
}

function shouldAttachSearchPrefill(message: string): boolean {
    const normalized = message.toLowerCase();

    if (
        /\b(what|which|how|when|where|why|can|do|does|are|is)\b.*\b(rights?|rules?|policy|policies|cancel(?:led|lation)?|refunds?|compensation|allowance)\b/.test(
            normalized,
        ) ||
        [
            'passenger rights',
            'refund rule',
            'refund policy',
            'cancellation rule',
            'cancellation policy',
            'compensation',
            'baggage allowance',
            'bag rule',
            'what happens',
            'what are my',
        ].some((keyword) => normalized.includes(keyword))
    ) {
        return false;
    }

    return [
        'flight',
        'fare',
        'ticket',
        'trip',
        'fly',
        'route',
        'price',
        'cheap',
        'lowest',
        'comfort',
        'bundle',
        'package',
        'bag',
        'seat',
        'change',
        'flex',
        'lounge',
        'fast track',
        'wi-fi',
        'wifi',
    ].some((keyword) => normalized.includes(keyword));
}

function guidedChoicesFromTrace(toolTrace?: ToolTrace[]): GuidedChoice[] {
    const trace = toolTrace?.find((item) => item.tool === 'guided_choices');
    const choices = trace?.result.choices;

    if (!Array.isArray(choices)) {
        return [];
    }

    return choices
        .filter(isRecord)
        .map((choice) => ({
            label: textValue(choice.label) ?? '',
            message: textValue(choice.message) ?? '',
        }))
        .filter((choice) => choice.label !== '' && choice.message !== '');
}

function formatInline(text: string): ReactNode[] {
    const nodes: ReactNode[] = [];
    const pattern = /\*\*([^*]+)\*\*/g;
    let lastIndex = 0;
    let match: RegExpExecArray | null;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > lastIndex) {
            nodes.push(text.slice(lastIndex, match.index));
        }

        nodes.push(
            <strong key={`${match.index}-${match[1]}`} className="font-bold">
                {match[1]}
            </strong>,
        );
        lastIndex = pattern.lastIndex;
    }

    if (lastIndex < text.length) {
        nodes.push(text.slice(lastIndex));
    }

    return nodes;
}

function passengerCountsFromTrace(
    result: Record<string, unknown>,
): PassengerCounts {
    const passengers = isRecord(result.passengers) ? result.passengers : {};

    return {
        adults: numberValue(passengers.adults) ?? 1,
        children: numberValue(passengers.children) ?? 0,
        babies: numberValue(passengers.babies) ?? 0,
    };
}

function segmentFromRecord(segment: Record<string, unknown>): FlightSegment {
    return {
        flightNumber: textValue(segment.flight_number),
        origin: textValue(segment.origin),
        destination: textValue(segment.destination),
        date: textValue(segment.date),
        hour: textValue(segment.hour),
        arrivalHour:
            textValue(segment.arrival_hour) ?? textValue(segment.arrival_time),
        duration:
            textValue(segment.duration_str) ?? textValue(segment.duration),
    };
}

function fallbackSegment(flight: Record<string, unknown>): FlightSegment {
    return {
        flightNumber: textValue(flight.flight_number),
        origin: textValue(flight.origin),
        destination: textValue(flight.destination),
        date: textValue(flight.date),
        hour: textValue(flight.hour),
        arrivalHour: textValue(flight.arrival_hour),
        duration: textValue(flight.duration_str),
    };
}

function routeLabel(segments: FlightSegment[]) {
    const first = segments[0];
    const last = segments[segments.length - 1];

    if (!first) {
        return undefined;
    }

    if (segments.length > 1) {
        return [first.origin, first.destination, last.destination]
            .filter(Boolean)
            .join(' to ');
    }

    return [first.origin, first.destination].filter(Boolean).join(' to ');
}

function fareCheckedBagKg(fare: Record<string, unknown>): number {
    return (
        numberValue(fare.checked_baggage_kg) ??
        serviceAmount(asRecords(fare.services), 'CHECKED_BAG')
    );
}

function fareCabinBagKg(fare: Record<string, unknown>): number {
    return (
        numberValue(fare.cabin_baggage_kg) ??
        serviceAmount(asRecords(fare.services), 'CABIN_BAG')
    );
}

function fareHasSeatSelection(fare: Record<string, unknown>): boolean {
    const services = asRecords(fare.services);
    const seatSelection = booleanValue(fare.seat_selection_free);

    return (
        seatSelection ??
        (serviceIsEnabled(serviceByCode(services, 'SEAT_SELECTION')) ||
            serviceIsEnabled(serviceByCode(services, 'SEAT_STANDARD')) ||
            serviceIsEnabled(serviceByCode(services, 'SEAT_EXIT_ROW')))
    );
}

function fareHasChange(fare: Record<string, unknown>): boolean {
    return (
        fare.latest_change_hours !== null &&
        fare.latest_change_hours !== undefined
    );
}

function fareHasRefund(fare: Record<string, unknown>): boolean {
    return (
        fare.latest_refund_hours !== null &&
        fare.latest_refund_hours !== undefined
    );
}

function fareHasWifi(fare: Record<string, unknown>): boolean {
    const services = asRecords(fare.services);

    return ['WIFI', 'WIFI_1GB', 'WIFI_5GB', 'WIFI_UNLIMITED'].some((code) =>
        serviceIsEnabled(serviceByCode(services, code)),
    );
}

function highlightMatchesFare(
    fare: Record<string, unknown>,
    highlight: string,
): boolean {
    const normalized = highlight.toLowerCase();

    if (normalized.includes('checked') || normalized.includes('baggage')) {
        return fareCheckedBagKg(fare) > 0;
    }

    if (normalized.includes('cabin')) {
        return fareCabinBagKg(fare) > 0;
    }

    if (normalized.includes('seat')) {
        return fareHasSeatSelection(fare);
    }

    if (normalized.includes('refund')) {
        return fareHasRefund(fare);
    }

    if (normalized.includes('change')) {
        return fareHasChange(fare);
    }

    if (normalized.includes('flex')) {
        return fareHasChange(fare) || fareHasRefund(fare);
    }

    if (normalized.includes('wi-fi') || normalized.includes('wifi')) {
        return fareHasWifi(fare);
    }

    return true;
}

function actualHighlightPills(fare: Record<string, unknown>): string[] {
    if (booleanValue(fare.customized) === true) {
        return ['Custom offer'];
    }

    const checkedBag = fareCheckedBagKg(fare);
    const cabinBag = fareCabinBagKg(fare);

    if (checkedBag > 0) {
        return [`${checkedBag} kg checked bag`];
    }

    if (fareHasRefund(fare)) {
        return ['Refundable'];
    }

    if (fareHasChange(fare)) {
        return ['Changeable'];
    }

    if (cabinBag > 0) {
        return [`${cabinBag} kg cabin bag`];
    }

    return ['Lowest fare'];
}

function highlightPillsForFare(
    fare: Record<string, unknown>,
    highlights: string[],
): string[] {
    const filtered = highlights.filter((highlight) =>
        highlightMatchesFare(fare, highlight),
    );

    return filtered.length > 0 ? filtered : actualHighlightPills(fare);
}

function badgeForFare(fare: Record<string, unknown>, index: number): string {
    if (booleanValue(fare.customized) === true) {
        return 'Custom offer';
    }

    if (index === 0) {
        return 'Lowest fare';
    }

    if (fareCheckedBagKg(fare) > 0) {
        return 'Bag included';
    }

    if (fareHasRefund(fare) || fareHasChange(fare)) {
        return 'Flexible fare';
    }

    return 'Option';
}

function fareDetails(fare: Record<string, unknown>): string[] {
    const checkedBag = fareCheckedBagKg(fare);
    const cabinBag = fareCabinBagKg(fare);
    const seatSelection = fareHasSeatSelection(fare);
    const changeFee = numberValue(fare.change_fee_usd);
    const refundFee = numberValue(fare.refund_fee_usd);
    const changeFeePercent = numberValue(fare.change_fee_percent);
    const refundFeePercent = numberValue(fare.refund_fee_percent);
    const changeable = fareHasChange(fare);
    const refundable = fareHasRefund(fare);

    return [
        checkedBag > 0 ? `${checkedBag} kg checked bag` : 'No checked bag',
        cabinBag > 0 ? `${cabinBag} kg cabin bag` : undefined,
        seatSelection === true
            ? 'Seat selection included'
            : 'Seat selection paid',
        changeable
            ? changeFeePercent !== undefined
                ? `${changeFeePercent}% change fee`
                : changeFee === 0
                  ? 'Free change'
                  : `Change allowed, $${changeFee} fee`
            : 'No change allowed',
        refundable
            ? refundFeePercent !== undefined
                ? `${refundFeePercent}% refund fee`
                : refundFee === 0
                  ? 'Free refund'
                  : `Refundable, $${refundFee} fee`
            : 'No refund allowed',
    ].filter((detail): detail is string => Boolean(detail));
}

function flightCardsFromTrace(toolTrace?: ToolTrace[]): FlightCard[] {
    if (!toolTrace) {
        return [];
    }

    const bundleCards = toolTrace.flatMap((trace) => {
        if (trace.tool !== 'build_dynamic_bundles') {
            return [];
        }

        const flight = isRecord(trace.result.flight) ? trace.result.flight : {};
        const passengers = passengerCountsFromTrace(trace.result);

        return asRecords(trace.result.recommended_picks).map((pick, index) => {
            const segments = asRecords(pick.segments).map(segmentFromRecord);
            const fallback = fallbackSegment(flight);
            const finalSegments = segments.length > 0 ? segments : [fallback];
            const totalPrice = numberValue(pick.base_price_usd) ?? 0;
            const offerIds = numberList(pick.offer_ids);
            const fallbackOfferId = numberValue(pick.id);

            return {
                key: `bundle-${textValue(flight.flight_number) ?? 'flight'}-${textValue(pick.uuid) ?? index}`,
                badge: badgeForFare(pick, index),
                title: textValue(pick.class) ?? 'Recommended package',
                price: formatMoney(totalPrice) ?? '$0',
                totalPrice,
                offerIds:
                    offerIds.length > 0
                        ? offerIds
                        : fallbackOfferId !== undefined
                          ? [fallbackOfferId]
                          : [],
                passengers,
                route: routeLabel(finalSegments),
                segments: finalSegments,
                details: fareDetails(pick),
                featureRows: featureRowsFromFare(pick),
                packageCode: textValue(pick.package_code),
                classLetters: textValue(pick.class_letters),
                isPrivate: booleanValue(pick.public) === false,
                isCustom: booleanValue(pick.customized) === true,
                highlightPills: highlightPillsForFare(
                    pick,
                    stringList(pick.highlight_pills),
                ),
            };
        });
    });

    if (bundleCards.length > 0) {
        return bundleCards.slice(0, 2);
    }

    return toolTrace
        .flatMap((trace) => {
            if (trace.tool !== 'search_flights') {
                return [];
            }

            return asRecords(trace.result.flights).flatMap((flight, index) => {
                const fares = asRecords(flight.fares);
                const fare = fares[0];

                if (!fare) {
                    return [];
                }

                const priceBreakdown = isRecord(fare.price_breakdown)
                    ? fare.price_breakdown
                    : {};
                const route = [
                    textValue(flight.origin),
                    textValue(flight.destination),
                ]
                    .filter(Boolean)
                    .join(' to ');
                const offerId = numberValue(fare.id);

                return [
                    {
                        key: `flight-${textValue(flight.flight_number) ?? index}-${textValue(fare.class) ?? 'fare'}`,
                        badge: badgeForFare(fare, index),
                        title: textValue(fare.class) ?? 'Available fare',
                        price:
                            formatMoney(priceBreakdown.grand_total_usd) ??
                            formatMoney(fare.base_price_usd) ??
                            '$0',
                        totalPrice:
                            numberValue(priceBreakdown.grand_total_usd) ??
                            numberValue(fare.base_price_usd) ??
                            0,
                        offerIds: offerId !== undefined ? [offerId] : [],
                        passengers: passengerCountsFromTrace(trace.result),
                        route,
                        segments: [
                            {
                                flightNumber: textValue(flight.flight_number),
                                origin: textValue(flight.origin),
                                destination: textValue(flight.destination),
                                date: textValue(flight.date),
                                hour: textValue(flight.hour),
                                arrivalHour: textValue(flight.arrival_hour),
                                duration: textValue(flight.duration_str),
                            },
                        ],
                        details: fareDetails(fare),
                        featureRows: featureRowsFromFare(fare),
                        packageCode: textValue(fare.package_code),
                        classLetters: textValue(fare.class_letters),
                        isPrivate: booleanValue(fare.public) === false,
                        isCustom: booleanValue(fare.customized) === true,
                        highlightPills: highlightPillsForFare(
                            fare,
                            stringList(fare.highlight_pills),
                        ),
                    },
                ];
            });
        })
        .slice(0, 2);
}

export function WingoChat({ isOpen, onOpen, onClose }: WingoChatProps) {
    const { props } = usePage<{ customer?: CustomerSummary }>();
    const { locale, t } = useTranslation();
    const customer = props.customer;
    const [input, setInput] = useState('');
    const [searchPrefill, setSearchPrefill] =
        useState<WingoSearchPrefill | null>(() =>
            readStoredWingoSearchPrefill(),
        );
    const [checkoutTarget, setCheckoutTarget] = useState<CheckoutTarget | null>(
        null,
    );
    const [isSending, setIsSending] = useState(false);
    const [isListening, setIsListening] = useState(false);
    const [isExpanded, setIsExpanded] = useState(false);
    const sessionId = useRef<string | null>(null);
    const scrollRef = useRef<HTMLDivElement | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([
        {
            id: 'welcome',
            role: 'assistant',
            text: t('chat.welcome'),
            status: 'sent',
        },
    ]);
    const suggestions = [
        t('chat.suggestions.help'),
        t('chat.suggestions.flexibility'),
        t('chat.suggestions.airports'),
        t('chat.suggestions.cancelled'),
    ];

    const applyAssistantReply = useCallback(
        (event: ChatbotMessageReadyEvent) => {
            setMessages((current) =>
                current.map((message) =>
                    message.id === event.message_id
                        ? {
                              ...message,
                              text: event.reply,
                              toolTrace: event.tool_trace,
                              status: event.failed ? 'failed' : 'sent',
                          }
                        : message,
                ),
            );
            setIsSending(false);
        },
        [],
    );

    const pollForReply = useCallback(
        (chatSessionId: string, messageId: string) => {
            let attempt = 0;

            async function poll(): Promise<void> {
                try {
                    const response = await fetch(
                        replyStatusUrl(chatSessionId, messageId),
                        {
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' },
                        },
                    );

                    if (response.ok) {
                        const event =
                            (await response.json()) as ChatbotMessageStatusEvent;

                        if (event.status === 'ready') {
                            applyAssistantReply(event);

                            return;
                        }
                    }
                } catch {
                    // Reverb may still deliver the message; keep polling briefly.
                }

                if (attempt < replyPollMaxAttempts) {
                    attempt += 1;
                    window.setTimeout(poll, replyPollDelayMs);

                    return;
                }

                setMessages((current) =>
                    current.map((message) =>
                        message.id === messageId && message.status === 'pending'
                            ? {
                                  ...message,
                                  text: t('chat.waiting'),
                                  status: 'failed',
                              }
                            : message,
                    ),
                );
                setIsSending(false);
            }

            window.setTimeout(poll, replyPollDelayMs);
        },
        [applyAssistantReply, t],
    );

    useEffect(() => {
        sessionId.current = storedSessionId();

        if (!window.Echo) {
            return;
        }

        const channelName = `wingo-chat.${sessionId.current}`;

        window.Echo.channel(channelName).listen(
            '.ChatbotMessageReady',
            applyAssistantReply,
        );

        return () => {
            window.Echo.leave(channelName);
        };
    }, [applyAssistantReply]);

    useEffect(() => {
        scrollRef.current?.scrollTo({
            top: scrollRef.current.scrollHeight,
            behavior: 'smooth',
        });
    }, [messages, isOpen]);

    async function sendMessage(text: string) {
        const trimmed = text.trim();

        if (!trimmed || isSending) {
            return;
        }

        const assistantMessageId = randomId();
        const chatSessionId = sessionId.current ?? storedSessionId();
        sessionId.current = chatSessionId;

        setMessages((current) => [
            ...current,
            {
                id: randomId(),
                role: 'user',
                text: trimmed,
                status: 'sent',
            },
            {
                id: assistantMessageId,
                role: 'assistant',
                text: t('chat.pending'),
                status: 'pending',
            },
        ]);
        setInput('');
        setIsSending(true);

        try {
            const assistantMessage = messageWithSearchPrefill(
                trimmed,
                searchPrefill,
            );
            const response = await fetch('/api/message', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    session_id: chatSessionId,
                    message_id: assistantMessageId,
                    message: assistantMessage,
                    source: 'ours',
                    language: locale,
                }),
            });

            if (!response.ok) {
                throw new Error('Chat request failed.');
            }

            pollForReply(chatSessionId, assistantMessageId);
        } catch {
            setMessages((current) =>
                current.map((message) =>
                    message.id === assistantMessageId
                        ? {
                              ...message,
                              text: t('chat.failed'),
                              status: 'failed',
                          }
                        : message,
                ),
            );
            setIsSending(false);
        }
    }

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        function handleSearchPrefill(event: Event) {
            const prefill = (event as CustomEvent<WingoSearchPrefill>).detail;

            setSearchPrefill(prefill);
        }

        window.addEventListener(wingoSearchPrefillEvent, handleSearchPrefill);

        return () => {
            window.removeEventListener(
                wingoSearchPrefillEvent,
                handleSearchPrefill,
            );
        };
    }, []);

    function startVoiceInput() {
        const Recognition =
            window.SpeechRecognition ?? window.webkitSpeechRecognition;

        if (!Recognition || isListening) {
            return;
        }

        const recognition = new Recognition();
        recognition.lang = locale === 'tr' ? 'tr-TR' : 'en-US';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        recognition.onstart = () => setIsListening(true);
        recognition.onend = () => setIsListening(false);
        recognition.onerror = () => setIsListening(false);
        recognition.onresult = (event) => {
            const transcript = event.results[0]?.[0]?.transcript ?? '';
            setInput((current) => `${current} ${transcript}`.trim());
        };
        recognition.start();
    }

    function handleGuidedChoice(choice: GuidedChoice) {
        sendMessage(choice.message);
    }

    function closeWingoChat() {
        setIsExpanded(false);
        onClose();
    }

    const hasUserPrompt = messages.some((message) => message.role === 'user');
    const panelSizeClasses = isExpanded ? 'w-full' : 'w-full sm:w-[600px]';

    return (
        <>
            <button
                type="button"
                className="fixed right-6 bottom-6 z-40 flex size-16 items-center justify-center overflow-hidden rounded-md border-2 border-red-700 bg-white transition-transform hover:scale-105"
                onClick={onOpen}
                aria-label={t('chat.open')}
            >
                <video
                    src="/assets/wingo.mp4"
                    className="h-full w-full object-cover"
                    autoPlay
                    loop
                    muted
                    playsInline
                    aria-hidden="true"
                />
            </button>

            {isOpen && (
                <button
                    type="button"
                    className="fixed inset-0 z-[45] bg-slate-950/45 backdrop-blur-[1px]"
                    aria-label={t('chat.close')}
                    onClick={closeWingoChat}
                />
            )}

            <aside
                className={`fixed top-0 right-0 bottom-0 z-50 flex flex-col bg-white transition-[width,transform,box-shadow] duration-500 ease-out ${panelSizeClasses} ${
                    isOpen ? 'translate-x-0' : 'translate-x-full'
                }`}
                aria-hidden={!isOpen}
            >
                <ChatHeader
                    isExpanded={isExpanded}
                    onClose={closeWingoChat}
                    onToggleExpanded={() => {
                        setIsExpanded((current) => !current);
                    }}
                />

                <div
                    ref={scrollRef}
                    className="flex-1 space-y-4 overflow-y-auto bg-white p-5 text-sm"
                >
                    <div className="flex flex-col items-center pt-1 pb-1">
                        <video
                            src="/assets/wingo.mp4"
                            className="h-40 w-40 rounded-md object-cover"
                            autoPlay
                            loop
                            muted
                            playsInline
                            aria-label="Wingo"
                        />
                        <div className="mt-1 font-rounded text-lg font-black text-red-800">
                            Wingo
                        </div>
                    </div>

                    {messages.map((message) => (
                        <div
                            key={message.id}
                            className={
                                message.role === 'user'
                                    ? 'ml-10 rounded-md bg-linear-to-br from-red-700 to-red-950 p-4 text-white'
                                    : 'mr-6 rounded-md border border-slate-200 bg-white p-4 text-slate-700'
                            }
                        >
                            {message.role === 'assistant' && (
                                <div className="mb-1 font-rounded font-bold text-red-800">
                                    Wingo
                                </div>
                            )}
                            <RichMessage
                                text={message.text}
                                isPending={message.status === 'pending'}
                            />
                            {message.role === 'assistant' && (
                                <GuidedChoiceButtons
                                    choices={guidedChoicesFromTrace(
                                        message.toolTrace,
                                    )}
                                    disabled={isSending}
                                    onSelect={handleGuidedChoice}
                                />
                            )}
                            {message.role === 'assistant' && (
                                <FlightSuggestionCards
                                    toolTrace={message.toolTrace}
                                    customer={customer}
                                    onSelect={setCheckoutTarget}
                                />
                            )}
                        </div>
                    ))}

                    {!hasUserPrompt && (
                        <div className="mr-6 rounded-md border border-slate-200 bg-white p-4">
                            <div className="mb-2 font-bold text-red-800">
                                {t('chat.tryAsking')}
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {suggestions.map((suggestion) => (
                                    <button
                                        type="button"
                                        key={suggestion}
                                        className="rounded-md bg-red-50 px-2.5 py-1 text-[11px] font-bold text-red-800 transition-opacity hover:opacity-80"
                                        onClick={() => sendMessage(suggestion)}
                                    >
                                        {suggestion}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                <div className="border-t border-slate-200 bg-white p-4">
                    <form
                        className="flex gap-2.5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            sendMessage(input);
                        }}
                    >
                        <input
                            className="flex-1 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm transition-colors outline-none focus:border-red-800 focus:bg-white"
                            placeholder={t('chat.typeMessage')}
                            value={input}
                            onChange={(event) => setInput(event.target.value)}
                        />
                        <button
                            type="button"
                            title={
                                isListening
                                    ? t('chat.listening')
                                    : t('chat.voiceInput')
                            }
                            className={`hidden w-12 shrink-0 items-center justify-center rounded-md border transition-colors sm:inline-flex ${
                                isListening
                                    ? 'border-red-800 bg-red-50 text-red-800'
                                    : 'border-slate-200 bg-white text-slate-500 hover:border-red-800 hover:text-red-800'
                            }`}
                            onClick={startVoiceInput}
                        >
                            <Mic className="size-5" />
                        </button>
                        <button
                            className="inline-flex items-center justify-center rounded-md bg-red-800 px-5 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-60"
                            disabled={isSending}
                        >
                            <Send className="size-5" />
                        </button>
                    </form>
                    <div className="mt-2 px-1 text-[10px] font-medium text-slate-400">
                        {t('chat.disclaimer')}
                    </div>
                </div>
            </aside>
            {checkoutTarget && (
                <WingoCheckoutModal
                    target={checkoutTarget}
                    customer={customer}
                    onClose={() => setCheckoutTarget(null)}
                />
            )}
        </>
    );
}

function GuidedChoiceButtons({
    choices,
    disabled,
    onSelect,
}: {
    choices: GuidedChoice[];
    disabled: boolean;
    onSelect: (choice: GuidedChoice) => void;
}) {
    if (choices.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 grid gap-2 sm:grid-cols-2">
            {choices.map((choice) => (
                <button
                    key={`${choice.label}-${choice.message}`}
                    type="button"
                    className="min-h-10 rounded-md border border-red-100 bg-red-50 px-3 py-2 text-left text-xs font-black text-red-800 transition-all duration-200 hover:-translate-y-px hover:border-red-200 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={disabled}
                    onClick={() => onSelect(choice)}
                >
                    {choice.label}
                </button>
            ))}
        </div>
    );
}

function RichMessage({
    text,
    isPending,
}: {
    text: string;
    isPending: boolean;
}) {
    const lines = text.split(/\r?\n/);
    const blocks: ReactNode[] = [];
    let bulletItems: string[] = [];

    function flushBullets() {
        if (bulletItems.length === 0) {
            return;
        }

        const items = bulletItems;
        bulletItems = [];
        blocks.push(
            <ul
                key={`list-${blocks.length}`}
                className="space-y-1 pl-4 text-current"
            >
                {items.map((item, index) => (
                    <li key={`${item}-${index}`} className="list-disc">
                        {formatInline(item)}
                    </li>
                ))}
            </ul>,
        );
    }

    lines.forEach((line) => {
        const trimmed = line.trim();

        if (trimmed === '') {
            flushBullets();

            return;
        }

        const bullet = /^[-*]\s+(.+)$/.exec(trimmed);

        if (bullet) {
            bulletItems.push(bullet[1]);

            return;
        }

        flushBullets();
        blocks.push(
            <p key={`paragraph-${blocks.length}`} className="text-current">
                {formatInline(trimmed.replace(/^#{1,3}\s+/, ''))}
            </p>,
        );
    });

    flushBullets();

    return (
        <div className={`space-y-2 ${isPending ? 'animate-pulse' : ''}`}>
            {blocks.length > 0 ? blocks : <p>{text}</p>}
        </div>
    );
}

function FlightSuggestionCards({
    toolTrace,
    customer,
    onSelect,
}: {
    toolTrace?: ToolTrace[];
    customer?: CustomerSummary;
    onSelect: (target: CheckoutTarget) => void;
}) {
    const { locale, t } = useTranslation();
    const [expandedKey, setExpandedKey] = useState<string | null>(null);
    const cards = flightCardsFromTrace(toolTrace);

    if (cards.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 grid gap-3">
            {cards.map((card, index) => {
                const isExpanded = expandedKey === card.key;

                return (
                    <div key={card.key} className="grid gap-3">
                        {index === 1 && (
                            <p className="animate-in px-1 text-xs leading-5 font-medium text-slate-600 duration-300 fade-in-50 slide-in-from-bottom-1">
                                {bridgeSuggestion(cards[0], card, locale)}
                            </p>
                        )}
                        <article
                            className="animate-in overflow-hidden rounded-md border border-slate-200 bg-white text-slate-950 shadow-sm transition-all duration-300 fade-in-50 slide-in-from-bottom-1 hover:-translate-y-0.5 hover:border-red-200 hover:shadow-md"
                            style={{ animationDelay: `${index * 70}ms` }}
                        >
                            <button
                                type="button"
                                className="block w-full text-left"
                                aria-expanded={isExpanded}
                                onClick={() =>
                                    setExpandedKey(isExpanded ? null : card.key)
                                }
                            >
                                <FlightDisplay
                                    card={card}
                                    customer={customer}
                                    expanded={isExpanded}
                                />
                            </button>

                            <div
                                className={`grid transition-all duration-300 ${
                                    isExpanded
                                        ? 'grid-rows-[1fr] opacity-100'
                                        : 'grid-rows-[0fr] opacity-0'
                                }`}
                            >
                                <div className="overflow-hidden">
                                    <div className="animate-in border-t border-slate-200 bg-slate-50/80 p-3 duration-300 fade-in-50 slide-in-from-top-1">
                                        <FeatureTable
                                            featureRows={card.featureRows}
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-3 py-3">
                                <div className="flex min-w-0 flex-wrap gap-1.5">
                                    {card.details.slice(0, 3).map((detail) => (
                                        <span
                                            key={detail}
                                            className="rounded-md border border-red-100 bg-red-50 px-2 py-1 text-[10px] font-bold text-red-800 transition-colors"
                                        >
                                            {translateFareCardText(
                                                detail,
                                                locale,
                                            )}
                                        </span>
                                    ))}
                                </div>
                                <button
                                    type="button"
                                    className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-red-800 px-3 text-xs font-black text-white transition-all duration-200 hover:-translate-y-px hover:bg-red-900 hover:shadow-sm active:translate-y-0"
                                    onClick={() => onSelect(card)}
                                >
                                    {t('chat.checkout')}
                                    <ArrowRight className="size-3.5" />
                                </button>
                            </div>
                        </article>
                    </div>
                );
            })}
        </div>
    );
}

function FlightDisplay({
    card,
    customer,
    expanded,
}: {
    card: FlightCard;
    customer?: CustomerSummary;
    expanded: boolean;
}) {
    const { locale, t } = useTranslation();
    const firstSegment = card.segments[0];
    const lastSegment = card.segments[card.segments.length - 1] ?? firstSegment;

    return (
        <div className="grid gap-3 p-3">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="rounded-md bg-red-800 px-2 py-1 font-condensed text-[10px] font-black tracking-normal text-white uppercase">
                            {translateFareCardText(card.badge, locale)}
                        </span>
                        {card.isCustom && (
                            <span className="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-[10px] font-black text-emerald-700">
                                {t('chat.custom')}
                            </span>
                        )}
                        {card.isPrivate && (
                            <span className="rounded-md border border-slate-200 bg-slate-100 px-2 py-1 text-[10px] font-black text-slate-700">
                                {t('chat.privateOffer')}
                            </span>
                        )}
                        {card.highlightPills.map((highlight) => (
                            <span
                                key={highlight}
                                className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[10px] font-black text-amber-800"
                            >
                                {translateFareCardText(highlight, locale)}
                            </span>
                        ))}
                    </div>
                    <div className="mt-2 truncate font-display text-base font-black text-slate-950">
                        {cardTitle(card, customer, locale)}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] font-bold text-slate-500">
                        <span>
                            {firstSegment?.flightNumber ?? t('chat.flight')}
                        </span>
                        {card.packageCode && <span>{card.packageCode}</span>}
                        {card.classLetters && <span>{card.classLetters}</span>}
                    </div>
                </div>
                <div className="shrink-0 text-right">
                    <div className="font-display text-lg font-black text-red-800">
                        {card.price}
                    </div>
                    <div className="mt-1 text-[10px] font-bold text-slate-400">
                        {t('chat.total').toLowerCase()}
                    </div>
                </div>
            </div>

            <div className="grid items-center gap-4 rounded-md border border-slate-200 bg-white p-3 sm:grid-cols-[1fr_auto_1fr]">
                <FlightEndpoint
                    align="left"
                    code={firstSegment?.origin}
                    time={firstSegment?.hour}
                    date={firstSegment?.date}
                />
                <div className="min-w-[150px]">
                    <div className="mb-2 flex items-center justify-center gap-2 text-[11px] font-medium text-slate-500 uppercase">
                        <Clock className="size-3.5" />
                        {firstSegment?.duration ?? t('chat.duration')}
                    </div>
                    <div className="relative flex items-center">
                        <span className="size-2 rounded-full border border-slate-500 bg-white" />
                        <div className="h-px flex-1 bg-slate-300" />
                        <img
                            src="/assets/thy-emblem.svg"
                            className="mx-1.5 size-5 shrink-0 object-contain"
                            alt="Turkish Airlines"
                        />
                        <div className="h-px flex-1 bg-slate-300" />
                        <span className="size-2 rounded-full border border-slate-500 bg-white" />
                    </div>
                </div>
                <FlightEndpoint
                    align="right"
                    code={lastSegment?.destination}
                    time={lastSegment?.arrivalHour}
                    date={lastSegment?.date}
                />
            </div>

            <div className="flex items-center justify-end gap-3 text-xs">
                <div className="inline-flex shrink-0 items-center gap-1 font-black text-red-800">
                    {t('chat.features')}
                    <ChevronDown
                        className={`size-4 transition-transform duration-300 ${
                            expanded ? 'rotate-180' : ''
                        }`}
                    />
                </div>
            </div>
        </div>
    );
}

function FlightEndpoint({
    align,
    code,
    time,
    date,
}: {
    align: 'left' | 'right';
    code?: string;
    time?: string;
    date?: string;
}) {
    const { locale } = useTranslation();

    return (
        <div
            className={`min-w-0 text-center ${align === 'right' ? 'sm:text-right' : 'sm:text-left'}`}
        >
            <div className="font-display text-2xl leading-none font-semibold text-slate-950 md:text-[28px]">
                {time ?? '--:--'}
            </div>
            <div className="mt-1 font-condensed text-xs font-medium text-slate-900">
                {code ?? '---'}
            </div>
            <div className="mt-0.5 text-[11px] leading-4 font-medium text-slate-500">
                {formatFlightDate(date, locale)}
            </div>
        </div>
    );
}

function FeatureTable({ featureRows }: { featureRows: FeatureRow[] }) {
    const { locale, t } = useTranslation();

    return (
        <div className="overflow-hidden rounded-md border border-slate-200 bg-white">
            <table className="w-full table-fixed text-left text-xs">
                <colgroup>
                    <col className="w-[42%]" />
                    <col className="w-[28%]" />
                    <col className="w-[30%]" />
                </colgroup>
                <thead className="bg-slate-950 font-condensed text-[10px] font-black tracking-normal text-white uppercase">
                    <tr>
                        <th className="px-3 py-2">{t('chat.feature')}</th>
                        <th className="px-3 py-2">{t('chat.value')}</th>
                        <th className="px-3 py-2">{t('chat.source')}</th>
                    </tr>
                </thead>
                <tbody>
                    {featureRows.map((row, index) => (
                        <tr
                            key={`${row.label}-${index}`}
                            className="border-t border-slate-100 transition-colors hover:bg-red-50/50"
                        >
                            <td className="px-3 py-2 font-black break-words text-slate-900">
                                {translateFareCardText(row.label, locale)}
                            </td>
                            <td className="px-3 py-2 font-semibold break-words text-slate-700">
                                {translateFareCardText(row.value, locale)}
                            </td>
                            <td className="px-3 py-2 break-words text-slate-500">
                                {translateFareCardText(row.source, locale)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function WingoCheckoutModal({
    target,
    customer,
    onClose,
}: {
    target: CheckoutTarget;
    customer?: CustomerSummary;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [featuresOpen, setFeaturesOpen] = useState(true);
    const [buyer, setBuyer] = useState({
        first_name: '',
        last_name: '',
        email: '',
        passport_number: '',
    });
    const seatedPassengers =
        target.passengers.adults + target.passengers.children;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        router.post(
            '/purchase',
            {
                offer_ids: target.offerIds,
                first_name: buyer.first_name,
                last_name: buyer.last_name,
                email: buyer.email,
                passport_number: buyer.passport_number,
                adults: target.passengers.adults,
                children: target.passengers.children,
                babies: target.passengers.babies,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
            },
        );
    }

    return (
        <div className="fixed inset-0 z-[70] grid animate-in place-items-center bg-slate-950/60 p-4 duration-200 fade-in-50">
            <form
                onSubmit={submit}
                className="max-h-[92vh] w-full max-w-2xl animate-in overflow-y-auto rounded-md border border-slate-950 bg-white shadow-2xl duration-300 zoom-in-95 slide-in-from-bottom-3"
            >
                <div className="flex items-start justify-between gap-4 bg-red-900 px-5 py-4 text-white">
                    <div>
                        <div className="font-condensed text-xs font-bold tracking-wide text-red-100 uppercase">
                            {t('chat.checkout')}
                        </div>
                        <h2 className="mt-1 font-display text-xl font-black">
                            {cardTitle(target, customer)} · {target.price}
                        </h2>
                    </div>
                    <button
                        type="button"
                        className="grid size-9 place-items-center rounded-md text-white/80 transition-colors hover:bg-white/10 hover:text-white"
                        onClick={onClose}
                        aria-label={t('chat.close')}
                    >
                        <X className="size-5" />
                    </button>
                </div>

                <div className="grid gap-4 p-5">
                    <div className="overflow-hidden rounded-md border border-slate-200 bg-white">
                        <FlightDisplay
                            card={target}
                            customer={customer}
                            expanded={featuresOpen}
                        />
                    </div>

                    <button
                        type="button"
                        className="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-left transition-all duration-200 hover:-translate-y-px hover:border-red-200 hover:bg-red-50"
                        aria-expanded={featuresOpen}
                        onClick={() => setFeaturesOpen((current) => !current)}
                    >
                        <span className="flex items-center gap-2 text-sm font-black text-slate-950">
                            <CalendarRange className="size-4 text-red-800" />
                            {t('chat.featureTable')}
                        </span>
                        <ChevronDown
                            className={`size-4 text-red-800 transition-transform duration-300 ${
                                featuresOpen ? 'rotate-180' : ''
                            }`}
                        />
                    </button>

                    <div
                        className={`grid transition-all duration-300 ${
                            featuresOpen
                                ? 'grid-rows-[1fr] opacity-100'
                                : 'grid-rows-[0fr] opacity-0'
                        }`}
                    >
                        <div className="overflow-hidden">
                            <div className="animate-in duration-300 fade-in-50 slide-in-from-top-1">
                                <FeatureTable
                                    featureRows={target.featureRows}
                                />
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-2 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                        <div className="grid gap-2 sm:grid-cols-3">
                            <SummaryPill
                                icon={<Luggage className="size-4" />}
                                label={t('chat.seated')}
                                value={String(seatedPassengers)}
                            />
                            <SummaryPill
                                icon={<CircleCheck className="size-4" />}
                                label={t('chat.offers')}
                                value={String(target.offerIds.length)}
                            />
                            <SummaryPill
                                icon={<CircleDollarSign className="size-4" />}
                                label={t('chat.total')}
                                value={target.price}
                            />
                        </div>
                    </div>

                    <div className="grid animate-in gap-3 fade-in-50 slide-in-from-bottom-1 sm:grid-cols-2">
                        <CheckoutField
                            label={t('chat.firstName')}
                            value={buyer.first_name}
                            onChange={(value) =>
                                setBuyer((current) => ({
                                    ...current,
                                    first_name: value,
                                }))
                            }
                        />
                        <CheckoutField
                            label={t('chat.lastName')}
                            value={buyer.last_name}
                            onChange={(value) =>
                                setBuyer((current) => ({
                                    ...current,
                                    last_name: value,
                                }))
                            }
                        />
                        <CheckoutField
                            label={t('chat.email')}
                            value={buyer.email}
                            className="sm:col-span-2"
                            onChange={(value) =>
                                setBuyer((current) => ({
                                    ...current,
                                    email: value,
                                }))
                            }
                        />
                        <CheckoutField
                            label={t('chat.passportNumber')}
                            value={buyer.passport_number}
                            className="sm:col-span-2"
                            onChange={(value) =>
                                setBuyer((current) => ({
                                    ...current,
                                    passport_number: value,
                                }))
                            }
                        />
                    </div>

                    <button
                        type="submit"
                        disabled={target.offerIds.length === 0}
                        className="h-12 rounded-md bg-red-800 text-sm font-black text-white transition-all duration-200 hover:-translate-y-px hover:bg-red-900 hover:shadow-md active:translate-y-0 disabled:bg-slate-300"
                    >
                        {t('chat.confirmPurchase')}
                    </button>
                </div>
            </form>
        </div>
    );
}

function SummaryPill({
    icon,
    label,
    value,
}: {
    icon: ReactNode;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-center gap-2 rounded-md bg-white px-3 py-2 transition-transform duration-200 hover:-translate-y-px">
            <span className="text-red-800">{icon}</span>
            <span className="min-w-0">
                <span className="block font-condensed text-[10px] font-black text-slate-400 uppercase">
                    {label}
                </span>
                <span className="block truncate text-sm font-black text-slate-950">
                    {value}
                </span>
            </span>
        </div>
    );
}

function CheckoutField({
    label,
    value,
    className = '',
    onChange,
}: {
    label: string;
    value: string;
    className?: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className={`grid gap-1.5 ${className}`}>
            <span className="font-condensed text-[10px] font-black text-slate-500 uppercase">
                {label}
            </span>
            <input
                className="h-11 rounded-md border border-slate-200 px-3 text-sm font-semibold transition-all duration-200 outline-none focus:-translate-y-px focus:border-red-800 focus:shadow-sm"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
    );
}

function ChatHeader({
    isExpanded,
    onClose,
    onToggleExpanded,
}: {
    isExpanded: boolean;
    onClose: () => void;
    onToggleExpanded: () => void;
}) {
    const { t } = useTranslation();
    const ExpandedIcon = isExpanded ? Minimize2 : Maximize2;

    return (
        <div className="relative bg-linear-to-br from-red-950 via-red-800 to-red-600 px-5 py-5 text-white">
            <button
                type="button"
                className="absolute top-4 right-14 flex size-9 items-center justify-center rounded-md text-white/70 transition-all duration-300 hover:bg-white/10 hover:text-white"
                onClick={onToggleExpanded}
                aria-label={isExpanded ? t('chat.shrink') : t('chat.expand')}
                aria-pressed={isExpanded}
                title={isExpanded ? t('chat.shrink') : t('chat.expand')}
            >
                <ExpandedIcon
                    className={`size-5 transition-transform duration-300 ${
                        isExpanded ? 'scale-90' : 'scale-100'
                    }`}
                />
            </button>
            <button
                type="button"
                className="absolute top-4 right-4 flex size-9 items-center justify-center rounded-md text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                onClick={onClose}
                aria-label={t('chat.close')}
            >
                <X className="size-5" />
            </button>
            <div className="flex items-center gap-3 pr-24">
                <div className="flex size-11 items-center justify-center overflow-hidden rounded-md bg-white">
                    <img
                        src="/assets/wingo-face.png"
                        className="h-full w-full object-contain"
                        aria-hidden="true"
                        alt=""
                    />
                </div>
                <div>
                    <div className="font-rounded text-lg leading-none font-bold">
                        Wingo
                    </div>
                </div>
            </div>
        </div>
    );
}
