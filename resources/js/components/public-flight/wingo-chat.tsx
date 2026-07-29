import { router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarRange,
    ChevronDown,
    CircleCheck,
    CircleDollarSign,
    Clock,
    Luggage,
    Mic,
    Send,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    readStoredWingoSearchPrefill,
    wingoSearchPrefillEvent,
} from '@/lib/flight-search';
import type { WingoSearchPrefill } from '@/lib/flight-search';

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

type BuilderState = {
    origin: string;
    destination: string;
    date: string;
    tripType: 'one_way' | 'round_trip';
    returnDate: string;
    services: string[];
};

type ChatbotMessageStatusEvent =
    | (ChatbotMessageReadyEvent & { status: 'ready' })
    | { status: 'pending'; message_id: string };

type WingoChatProps = {
    isOpen: boolean;
    onOpen: () => void;
    onClose: () => void;
};

const suggestions = [
    'I want to fly to London this weekend with a comfortable fare.',
    'Find the cheapest Amsterdam flight without checked baggage.',
    'Which airports can I search?',
    'What happens if my flight is cancelled?',
];

const builderOptions = [
    { key: 'CHECKED_BAG', label: 'Checked bag' },
    { key: 'SEAT_SELECTION', label: 'Seat selection' },
    { key: 'CHANGE_ALLOWED', label: 'Change right' },
    { key: 'REFUNDABLE', label: 'Refund right' },
    { key: 'LOUNGE', label: 'Lounge' },
    { key: 'FAST_TRACK', label: 'Fast track' },
] as const;

const replyPollDelayMs = 1200;
const replyPollMaxAttempts = 35;
const defaultBuilder: BuilderState = {
    origin: 'IST',
    destination: 'LHR',
    date: '2026-08-06',
    tripType: 'one_way',
    returnDate: '2026-08-13',
    services: ['CHECKED_BAG', 'SEAT_SELECTION'],
};

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

function messageLanguage(text: string) {
    return /[çğıöşüÇĞİÖŞÜ]/.test(text) ? 'tr' : 'en';
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

function serviceValueLabel(value: unknown): string {
    if (isRecord(value)) {
        const amount = value.amount;

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
        return 'Custom add-on';
    }

    if (source === 'rule') {
        return 'Rule benefit';
    }

    return 'Package';
}

function featureRowsFromFare(fare: Record<string, unknown>): FeatureRow[] {
    const serviceRows = asRecords(fare.services).map((service) => ({
        label: textValue(service.name) ?? textValue(service.code) ?? 'Service',
        value: serviceValueLabel(service.value),
        source: sourceLabel(service.source),
    }));

    if (serviceRows.length > 0) {
        return serviceRows;
    }

    return fareDetails(fare).map((detail) => ({
        label: detail,
        value: 'Included',
        source: 'Package',
    }));
}

function builderFromPrefill(
    prefill: WingoSearchPrefill | null,
    current: BuilderState = defaultBuilder,
): BuilderState {
    if (!prefill) {
        return current;
    }

    return {
        ...current,
        origin: prefill.origin ?? current.origin,
        destination: prefill.destination ?? current.destination,
        date: prefill.date ?? current.date,
        tripType: prefill.tripType ?? current.tripType,
        returnDate: prefill.returnDate ?? current.returnDate,
    };
}

function messageWithSearchPrefill(
    message: string,
    prefill: WingoSearchPrefill | null,
): string {
    if (!prefill?.origin || !prefill.destination || !prefill.date) {
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

    const messageWithContext = `${message}\n\nCurrent search form: from ${prefill.origin} to ${prefill.destination} on ${prefill.date}.${returnText} Passengers: ${passengerText}. Use this as prefill context when the user asks about flights, fares, or bundles.`;

    return messageWithContext.length <= 2000 ? messageWithContext : message;
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

function segmentSchedule(segment: FlightSegment) {
    return [
        segment.date,
        [segment.hour, segment.arrivalHour].filter(Boolean).join('-'),
        segment.duration,
    ]
        .filter(Boolean)
        .join(' · ');
}

function fareDetails(fare: Record<string, unknown>): string[] {
    const checkedBag = numberValue(fare.checked_baggage_kg) ?? 0;
    const cabinBag = numberValue(fare.cabin_baggage_kg) ?? 0;
    const seatSelection = booleanValue(fare.seat_selection_free);
    const changeFee = numberValue(fare.change_fee_usd);
    const refundFee = numberValue(fare.refund_fee_usd);
    const changeable =
        fare.latest_change_hours !== null &&
        fare.latest_change_hours !== undefined;
    const refundable =
        fare.latest_refund_hours !== null &&
        fare.latest_refund_hours !== undefined;

    return [
        checkedBag > 0 ? `${checkedBag} kg checked bag` : 'No checked bag',
        cabinBag > 0 ? `${cabinBag} kg cabin bag` : undefined,
        seatSelection === true
            ? 'Seat selection included'
            : 'Seat selection paid',
        changeable
            ? changeFee === 0
                ? 'Free change'
                : `Change allowed, $${changeFee} fee`
            : 'No change allowed',
        refundable
            ? refundFee === 0
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
                badge: index === 0 ? 'Best pick' : 'Backup',
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
                        badge: index === 0 ? 'Suggested flight' : 'Option',
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
                    },
                ];
            });
        })
        .slice(0, 2);
}

