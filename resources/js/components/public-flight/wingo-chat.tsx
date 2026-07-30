import { router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ChevronDown,
    Clock,
    Maximize2,
    Mic,
    Minimize2,
    Send,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';
import {
    formatShortDate,
    readStoredWingoSearchPrefill,
    wingoLaunchEvent,
    wingoSearchPrefillEvent,
} from '@/lib/flight-search';
import type {
    WingoLaunchDetail,
    WingoSearchPrefill,
    WingoTriggerContext,
} from '@/lib/flight-search';
import { useTranslation } from '@/lib/i18n';
import type { Locale, TranslationKey } from '@/lib/i18n';
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

type FollowUp = {
    question: string;
    choices: GuidedChoice[];
};

type ChatbotMessageReadyEvent = {
    message_id: string;
    reply: string;
    failed: boolean;
    tool_trace: ToolTrace[];
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
};

type OfferCard = {
    key: string;
    offerIds: number[];
    memo: string;
    title: string;
    price: string;
    totalPrice: number;
    isCustom: boolean;
    isRoundTrip: boolean;
    passengers: PassengerCounts;
    segments: FlightSegment[];
    route?: string;
    raw: Record<string, unknown>;
};

type GeoLocation = {
    lat: number;
    lng: number;
};

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
const consentStorageKey = 'wingo-consent';

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

function storedConsent(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.localStorage.getItem(consentStorageKey) === 'yes';
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

function serviceByCode(
    services: Record<string, unknown>[],
    code: string,
): Record<string, unknown> | undefined {
    return services.find((service) => textValue(service.code) === code);
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

function passengerCountsFromRecord(
    record: Record<string, unknown>,
): PassengerCounts {
    return {
        adults: numberValue(record.adults) ?? 1,
        children: numberValue(record.children) ?? 0,
        babies: numberValue(record.babies) ?? 0,
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

function routeLabel(segments: FlightSegment[]): string | undefined {
    const first = segments[0];
    const last = segments[segments.length - 1];

    if (!first) {
        return undefined;
    }

    if (segments.length > 1) {
        return [first.origin, first.destination, last.destination]
            .filter(Boolean)
            .join(' → ');
    }

    return [first.origin, first.destination].filter(Boolean).join(' → ');
}

function offersFromTrace(toolTrace?: ToolTrace[]): OfferCard[] {
    if (!toolTrace) {
        return [];
    }

    const trace = [...toolTrace]
        .reverse()
        .find((item) => item.tool === 'present_offers');

    if (!trace) {
        return [];
    }

    return asRecords(trace.result.offers)
        .map((offer, index) => {
            const segments = asRecords(offer.segments).map(segmentFromRecord);
            const totalPrice = numberValue(offer.total_price_usd) ?? 0;
            const offerIds = numberList(offer.offer_ids);

            return {
                key: `offer-${offerIds.join('-') || index}`,
                offerIds,
                memo: textValue(offer.memo) ?? '',
                title: textValue(offer.title) ?? 'Offer',
                price: formatMoney(totalPrice) ?? '$0',
                totalPrice,
                isCustom: booleanValue(offer.is_custom) === true,
                isRoundTrip: booleanValue(offer.is_round_trip) === true,
                passengers: passengerCountsFromRecord(
                    isRecord(offer.passengers) ? offer.passengers : {},
                ),
                segments,
                route: routeLabel(segments),
                raw: offer,
            } satisfies OfferCard;
        })
        .slice(0, 2);
}

function followUpFromTrace(toolTrace?: ToolTrace[]): FollowUp | null {
    const trace = toolTrace?.find((item) => item.tool === 'ask_follow_up');

    if (!trace) {
        return null;
    }

    const choices = asRecords(trace.result.choices)
        .map((choice) => ({
            label: textValue(choice.label) ?? '',
            message: textValue(choice.message) ?? '',
        }))
        .filter((choice) => choice.label !== '' && choice.message !== '');

    return {
        question: textValue(trace.result.question) ?? '',
        choices,
    };
}

function flexValue(
    t: (key: TranslationKey) => string,
    locale: Locale,
    hours: number | undefined,
    feeUsd: number | undefined,
    feePercent: number | undefined,
): string {
    if (hours === undefined) {
        return t('chat.val.notIncluded');
    }

    if (feePercent !== undefined) {
        return locale === 'tr'
            ? `%${feePercent} ücret, ${hours} sa öncesine kadar`
            : `${feePercent}% fee until ${hours}h`;
    }

    if (feeUsd === 0) {
        return locale === 'tr'
            ? `${hours} sa öncesine kadar ücretsiz`
            : `Free until ${hours}h`;
    }

    const fee = feeUsd ?? 0;

    return locale === 'tr'
        ? `$${fee} ücret, ${hours} sa öncesine kadar`
        : `$${fee} fee until ${hours}h`;
}

function featureRowsFromCard(
    raw: Record<string, unknown>,
    t: (key: TranslationKey) => string,
    locale: Locale,
): FeatureRow[] {
    const services = asRecords(raw.services);
    const checkedBag =
        numberValue(raw.checked_baggage_kg) ??
        serviceAmount(services, 'CHECKED_BAG');
    const cabinBag =
        numberValue(raw.cabin_baggage_kg) ??
        serviceAmount(services, 'CABIN_BAG');
    const seatSelection = booleanValue(raw.seat_selection_free) === true;
    const changeHours = numberValue(raw.latest_change_hours);
    const refundHours = numberValue(raw.latest_refund_hours);

    const seatValue = serviceIsEnabled(serviceByCode(services, 'SEAT_EXIT_ROW'))
        ? t('chat.val.exitRow')
        : serviceIsEnabled(serviceByCode(services, 'SEAT_STANDARD'))
          ? t('chat.val.standard')
          : seatSelection
            ? t('chat.val.included')
            : t('chat.val.notIncluded');

    return [
        {
            label: t('chat.feat.checkedBag'),
            value:
                checkedBag > 0
                    ? `${checkedBag} kg`
                    : t('chat.val.notIncluded'),
        },
        {
            label: t('chat.feat.cabinBag'),
            value: cabinBag > 0 ? `${cabinBag} kg` : t('chat.val.notIncluded'),
        },
        {
            label: t('chat.feat.seat'),
            value: seatValue,
        },
        {
            label: t('chat.feat.changes'),
            value: flexValue(
                t,
                locale,
                changeHours,
                numberValue(raw.change_fee_usd),
                numberValue(raw.change_fee_percent),
            ),
        },
        {
            label: t('chat.feat.refunds'),
            value: flexValue(
                t,
                locale,
                refundHours,
                numberValue(raw.refund_fee_usd),
                numberValue(raw.refund_fee_percent),
            ),
        },
    ];
}

function customerFirstName(customer?: CustomerSummary): string | undefined {
    return customer?.firstName.trim() || undefined;
}

function offerTitle(
    card: OfferCard,
    customer: CustomerSummary | undefined,
    locale: Locale,
): string {
    if (!card.isCustom) {
        return card.title;
    }

    const firstName = customerFirstName(customer);

    if (locale === 'tr') {
        return firstName ? `${firstName} için hazırlandı` : 'Sana özel';
    }

    return firstName ? `Built for ${firstName}` : 'Built for you';
}

export function WingoChat({ isOpen, onOpen, onClose }: WingoChatProps) {
    const page = usePage<{
        customer?: CustomerSummary;
        auth?: { user?: { name?: string } | null };
    }>();
    const { locale, t } = useTranslation();
    const customer = page.props.customer;
    const currentPage = page.component;
    const firstName =
        customerFirstName(customer) ??
        page.props.auth?.user?.name?.trim().split(/\s+/)[0];

    const [input, setInput] = useState('');
    const [searchPrefill, setSearchPrefill] =
        useState<WingoSearchPrefill | null>(() =>
            readStoredWingoSearchPrefill(),
        );
    const [checkoutTarget, setCheckoutTarget] = useState<OfferCard | null>(null);
    const [isSending, setIsSending] = useState(false);
    const [isListening, setIsListening] = useState(false);
    const [isExpanded, setIsExpanded] = useState(false);
    const [typeOverride, setTypeOverride] = useState(false);
    const [consentGiven, setConsentGiven] = useState<boolean>(() =>
        storedConsent(),
    );
    const [consentDeclined, setConsentDeclined] = useState(false);

    const sessionId = useRef<string | null>(null);
    const scrollRef = useRef<HTMLDivElement | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);
    const recognitionRef = useRef<BrowserSpeechRecognition | null>(null);
    const voiceBaseRef = useRef('');
    const consentRef = useRef(consentGiven);
    const locationRef = useRef<GeoLocation | null>(null);
    const pendingLaunch = useRef<WingoLaunchDetail | null>(null);

    const [messages, setMessages] = useState<ChatMessage[]>([
        {
            id: 'welcome',
            role: 'assistant',
            text: firstName
                ? t('chat.welcomeNamed').replace(':name', firstName)
                : t('chat.welcome'),
            status: 'sent',
        },
    ]);

    const suggestions = [
        t('chat.suggestions.help'),
        t('chat.suggestions.flexibility'),
        t('chat.suggestions.airports'),
        t('chat.suggestions.cancelled'),
    ];

    useEffect(() => {
        consentRef.current = consentGiven;
    }, [consentGiven]);

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
            setTypeOverride(false);
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

    useEffect(() => {
        const element = textareaRef.current;

        if (!element) {
            return;
        }

        element.style.height = 'auto';
        element.style.height = `${Math.min(element.scrollHeight, 160)}px`;
    }, [input]);

    const buildContext = useCallback(
        (
            prefillOverride: WingoSearchPrefill | null,
            triggerContext?: WingoTriggerContext,
        ): Record<string, unknown> => {
            const prefill = prefillOverride ?? searchPrefill;
            const context: Record<string, unknown> = { page: currentPage };

            if (prefill) {
                context.form = {
                    origin: prefill.origin,
                    destination: prefill.destination,
                    date: prefill.date,
                    return_date: prefill.returnDate,
                    trip_type: prefill.tripType,
                    adults: prefill.adults,
                    children: prefill.children,
                    babies: prefill.babies,
                    submitted: currentPage === 'flight-results',
                };
            }

            if (locationRef.current) {
                context.location = locationRef.current;
            }

            if (triggerContext) {
                context.trigger_context = triggerContext;
            }

            return context;
        },
        [currentPage, searchPrefill],
    );

    const sendMessage = useCallback(
        async (
            text: string,
            options?: {
                prefillOverride?: WingoSearchPrefill | null;
                trigger?: string;
                triggerContext?: WingoTriggerContext;
                hidden?: boolean;
            },
        ) => {
            const trimmed = text.trim();
            const trigger = options?.trigger;

            if ((!trimmed && !trigger) || isSending) {
                return;
            }

            stopVoiceInput();
            setTypeOverride(false);

            const assistantMessageId = randomId();
            const chatSessionId = sessionId.current ?? storedSessionId();
            sessionId.current = chatSessionId;

            setMessages((current) => [
                ...current,
                ...(options?.hidden || !trimmed
                    ? []
                    : [
                          {
                              id: randomId(),
                              role: 'user' as const,
                              text: trimmed,
                              status: 'sent' as const,
                          },
                      ]),
                {
                    id: assistantMessageId,
                    role: 'assistant',
                    text: t('chat.pending'),
                    status: 'pending',
                },
            ]);

            if (!options?.hidden) {
                setInput('');
            }

            setIsSending(true);

            try {
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
                        message: trimmed || undefined,
                        source: 'ours',
                        language: locale,
                        consent: true,
                        trigger,
                        context: buildContext(
                            options?.prefillOverride ?? null,
                            options?.triggerContext,
                        ),
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
        },
        [buildContext, isSending, locale, pollForReply, t],
    );

    const fireLaunch = useCallback(
        (detail: WingoLaunchDetail) => {
            const nextPrefill = detail.prefill ?? searchPrefill;

            if (detail.prefill) {
                setSearchPrefill(detail.prefill);
            }

            if (detail.trigger) {
                void sendMessage('', {
                    prefillOverride: nextPrefill ?? null,
                    trigger: detail.trigger,
                    triggerContext: detail.triggerContext,
                    hidden: true,
                });

                return;
            }

            if (detail.message) {
                void sendMessage(detail.message, {
                    prefillOverride: nextPrefill ?? null,
                });
            }
        },
        [searchPrefill, sendMessage],
    );

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        function handleSearchPrefill(event: Event) {
            setSearchPrefill(
                (event as CustomEvent<WingoSearchPrefill>).detail,
            );
        }

        window.addEventListener(wingoSearchPrefillEvent, handleSearchPrefill);

        return () => {
            window.removeEventListener(
                wingoSearchPrefillEvent,
                handleSearchPrefill,
            );
        };
    }, []);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        function handleWingoLaunch(event: Event) {
            const detail = (event as CustomEvent<WingoLaunchDetail>).detail;

            if (!detail) {
                return;
            }

            if (!consentRef.current) {
                pendingLaunch.current = detail;

                return;
            }

            window.setTimeout(() => fireLaunch(detail), 0);
        }

        window.addEventListener(wingoLaunchEvent, handleWingoLaunch);

        return () => {
            window.removeEventListener(wingoLaunchEvent, handleWingoLaunch);
        };
    }, [fireLaunch]);

    function acceptConsent() {
        window.localStorage.setItem(consentStorageKey, 'yes');
        setConsentGiven(true);
        setConsentDeclined(false);
        consentRef.current = true;

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    locationRef.current = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                    };
                },
                () => {
                    locationRef.current = null;
                },
                {
                    enableHighAccuracy: false,
                    timeout: 8000,
                    maximumAge: 600000,
                },
            );
        }

        const pending = pendingLaunch.current;

        if (pending) {
            pendingLaunch.current = null;
            window.setTimeout(() => fireLaunch(pending), 0);
        }
    }

    function declineConsent() {
        setConsentDeclined(true);
    }

    function stopVoiceInput() {
        recognitionRef.current?.stop();
        recognitionRef.current = null;
        setIsListening(false);
    }

    function startVoiceInput() {
        const Recognition =
            window.SpeechRecognition ?? window.webkitSpeechRecognition;

        if (!Recognition) {
            return;
        }

        if (isListening) {
            stopVoiceInput();

            return;
        }

        const recognition = new Recognition();
        recognition.lang = locale === 'tr' ? 'tr-TR' : 'en-US';
        recognition.interimResults = true;
        recognition.continuous = true;
        recognition.maxAlternatives = 1;
        voiceBaseRef.current = input ? `${input.trimEnd()} ` : '';
        recognition.onstart = () => setIsListening(true);
        recognition.onend = () => {
            setIsListening(false);
            recognitionRef.current = null;
        };
        recognition.onerror = () => {
            setIsListening(false);
            recognitionRef.current = null;
        };
        recognition.onresult = (event) => {
            let transcript = '';

            for (let index = 0; index < event.results.length; index += 1) {
                transcript += event.results[index]?.[0]?.transcript ?? '';
            }

            setInput(`${voiceBaseRef.current}${transcript}`.trimStart());
        };
        recognitionRef.current = recognition;
        recognition.start();
    }

    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        void sendMessage(input);
    }

    function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void sendMessage(input);
        }
    }

    function closeWingoChat() {
        setIsExpanded(false);
        stopVoiceInput();
        onClose();
    }

    const hasUserPrompt = messages.some((message) => message.role === 'user');
    const panelSizeClasses = isExpanded ? 'w-full' : 'w-full sm:w-[600px]';
    const lastMessage = messages[messages.length - 1];
    const activeFollowUp =
        lastMessage?.role === 'assistant' && lastMessage.status === 'sent'
            ? followUpFromTrace(lastMessage.toolTrace)
            : null;
    const lockInput =
        !!activeFollowUp && activeFollowUp.choices.length > 0 && !typeOverride;

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
                                <ChoiceButtons
                                    choices={
                                        followUpFromTrace(message.toolTrace)
                                            ?.choices ?? []
                                    }
                                    disabled={isSending}
                                    onSelect={(choice) =>
                                        sendMessage(choice.message)
                                    }
                                />
                            )}
                            {message.role === 'assistant' && (
                                <OfferCards
                                    toolTrace={message.toolTrace}
                                    customer={customer}
                                    onCheckout={setCheckoutTarget}
                                />
                            )}
                        </div>
                    ))}

                    {!hasUserPrompt && !lockInput && (
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
                    {lockInput ? (
                        <div className="flex items-center justify-between gap-3">
                            <span className="text-xs font-medium text-slate-500">
                                {t('chat.selectOption')}
                            </span>
                            <button
                                type="button"
                                className="shrink-0 text-xs font-black text-red-800 underline-offset-2 hover:underline"
                                onClick={() => setTypeOverride(true)}
                            >
                                {t('chat.typeOwn')}
                            </button>
                        </div>
                    ) : (
                        <form className="flex items-end gap-2.5" onSubmit={handleSubmit}>
                            <textarea
                                ref={textareaRef}
                                rows={1}
                                className="max-h-40 flex-1 resize-none rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-5 transition-colors outline-none focus:border-red-800 focus:bg-white"
                                placeholder={t('chat.typeMessage')}
                                value={input}
                                onChange={(event) => setInput(event.target.value)}
                                onKeyDown={handleKeyDown}
                            />
                            <button
                                type="button"
                                title={
                                    isListening
                                        ? t('chat.listening')
                                        : t('chat.voiceInput')
                                }
                                className={`inline-flex size-12 shrink-0 items-center justify-center rounded-md border transition-colors ${
                                    isListening
                                        ? 'border-red-800 bg-red-50 text-red-800'
                                        : 'border-slate-200 bg-white text-slate-500 hover:border-red-800 hover:text-red-800'
                                }`}
                                onClick={startVoiceInput}
                            >
                                <Mic className="size-5" />
                            </button>
                            <button
                                className="inline-flex size-12 shrink-0 items-center justify-center rounded-md bg-red-800 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-60"
                                disabled={isSending}
                            >
                                <Send className="size-5" />
                            </button>
                        </form>
                    )}
                    <div className="mt-2 px-1 text-[10px] font-medium text-slate-400">
                        {t('chat.disclaimer')}
                    </div>
                </div>

                {isOpen && !consentGiven && (
                    <ConsentGate
                        declined={consentDeclined}
                        onAccept={acceptConsent}
                        onDecline={declineConsent}
                    />
                )}
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

function ConsentGate({
    declined,
    onAccept,
    onDecline,
}: {
    declined: boolean;
    onAccept: () => void;
    onDecline: () => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="absolute inset-0 z-20 flex flex-col overflow-y-auto bg-white/95 backdrop-blur-sm">
            <div className="m-auto w-full max-w-md p-6">
                <div className="mb-4 flex items-center gap-3">
                    <span className="grid size-11 place-items-center rounded-md bg-red-50 text-red-800">
                        <ShieldCheck className="size-6" />
                    </span>
                    <h2 className="font-display text-lg font-black text-slate-950">
                        {t('chat.consent.title')}
                    </h2>
                </div>
                <p className="text-sm leading-6 text-slate-600">
                    {t('chat.consent.body')}
                </p>
                <p className="mt-3 text-xs leading-5 text-slate-500">
                    {t('chat.consent.location')}
                </p>
                {declined && (
                    <p className="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                        {t('chat.consent.declined')}
                    </p>
                )}
                <div className="mt-6 grid gap-2">
                    <button
                        type="button"
                        className="h-12 rounded-md bg-red-800 text-sm font-black text-white transition-colors hover:bg-red-900"
                        onClick={onAccept}
                    >
                        {t('chat.consent.accept')}
                    </button>
                    <button
                        type="button"
                        className="h-11 rounded-md border border-slate-200 text-sm font-bold text-slate-600 transition-colors hover:bg-slate-50"
                        onClick={onDecline}
                    >
                        {t('chat.consent.decline')}
                    </button>
                </div>
            </div>
        </div>
    );
}

function ChoiceButtons({
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

function TypingIndicator({ label }: { label: string }) {
    return (
        <div
            className="flex h-6 items-center gap-1"
            role="status"
            aria-label={label}
        >
            {[0, 1, 2].map((index) => (
                <span
                    key={index}
                    className="size-2 rounded-full bg-red-700 motion-safe:animate-bounce"
                    style={{ animationDelay: `${index * 120}ms` }}
                />
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
    if (isPending) {
        return <TypingIndicator label={text} />;
    }

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
        <div className="space-y-2">
            {blocks.length > 0 ? blocks : <p>{text}</p>}
        </div>
    );
}

function OfferCards({
    toolTrace,
    customer,
    onCheckout,
}: {
    toolTrace?: ToolTrace[];
    customer?: CustomerSummary;
    onCheckout: (card: OfferCard) => void;
}) {
    const { locale, t } = useTranslation();
    const [expandedKey, setExpandedKey] = useState<string | null>(null);
    const [isComparing, setIsComparing] = useState(false);
    const cards = offersFromTrace(toolTrace);

    if (cards.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 grid gap-4">
            {cards.map((card) => {
                const isExpanded = expandedKey === card.key;

                return (
                    <div key={card.key} className="grid gap-2">
                        {card.memo && (
                            <p className="px-1 text-xs leading-5 font-medium text-slate-600">
                                {card.memo}
                            </p>
                        )}
                        <article className="overflow-hidden rounded-md border border-slate-200 bg-white text-slate-950 shadow-sm">
                            <div className="grid gap-3 p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="truncate font-display text-base font-black text-slate-950">
                                            {offerTitle(card, customer, locale)}
                                        </div>
                                        {card.route && (
                                            <div className="mt-0.5 text-[11px] font-semibold text-slate-500">
                                                {card.route}
                                            </div>
                                        )}
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <div className="font-display text-lg font-black text-red-800">
                                            {card.price}
                                        </div>
                                        <div className="text-[10px] font-bold text-slate-400">
                                            {t('chat.total').toLowerCase()}
                                        </div>
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    {card.segments.map((segment, index) => (
                                        <SegmentRow
                                            key={`${card.key}-segment-${index}`}
                                            segment={segment}
                                        />
                                    ))}
                                </div>

                                <button
                                    type="button"
                                    className="flex items-center justify-end gap-1.5 text-xs font-black text-red-800"
                                    aria-expanded={isExpanded}
                                    onClick={() =>
                                        setExpandedKey(
                                            isExpanded ? null : card.key,
                                        )
                                    }
                                >
                                    {t('chat.features')}
                                    <ChevronDown
                                        className={`size-4 transition-transform duration-300 ${
                                            isExpanded ? 'rotate-180' : ''
                                        }`}
                                    />
                                </button>
                            </div>

                            <div
                                className={`grid transition-all duration-300 ${
                                    isExpanded
                                        ? 'grid-rows-[1fr] opacity-100'
                                        : 'grid-rows-[0fr] opacity-0'
                                }`}
                            >
                                <div className="overflow-hidden">
                                    <div className="border-t border-slate-100 bg-slate-50/70 p-3">
                                        <FeatureTable
                                            rows={featureRowsFromCard(
                                                card.raw,
                                                t,
                                                locale,
                                            )}
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="flex justify-end border-t border-slate-100 px-4 py-3">
                                <button
                                    type="button"
                                    className="inline-flex h-9 items-center gap-1.5 rounded-md bg-red-800 px-4 text-xs font-black text-white transition-colors hover:bg-red-900"
                                    onClick={() => onCheckout(card)}
                                >
                                    {t('chat.checkout')}
                                    <ArrowRight className="size-3.5" />
                                </button>
                            </div>
                        </article>
                    </div>
                );
            })}

            {cards.length > 1 && (
                <button
                    type="button"
                    className="inline-flex h-10 items-center justify-center rounded-md border border-red-200 bg-white px-3 text-xs font-black text-red-800 transition-colors hover:border-red-800 hover:bg-red-50"
                    onClick={() => setIsComparing(true)}
                >
                    {t('chat.compareOffers')}
                </button>
            )}

            {isComparing && (
                <OfferComparisonModal
                    cards={cards}
                    customer={customer}
                    onClose={() => setIsComparing(false)}
                    onSelect={(card) => {
                        setIsComparing(false);
                        onCheckout(card);
                    }}
                />
            )}
        </div>
    );
}

function SegmentRow({ segment }: { segment: FlightSegment }) {
    const { t } = useTranslation();

    return (
        <div className="grid items-center gap-3 rounded-md border border-slate-200 bg-white p-3 sm:grid-cols-[1fr_auto_1fr]">
            <FlightEndpoint
                align="left"
                code={segment.origin}
                time={segment.hour}
                date={segment.date}
            />
            <div className="min-w-[130px]">
                <div className="mb-1.5 flex items-center justify-center gap-1.5 text-[11px] font-medium text-slate-500 uppercase">
                    <Clock className="size-3.5" />
                    {segment.duration ?? t('chat.duration')}
                </div>
                <div className="relative flex items-center">
                    <span className="size-2 rounded-full border border-slate-500 bg-white" />
                    <div className="h-px flex-1 bg-slate-300" />
                    <img
                        src="/assets/tk-mark.svg"
                        className="mx-1.5 size-5 shrink-0 object-contain"
                        alt="Turkish Airlines"
                    />
                    <div className="h-px flex-1 bg-slate-300" />
                    <span className="size-2 rounded-full border border-slate-500 bg-white" />
                </div>
                {segment.flightNumber && (
                    <div className="mt-1.5 text-center text-[10px] font-semibold text-slate-400">
                        {segment.flightNumber}
                    </div>
                )}
            </div>
            <FlightEndpoint
                align="right"
                code={segment.destination}
                time={segment.arrivalHour}
                date={segment.date}
            />
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

function FeatureTable({ rows }: { rows: FeatureRow[] }) {
    const { t } = useTranslation();

    return (
        <div className="overflow-hidden rounded-md border border-slate-200 bg-white">
            <table className="w-full table-fixed text-left text-xs">
                <colgroup>
                    <col className="w-[42%]" />
                    <col className="w-[58%]" />
                </colgroup>
                <thead className="font-condensed text-[10px] font-black tracking-normal text-slate-500 uppercase">
                    <tr className="border-b border-slate-200">
                        <th className="px-3 py-2">{t('chat.feature')}</th>
                        <th className="px-3 py-2">{t('chat.value')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr
                            key={`${row.label}-${index}`}
                            className="border-t border-slate-100"
                        >
                            <td className="px-3 py-2 font-black break-words text-slate-900">
                                {row.label}
                            </td>
                            <td className="px-3 py-2 font-semibold break-words text-slate-700">
                                {row.value}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function OfferComparisonModal({
    cards,
    customer,
    onClose,
    onSelect,
}: {
    cards: OfferCard[];
    customer?: CustomerSummary;
    onClose: () => void;
    onSelect: (card: OfferCard) => void;
}) {
    const { locale, t } = useTranslation();
    const cardRows = cards.map((card) => ({
        card,
        rows: featureRowsFromCard(card.raw, t, locale),
    }));
    const featureLabels = Array.from(
        new Set(cardRows.flatMap((entry) => entry.rows.map((row) => row.label))),
    );

    function valueFor(cardKey: string, label: string): string {
        const entry = cardRows.find((item) => item.card.key === cardKey);
        const row = entry?.rows.find((feature) => feature.label === label);

        return row?.value ?? t('chat.notAvailable');
    }

    return (
        <div className="fixed inset-0 z-[75] animate-in bg-white duration-200 fade-in-50">
            <div className="flex h-dvh min-h-0 w-full flex-col bg-white">
                <div className="flex items-start justify-between gap-4 bg-slate-950 px-5 py-4 text-white sm:px-8">
                    <div>
                        <div className="font-condensed text-xs font-bold tracking-wide text-white/70 uppercase">
                            {t('chat.offerComparison')}
                        </div>
                        <h2 className="mt-1 font-display text-xl font-black">
                            {t('chat.compareOffers')}
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

                <div className="min-h-0 flex-1 overflow-auto p-4 sm:p-8">
                    <table className="w-full table-fixed text-left text-sm">
                        <colgroup>
                            <col className="w-[26%]" />
                            {cards.map((card) => (
                                <col key={card.key} />
                            ))}
                        </colgroup>
                        <thead>
                            <tr className="border-b border-slate-200">
                                <th className="px-3 py-3 font-condensed text-[10px] font-black tracking-normal text-slate-500 uppercase">
                                    {t('chat.feature')}
                                </th>
                                {cards.map((card) => (
                                    <th
                                        key={card.key}
                                        className="border-l border-slate-200 px-3 py-3 align-top"
                                    >
                                        <span className="block font-display text-base font-black text-slate-950">
                                            {offerTitle(card, customer, locale)}
                                        </span>
                                        <span className="mt-1 block text-lg font-black text-red-800">
                                            {card.price}
                                        </span>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="border-b border-slate-100">
                                <td className="bg-slate-50 px-3 py-3 font-black text-slate-900">
                                    {t('chat.flight')}
                                </td>
                                {cards.map((card) => (
                                    <td
                                        key={`${card.key}-route`}
                                        className="border-l border-slate-200 px-3 py-3 font-semibold text-slate-700"
                                    >
                                        {card.route ??
                                            routeLabel(card.segments)}
                                    </td>
                                ))}
                            </tr>
                            {featureLabels.map((label, index) => (
                                <tr
                                    key={label}
                                    className={`border-b border-slate-100 ${index % 2 === 0 ? 'bg-white' : 'bg-slate-50/70'}`}
                                >
                                    <td className="px-3 py-3 font-black text-slate-900">
                                        {label}
                                    </td>
                                    {cards.map((card) => (
                                        <td
                                            key={`${card.key}-${label}`}
                                            className="border-l border-slate-200 px-3 py-3 font-semibold text-slate-700"
                                        >
                                            {valueFor(card.key, label)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t border-slate-300 bg-slate-50">
                                <td className="px-3 py-3 text-xs font-black tracking-normal text-slate-500 uppercase">
                                    {t('chat.checkout')}
                                </td>
                                {cards.map((card) => (
                                    <td
                                        key={`${card.key}-select`}
                                        className="border-l border-slate-200 px-3 py-3"
                                    >
                                        <button
                                            type="button"
                                            className="inline-flex h-10 w-full items-center justify-center rounded-md bg-red-800 px-3 text-xs font-black text-white transition-colors hover:bg-red-900"
                                            onClick={() => onSelect(card)}
                                        >
                                            {t('chat.checkout')}
                                        </button>
                                    </td>
                                ))}
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    );
}

function WingoCheckoutModal({
    target,
    customer,
    onClose,
}: {
    target: OfferCard;
    customer?: CustomerSummary;
    onClose: () => void;
}) {
    const { locale, t } = useTranslation();
    const [buyer, setBuyer] = useState({
        first_name: '',
        last_name: '',
        email: '',
        passport_number: '',
    });

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
                            {offerTitle(target, customer, locale)} ·{' '}
                            {target.price}
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
                    <div className="grid gap-2">
                        {target.segments.map((segment, index) => (
                            <SegmentRow
                                key={`checkout-segment-${index}`}
                                segment={segment}
                            />
                        ))}
                    </div>

                    <FeatureTable
                        rows={featureRowsFromCard(target.raw, t, locale)}
                    />

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
                        className="h-12 rounded-md bg-red-800 text-sm font-black text-white transition-colors hover:bg-red-900 disabled:bg-slate-300"
                    >
                        {t('chat.confirmPurchase')}
                    </button>
                </div>
            </form>
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
                className="h-11 rounded-md border border-slate-200 px-3 text-sm font-semibold transition-colors outline-none focus:border-red-800"
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