export function WingoChat({ isOpen, onOpen, onClose }: WingoChatProps) {
    const [input, setInput] = useState('');
    const [searchPrefill, setSearchPrefill] =
        useState<WingoSearchPrefill | null>(() =>
            readStoredWingoSearchPrefill(),
        );
    const [builder, setBuilder] = useState<BuilderState>(() =>
        builderFromPrefill(readStoredWingoSearchPrefill()),
    );
    const [showBuilder, setShowBuilder] = useState(false);
    const [checkoutTarget, setCheckoutTarget] = useState<CheckoutTarget | null>(
        null,
    );
    const [isSending, setIsSending] = useState(false);
    const [isListening, setIsListening] = useState(false);
    const sessionId = useRef<string | null>(null);
    const scrollRef = useRef<HTMLDivElement | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([
        {
            id: 'welcome',
            role: 'assistant',
            text: 'Hi, I’m Wingo. Tell me where and when you want to fly, or ask about bags, changes, refunds, passenger rights, and booking. I’ll keep it short and show the best option first.',
            status: 'sent',
        },
    ]);

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
                                  text: 'I am still waiting on the fare assistant. Please try again.',
                                  status: 'failed',
                              }
                            : message,
                    ),
                );
                setIsSending(false);
            }

            window.setTimeout(poll, replyPollDelayMs);
        },
        [applyAssistantReply],
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
                text: 'Checking fares and rules...',
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
                    language: messageLanguage(trimmed),
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
                              text: 'I could not send that message. Please try again.',
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

        const storedPrefill = readStoredWingoSearchPrefill();

        if (storedPrefill) {
            setSearchPrefill(storedPrefill);
            setBuilder((current) => builderFromPrefill(storedPrefill, current));
        }

        function handleSearchPrefill(event: Event) {
            const prefill = (event as CustomEvent<WingoSearchPrefill>).detail;

            setSearchPrefill(prefill);
            setBuilder((current) => builderFromPrefill(prefill, current));
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
        recognition.lang = 'tr-TR';
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

    function toggleBuilderService(service: string) {
        setBuilder((current) => ({
            ...current,
            services: current.services.includes(service)
                ? current.services.filter((item) => item !== service)
                : [...current.services, service],
        }));
    }

    function sendBuilderRequest() {
        const serviceText =
            builder.services.length > 0
                ? builder.services.join(', ')
                : 'lowest sensible package';

        const returnText =
            builder.tripType === 'round_trip'
                ? ` Return on ${builder.returnDate}.`
                : '';

        sendMessage(
            `Build a custom bundle from ${builder.origin} to ${builder.destination} on ${builder.date}.${returnText} Preferred services: ${serviceText}.`,
        );
    }

    const hasUserPrompt = messages.some((message) => message.role === 'user');

    return (
        <>
            <button
                type="button"
                className="fixed right-6 bottom-6 z-40 flex size-16 items-center justify-center overflow-hidden rounded-md border-2 border-red-700 bg-white transition-transform hover:scale-105"
                onClick={onOpen}
                aria-label="Open Wingo chat"
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
                    aria-label="Close Wingo chat"
                    onClick={onClose}
                />
            )}

            <aside
                className={`fixed top-0 right-0 z-50 flex h-full w-full flex-col bg-white transition-transform duration-300 sm:w-[600px] ${
                    isOpen ? 'translate-x-0' : 'translate-x-full'
                }`}
                aria-hidden={!isOpen}
            >
                <ChatHeader onClose={onClose} />

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
                        <div className="mt-1 text-lg font-black text-red-800">
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
                                <div className="mb-1 font-bold text-red-800">
                                    Wingo
                                </div>
                            )}
                            <RichMessage
                                text={message.text}
                                isPending={message.status === 'pending'}
                            />
                            {message.role === 'assistant' && (
                                <FlightSuggestionCards
                                    toolTrace={message.toolTrace}
                                    onSelect={setCheckoutTarget}
                                />
                            )}
                            {message.role === 'assistant' &&
                                message.status === 'failed' && (
                                    <button
                                        type="button"
                                        className="mt-3 inline-flex h-9 items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 text-xs font-black text-red-800 transition-colors hover:bg-red-100"
                                        onClick={() => setShowBuilder(true)}
                                    >
                                        <SlidersHorizontal className="size-4" />
                                        Open bundle builder
                                    </button>
                                )}
                        </div>
                    ))}

                    {!hasUserPrompt && (
                        <div className="mr-6 rounded-md border border-slate-200 bg-white p-4">
                            <div className="mb-2 font-bold text-red-800">
                                Try asking
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

                    {showBuilder && (
                        <div className="mr-6 rounded-md border border-slate-200 bg-white p-4">
                            <div className="mb-2 font-bold text-red-800">
                                Custom bundle
                            </div>
                            <div className="mb-3 inline-flex rounded-md border border-slate-200 bg-slate-50 p-1">
                                {[
                                    ['one_way', 'One-way'],
                                    ['round_trip', 'Round trip'],
                                ].map(([value, label]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        className={`h-8 rounded-md px-3 text-xs font-black ${
                                            builder.tripType === value
                                                ? 'bg-white text-red-800 shadow-sm'
                                                : 'text-slate-500'
                                        }`}
                                        onClick={() =>
                                            setBuilder((current) => ({
                                                ...current,
                                                tripType: value as
                                                    'one_way' | 'round_trip',
                                            }))
                                        }
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <div className="grid gap-2 sm:grid-cols-3">
                                <MiniField
                                    label="From"
                                    value={builder.origin}
                                    onChange={(value) =>
                                        setBuilder((current) => ({
                                            ...current,
                                            origin: value.toUpperCase(),
                                        }))
                                    }
                                />
                                <MiniField
                                    label="To"
                                    value={builder.destination}
                                    onChange={(value) =>
                                        setBuilder((current) => ({
                                            ...current,
                                            destination: value.toUpperCase(),
                                        }))
                                    }
                                />
                                <MiniField
                                    label="Date"
                                    value={builder.date}
                                    onChange={(value) =>
                                        setBuilder((current) => ({
                                            ...current,
                                            date: value,
                                        }))
                                    }
                                />
                                {builder.tripType === 'round_trip' && (
                                    <MiniField
                                        label="Return"
                                        value={builder.returnDate}
                                        onChange={(value) =>
                                            setBuilder((current) => ({
                                                ...current,
                                                returnDate: value,
                                            }))
                                        }
                                    />
                                )}
                            </div>
                            <div className="mt-3 flex flex-wrap gap-1.5">
                                {builderOptions.map((option) => (
                                    <button
                                        key={option.key}
                                        type="button"
                                        className={`rounded-md border px-2 py-1 text-[11px] font-bold ${
                                            builder.services.includes(
                                                option.key,
                                            )
                                                ? 'border-red-800 bg-red-50 text-red-800'
                                                : 'border-slate-200 text-slate-600'
                                        }`}
                                        aria-pressed={builder.services.includes(
                                            option.key,
                                        )}
                                        onClick={() =>
                                            toggleBuilderService(option.key)
                                        }
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                            <button
                                type="button"
                                className="mt-3 h-9 rounded-md bg-red-800 px-3 text-xs font-black text-white"
                                onClick={sendBuilderRequest}
                                disabled={isSending}
                            >
                                Build package
                            </button>
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
                            placeholder="Type a message..."
                            value={input}
                            onChange={(event) => setInput(event.target.value)}
                        />
                        <button
                            type="button"
                            title={
                                isListening ? 'Listening' : 'Start voice input'
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
                        Recommendations are curated from available package
                        inventory. Purchases require an explicit quote
                        confirmation.
                    </div>
                </div>
            </aside>
            {checkoutTarget && (
                <WingoCheckoutModal
                    target={checkoutTarget}
                    onClose={() => setCheckoutTarget(null)}
                />
            )}
        </>
    );
}

function MiniField({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className="grid gap-1">
            <span className="text-[10px] font-black text-slate-500 uppercase">
                {label}
            </span>
            <input
                className="h-9 rounded-md border border-slate-200 px-2 text-xs font-bold text-slate-900 outline-none focus:border-red-800"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
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
    onSelect,
}: {
    toolTrace?: ToolTrace[];
    onSelect: (target: CheckoutTarget) => void;
}) {
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
                    <article
                        key={card.key}
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
                            <FlightDisplay card={card} expanded={isExpanded} />
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
                                        {detail}
                                    </span>
                                ))}
                            </div>
                            <button
                                type="button"
                                className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-red-800 px-3 text-xs font-black text-white transition-all duration-200 hover:-translate-y-px hover:bg-red-900 hover:shadow-sm active:translate-y-0"
                                onClick={() => onSelect(card)}
                            >
                                Checkout
                                <ArrowRight className="size-3.5" />
                            </button>
                        </div>
                    </article>
                );
            })}
        </div>
    );
}

function FlightDisplay({
    card,
    expanded,
}: {
    card: FlightCard;
    expanded: boolean;
}) {
    const firstSegment = card.segments[0];
    const lastSegment = card.segments[card.segments.length - 1] ?? firstSegment;

    return (
        <div className="grid gap-3 p-3">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="rounded-md bg-red-800 px-2 py-1 text-[10px] font-black tracking-normal text-white uppercase">
                            {card.badge}
                        </span>
                        {card.isCustom && (
                            <span className="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-[10px] font-black text-emerald-700">
                                Wingo custom
                            </span>
                        )}
                        {card.isPrivate && (
                            <span className="rounded-md border border-slate-200 bg-slate-100 px-2 py-1 text-[10px] font-black text-slate-700">
                                Private offer
                            </span>
                        )}
                    </div>
                    <div className="mt-2 truncate text-base font-black text-slate-950">
                        {card.title}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] font-bold text-slate-500">
                        <span>{firstSegment?.flightNumber ?? 'Flight'}</span>
                        {card.packageCode && <span>{card.packageCode}</span>}
                        {card.classLetters && <span>{card.classLetters}</span>}
                    </div>
                </div>
                <div className="shrink-0 text-right">
                    <div className="text-lg font-black text-red-800">
                        {card.price}
                    </div>
                    <div className="mt-1 text-[10px] font-bold text-slate-400">
                        total
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
                        {firstSegment?.duration ?? 'Duration'}
                    </div>
                    <div className="relative flex items-center">
                        <span className="size-2 rounded-full border border-slate-500 bg-white" />
                        <div className="h-px flex-1 bg-slate-300" />
                        <img
                            src="/assets/thy-emblem.svg"
                            className="mx-2 size-7 shrink-0 object-contain"
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

            <div className="flex items-center justify-between gap-3 text-xs">
                <div className="min-w-0 truncate font-semibold text-slate-600">
                    {card.route ?? 'Selected route'}
                </div>
                <div className="inline-flex shrink-0 items-center gap-1 font-black text-red-800">
                    Features
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
    return (
        <div
            className={`min-w-0 text-center ${align === 'right' ? 'sm:text-right' : 'sm:text-left'}`}
        >
            <div className="text-2xl leading-none font-semibold text-slate-950 md:text-[28px]">
                {time ?? '--:--'}
            </div>
            <div className="mt-1 text-xs font-medium text-slate-900">
                {code ?? '---'}
            </div>
            <div className="mt-0.5 text-[11px] leading-4 font-medium text-slate-500">
                {date ?? 'date'}
            </div>
        </div>
    );
}

function FeatureTable({ featureRows }: { featureRows: FeatureRow[] }) {
    return (
        <div className="overflow-hidden rounded-md border border-slate-200 bg-white">
            <table className="w-full table-fixed text-left text-xs">
                <colgroup>
                    <col className="w-[42%]" />
                    <col className="w-[28%]" />
                    <col className="w-[30%]" />
                </colgroup>
                <thead className="bg-slate-950 text-[10px] font-black tracking-normal text-white uppercase">
                    <tr>
                        <th className="px-3 py-2">Feature</th>
                        <th className="px-3 py-2">Value</th>
                        <th className="px-3 py-2">Source</th>
                    </tr>
                </thead>
                <tbody>
                    {featureRows.map((row, index) => (
                        <tr
                            key={`${row.label}-${index}`}
                            className="border-t border-slate-100 transition-colors hover:bg-red-50/50"
                        >
                            <td className="px-3 py-2 font-black break-words text-slate-900">
                                {row.label}
                            </td>
                            <td className="px-3 py-2 font-semibold break-words text-slate-700">
                                {row.value}
                            </td>
                            <td className="px-3 py-2 break-words text-slate-500">
                                {row.source}
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
    onClose,
}: {
    target: CheckoutTarget;
    onClose: () => void;
}) {
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
                        <div className="text-xs font-bold tracking-wide text-red-100 uppercase">
                            Checkout
                        </div>
                        <h2 className="mt-1 text-xl font-black">
                            {target.title} · {target.price}
                        </h2>
                    </div>
                    <button
                        type="button"
                        className="grid size-9 place-items-center rounded-md text-white/80 transition-colors hover:bg-white/10 hover:text-white"
                        onClick={onClose}
                        aria-label="Close checkout"
                    >
                        <X className="size-5" />
                    </button>
                </div>

                <div className="grid gap-4 p-5">
                    <div className="overflow-hidden rounded-md border border-slate-200 bg-white">
                        <FlightDisplay card={target} expanded={featuresOpen} />
                    </div>

                    <button
                        type="button"
                        className="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-left transition-all duration-200 hover:-translate-y-px hover:border-red-200 hover:bg-red-50"
                        aria-expanded={featuresOpen}
                        onClick={() => setFeaturesOpen((current) => !current)}
                    >
                        <span className="flex items-center gap-2 text-sm font-black text-slate-950">
                            <CalendarRange className="size-4 text-red-800" />
                            Offer feature table
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
                                label="Seated"
                                value={String(seatedPassengers)}
                            />
                            <SummaryPill
                                icon={<CircleCheck className="size-4" />}
                                label="Offers"
                                value={String(target.offerIds.length)}
                            />
                            <SummaryPill
                                icon={<CircleDollarSign className="size-4" />}
                                label="Total"
                                value={target.price}
                            />
                        </div>
                    </div>

                    <div className="grid animate-in gap-3 fade-in-50 slide-in-from-bottom-1 sm:grid-cols-2">
                        <CheckoutField
                            label="First name"
                            value={buyer.first_name}
                            onChange={(value) =>
                                setBuyer((current) => ({
                                    ...current,
                                    first_name: value,
                                }))
                            }
                        />
                        <CheckoutField
                            label="Last name"
                            value={buyer.last_name}
                            onChange={(value) =>
                                setBuyer((current) => ({
                                    ...current,
                                    last_name: value,
                                }))
                            }
                        />
                        <CheckoutField
                            label="Email"
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
                            label="Passport number"
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
                        Confirm purchase
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
                <span className="block text-[10px] font-black text-slate-400 uppercase">
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
            <span className="text-[10px] font-black text-slate-500 uppercase">
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

function SummaryLine({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-3">
            <span>{label}</span>
            <span className="font-black text-slate-950">{value}</span>
        </div>
    );
}

function ChatHeader({ onClose }: { onClose: () => void }) {
    return (
        <div className="relative bg-linear-to-br from-red-950 via-red-800 to-red-600 px-5 py-5 text-white">
            <button
                type="button"
                className="absolute top-4 right-4 flex size-9 items-center justify-center rounded-md text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                onClick={onClose}
                aria-label="Close chat"
            >
                <X className="size-5" />
            </button>
            <div className="flex items-center gap-3 pr-12">
                <div className="flex size-11 items-center justify-center overflow-hidden rounded-md bg-white">
                    <img
                        src="/assets/wingo-face.png"
                        className="h-full w-full object-contain"
                        aria-hidden="true"
                        alt=""
                    />
                </div>
                <div>
                    <div className="text-lg leading-none font-bold">
                        Fare Assistant
                    </div>
                    <div className="mt-1.5 text-[11px] text-white/80">
                        AI-supported travel advisor
                    </div>
                </div>
            </div>
        </div>
    );
}
