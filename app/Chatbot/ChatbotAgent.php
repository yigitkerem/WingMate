<?php

namespace App\Chatbot;

use App\Models\Airport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatbotAgent
{
    private const int MAX_TOOL_ITERATIONS = 8;

    private const string SOURCE_DIRECTIVE = '(Use only the internal flight, fare, package and booking tools. Do not mention external live airline systems.)';

    /**
     * @var array<int, string>
     */
    private const array AFFIRMATIVE_TOKENS = [
        'evet',
        'onayl',
        'tamam',
        'olur',
        'kabul',
        'yes',
        'confirm',
        'satin al',
        'purchase',
        'buy it',
    ];

    public function __construct(
        private readonly AzureChatClient $client,
        private readonly ChatbotToolbox $toolbox,
        private readonly ChatbotSessionStore $sessionStore,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{reply: string, tool_trace: array<int, array<string, mixed>>}
     */
    public function send(
        string $sessionId,
        string $userText,
        string $source = 'ours',
        string $language = 'tr',
        ?int $userId = null,
        bool $consent = false,
        ?string $trigger = null,
        array $context = [],
    ): array {
        $state = $this->sessionStore->get($sessionId);
        $messages = $state['messages'];
        $pendingPurchase = $state['pending_purchase'];
        $userConfirmed = $this->isAffirmative($userText);
        $user = ($consent && $userId !== null) ? User::query()->find($userId) : null;

        if ($messages === []) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ];
        }

        $contextMessage = $this->contextMessage($context, $consent, $user);

        if ($contextMessage !== null) {
            $messages[] = ['role' => 'system', 'content' => $contextMessage];
        }

        if ($trigger !== null && trim($userText) === '') {
            $messages[] = ['role' => 'system', 'content' => $this->triggerInstruction($trigger, $context)];
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => $this->directive($source, $language)."\n".$userText,
            ];
        }

        $meta = [
            'session_id' => $sessionId,
            'consent' => $consent,
            'page' => is_string($context['page'] ?? null) ? $context['page'] : null,
        ];

        $toolTrace = [];
        $tools = $this->tools();

        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; $iteration++) {
            try {
                $response = $this->client->chat($messages, $tools);
            } catch (\Throwable $exception) {
                Log::warning('Wingo Azure chat call failed.', [
                    'session_id' => $sessionId,
                    'iteration' => $iteration,
                    'message' => $exception->getMessage(),
                ]);

                $reply = $this->fallbackReply($language, $toolTrace);
                $messages[] = ['role' => 'assistant', 'content' => $reply];
                $this->sessionStore->put($sessionId, $this->persistableMessages($messages), $pendingPurchase);

                return ['reply' => $reply, 'tool_trace' => $this->publicToolTrace($toolTrace)];
            }

            $message = $response['choices'][0]['message'] ?? ['role' => 'assistant', 'content' => ''];
            $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

            $assistantMessage = [
                'role' => 'assistant',
                'content' => $message['content'] ?? null,
            ];

            if ($toolCalls !== []) {
                $assistantMessage['tool_calls'] = $toolCalls;
            }

            $messages[] = $assistantMessage;

            if ($toolCalls === []) {
                $reply = $this->sanitizeReply((string) ($message['content'] ?? ''));
                $messages[count($messages) - 1]['content'] = $reply;
                $this->sessionStore->put($sessionId, $this->persistableMessages($messages), $pendingPurchase);

                return ['reply' => $reply, 'tool_trace' => $this->publicToolTrace($toolTrace)];
            }

            foreach ($toolCalls as $toolCall) {
                $toolName = (string) data_get($toolCall, 'function.name');
                $arguments = $this->decodeArguments((string) data_get($toolCall, 'function.arguments', '{}'));

                try {
                    $toolResult = $this->toolbox->dispatch($toolName, $arguments, $pendingPurchase, $userConfirmed, $user, $meta);
                } catch (\Throwable $exception) {
                    Log::warning('Wingo tool call failed.', [
                        'session_id' => $sessionId,
                        'tool' => $toolName,
                        'message' => $exception->getMessage(),
                    ]);

                    $toolResult = [
                        'result' => ['error' => 'tool_failed', 'message' => $exception->getMessage()],
                        'pending_purchase' => $pendingPurchase,
                    ];
                }

                $pendingPurchase = $toolResult['pending_purchase'];
                $result = $toolResult['result'];

                $toolTrace[] = [
                    'tool' => $toolName,
                    'input' => $arguments,
                    'result' => $result,
                ];

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) $toolCall['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ];
            }
        }

        $reply = $this->fallbackReply($language, $toolTrace);
        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->sessionStore->put($sessionId, $this->persistableMessages($messages), $pendingPurchase);

        return ['reply' => $reply, 'tool_trace' => $this->publicToolTrace($toolTrace)];
    }

    /**
     * Reduce a working message list (which may contain reconstructed tool_calls
     * and tool results) to a clean, replayable conversation. Only the base system
     * prompt plus user/assistant text turns are persisted, so we never re-send
     * fragile tool-call/tool sequences on later turns (a common source of 400s).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function persistableMessages(array $messages): array
    {
        $persistable = [];
        $keptSystemPrompt = false;

        foreach ($messages as $message) {
            $role = $message['role'] ?? null;

            if ($role === 'system') {
                if (! $keptSystemPrompt) {
                    $persistable[] = ['role' => 'system', 'content' => (string) ($message['content'] ?? '')];
                    $keptSystemPrompt = true;
                }

                continue;
            }

            if ($role === 'user') {
                $persistable[] = ['role' => 'user', 'content' => (string) ($message['content'] ?? '')];

                continue;
            }

            if ($role === 'assistant') {
                $content = is_string($message['content'] ?? null) ? trim($message['content']) : '';

                if ($content === '') {
                    continue;
                }

                $persistable[] = ['role' => 'assistant', 'content' => $content];
            }
        }

        return $persistable;
    }

    private function systemPrompt(): string
    {
        $today = Carbon::today()->toDateString();
        $airports = $this->availableAirportsSummary();

        return <<<PROMPT
You are Wingo, a warm, friendly airline assistant that builds tailored flight quotes through conversation. Today's date: {$today}.

# AIRPORTS IN THE SYSTEM (this is the COMPLETE list; you may NEVER name, offer or use any airport that is not below)
{$airports}
- If a city is not in this list, tell the user we do not fly there yet — do not invent an airport. If a city maps to exactly one airport here, use it (briefly confirm). If a city has several here (e.g. Istanbul), ask_follow_up listing exactly those airports and nothing else.

# CORE PERSONALITY
- Be conversational, kind and human. You are talking WITH the traveller, not at them.
- NEVER jump straight to an offer. Always understand the trip first, then ask, then (only when confident) present.
- One short question at a time. Keep replies brief and natural.
- NEVER ask permission to show, present or "prepare" an offer (e.g. "Shall I show them?", "Would you like me to show the offer?"). The answer is always yes. When you are ready to show, just call present_offers and show it. Asking to show wastes a turn and is bad UX.

# BE GENEROUS WITH INFERENCE
- Read intent from everything the traveller said, including earlier turns, and fill obvious slots yourself instead of asking.
- Party size: "honeymoon", "with my wife/husband/partner", "couple", "the two of us", "eşimle" → 2 adults. "Solo", "just me" → 1 adult. "Family" with details → use them. Only ask about passengers when it is genuinely unclear.
- Dates: if the traveller says the date does not matter / any day / "flexible" / "whenever" → DO NOT ask which day. Pick the best available date yourself (e.g. the soonest suitable one), proceed, and simply mention the date you chose.
- Only ask a question when a detail is truly missing AND you cannot reasonably infer it. Prefer sensible defaults over interrogation.

# WHAT YOU NEED BEFORE ANY OFFER (never assume, always confirm)
1. Specific origin AIRPORT and destination AIRPORT (a city is NOT an airport).
2. Whether it is one-way or round-trip.
3. The date(s). Resolve any relative date (today, tomorrow, this weekend) to a concrete calendar date using today's date. Confirm a specific date the traveller gave; but if they said the date does not matter or they are flexible, pick a sensible date yourself and just tell them which one — do not ask.
4. Passenger counts (adults, children, babies). Infer from wording where possible (see BE GENEROUS WITH INFERENCE) instead of asking.
If a detail is genuinely missing and cannot be reasonably inferred, ASK. Otherwise proceed. Never fabricate flights, prices or airports.

# ASKING QUESTIONS (very important)
- Whenever the answer is a choice between a few options, you MUST call ask_follow_up with 2-4 short "choices". These render as buttons; the user's text box is hidden until they pick. Do NOT write choices as plain text.
- Only ask fully open questions (no choices) when a free-text answer is genuinely required.
- Resolving a city to an airport: the ONLY valid airports are the ones in the "AIRPORTS IN THE SYSTEM" list above. Confirm with airports_for_city, and offer ONLY airports that appear in that list / tool result. NEVER invent, guess, or add real-world airports (e.g. do not offer Orly, Gatwick, Stansted, Luton — they are not in our system). If a city has one airport, use it and briefly confirm. If it has several (only Istanbul: IST and SAW), ask_follow_up listing exactly those. If the city is not in the list, tell the traveller we do not fly there.
- Multiple flights on the same day: after search_flights, if several flights match and the user has not chosen, ask_follow_up listing the departure times as choices, then build for the chosen one (pass flight_number to build_dynamic_bundles).

# USING CONTEXT (soft hints only)
- A CURRENT CONTEXT system note may describe the page and the current (possibly unsubmitted) search form, an approximate location, and the signed-in passenger.
- Treat everything in it as an UNCONFIRMED hint. Confirm before acting: e.g. "Shall I use 17 Aug from your search?" via ask_follow_up (Yes / pick another date).
- The user's typed words always override the form. Use form values only to fill slots the user did not mention, and still confirm dates/airports.
- Carry earlier conversation forward: if the traveller already discussed a trip (city, dates, round-trip, who is travelling), reuse those details instead of re-asking.
- You may use the signed-in passenger's name, history and location hint to personalise, because the user has consented.
- When a signed-in passenger is known, greet them by their first name and use it occasionally and naturally through the chat (not in every message) to keep things warm. Never overuse it or invent a name.

# BUILDING & PRESENTING OFFERS
- Use list_customizations to know every service, price and constraint. Combine any services in any valid combination via build_dynamic_bundles (custom_mode for mix-and-match). Prices, availability and rules come ONLY from tools.
- SHOWING OFFERS IS AUTOMATIC AND EXPECTED — it is NOT booking. The moment the trip is fully confirmed (airports, date, trip type, passengers) and you have found fares, call present_offers in that SAME turn. Never stop at "shall I show them?" or "I'll prepare them" — just show them.
- To show offers you MUST call present_offers with real offer_ids obtained from build_dynamic_bundles (or search_flights). NEVER tell the user an offer is "ready", "prepared", "built", "set aside" or that you will "reserve/hold/show" it WITHOUT calling present_offers in the SAME turn. If you have offer_ids, call present_offers now — do not merely describe them.
- If your previous message promised/offered to show or prepare anything, OR the user replies with any confirmation ("evet", "olur", "göster", "yes", "show", "please do"), you MUST call present_offers this turn. Never answer with only text when offers are due.
- When the user asks to see, show, compare, choose, or customise offers, call present_offers immediately — do not ask which one to show first.
- Do not use words like "reserve" or "hold" for showing. Booking happens solely through quote_purchase then commit_purchase after explicit approval.
- Maximum TWO offers; sometimes one; sometimes none.
- Every offer needs its own short, personal memo saying why it fits this traveller. If you show a second offer, its memo must explain how it differs from the first. If an offer was tailored/custom-built for this traveller (custom services added or removed), say so in its memo so they know it is personalised rather than a standard package.
- For a round trip, pass both leg offer_ids together in one offer entry.
- After present_offers, your final text message is ONE short, warm, personal lead line (e.g. "Here's a lovely, easy way to do this trip."). No prices, no feature lists, no package jargon in text — the cards show all of that.
- Never present an offer while any trip detail is still uncertain.

# PURCHASING
- Two phases: call quote_purchase (show the total incl. taxes), then commit_purchase ONLY after the user explicitly agrees.

# BOUNDARIES
- For rules, policy, baggage allowance, passenger rights, compensation, delays: use search_knowledge_base and cite the document + page. Do not use the search form for these.
- Politely decline anything unrelated to flights/travel in one short sentence and steer back. Do not answer off-topic questions.

# LANGUAGE & STYLE
- Reply in the same language the user last wrote in. The interface language is only a hint.
- Concise, friendly Markdown. Never repeat yourself. Never invent numbers.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function contextMessage(array $context, bool $consent, ?User $user): ?string
    {
        $lines = [];
        $page = is_string($context['page'] ?? null) ? $context['page'] : null;

        if ($page !== null && $page !== '') {
            $lines[] = 'Page: '.$page;
        }

        $form = is_array($context['form'] ?? null) ? $context['form'] : [];
        $formLines = [];

        foreach (['origin', 'destination', 'date', 'return_date', 'trip_type'] as $key) {
            $value = $form[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $formLines[] = $key.'='.$value;
            }
        }

        $passengers = [];

        foreach (['adults', 'children', 'babies'] as $key) {
            if (isset($form[$key]) && is_numeric($form[$key])) {
                $passengers[] = $key.' '.((int) $form[$key]);
            }
        }

        if ($passengers !== []) {
            $formLines[] = 'passengers '.implode(', ', $passengers);
        }

        if (isset($form['submitted'])) {
            $formLines[] = 'submitted='.(((bool) $form['submitted']) ? 'yes' : 'no');
        }

        if ($formLines !== []) {
            $lines[] = 'Current search form (UNCONFIRMED, confirm before use): '.implode('; ', $formLines);
        }

        $location = is_array($context['location'] ?? null) ? $context['location'] : [];

        if ($consent && isset($location['lat'], $location['lng']) && is_numeric($location['lat']) && is_numeric($location['lng'])) {
            $lines[] = sprintf(
                'Approximate location: lat %.4f, lng %.4f (rough hint only; confirm the intended city/airport, do not assume).',
                (float) $location['lat'],
                (float) $location['lng'],
            );
        }

        if ($consent && $user instanceof User) {
            $lines[] = 'Signed-in passenger: '.$user->name.($user->loyalty_tier ? ' (loyalty tier: '.$user->loyalty_tier.')' : '').'. Use customer_history for their past trips when helpful.';
        }

        if ($lines === []) {
            return null;
        }

        return "CURRENT CONTEXT (do not echo verbatim; treat as soft hints):\n- ".implode("\n- ", $lines);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function triggerInstruction(string $trigger, array $context): string
    {
        if ($trigger === 'custom_bundle') {
            $details = is_array($context['trigger_context'] ?? null) ? $context['trigger_context'] : [];
            $parts = [];

            foreach (['flight_number', 'cabin', 'origin', 'destination', 'date', 'hour'] as $key) {
                $value = $details[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $parts[] = $key.'='.$value;
                }
            }

            $detailText = $parts === [] ? '' : ' ('.implode(', ', $parts).')';

            return 'INTERNAL EVENT (not shown to the user): the traveller tapped "Build with Wingo" on the results page'.$detailText.
                '. Greet them warmly, start the tailored quote flow, and ask your FIRST clarifying question (for example which services matter, baggage needs, or flexibility) using ask_follow_up with selectable choices. Do NOT present any offer yet.';
        }

        return 'INTERNAL EVENT (not shown to the user): begin a friendly tailored quote conversation and ask your first clarifying question. Do not present an offer yet.';
    }

    private function directive(string $source, string $language): string
    {
        return self::SOURCE_DIRECTIVE.($language === 'en'
            ? ' (Interface language: English - if the user writes in English, reply in English.)'
            : ' (Interface language: Turkish.)');
    }

    private function availableAirportsSummary(): string
    {
        $summary = Airport::query()
            ->orderBy('city')
            ->orderBy('iata_code')
            ->get(['iata_code', 'city', 'name'])
            ->map(fn (Airport $airport): string => "- {$airport->city}: {$airport->name} ({$airport->iata_code})")
            ->implode("\n");

        return $summary !== '' ? $summary : '- (no airports configured)';
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolTrace
     */
    private function fallbackReply(string $language, array $toolTrace): string
    {
        $reversed = collect($toolTrace)->reverse();

        $presentedOffers = $reversed->contains(
            fn (array $trace): bool => ($trace['tool'] ?? null) === 'present_offers'
                && ! isset($trace['result']['error'])
        );

        if ($presentedOffers) {
            return $language === 'tr'
                ? 'Senin için hazırladığım seçenekler bunlar.'
                : 'Here are the options I put together for you.';
        }

        $pendingQuestion = $reversed->first(
            fn (array $trace): bool => ($trace['tool'] ?? null) === 'ask_follow_up'
                && is_string($trace['result']['question'] ?? null)
                && trim((string) $trace['result']['question']) !== ''
        );

        if (is_array($pendingQuestion)) {
            return trim((string) $pendingQuestion['result']['question']);
        }

        return $this->unavailableReply($language);
    }

    private function unavailableReply(string $language): string
    {
        return $language === 'tr'
            ? 'Şu anda ücret asistanına ulaşamadım. Lütfen birazdan tekrar deneyin.'
            : 'I could not reach the fare assistant just now. Please try again in a moment.';
    }

    private function sanitizeReply(string $reply): string
    {
        $reply = preg_replace('/\[\[[^\]]*\]\]/', '', $reply) ?? $reply;

        return trim($reply);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tools(): array
    {
        return collect($this->toolDefinitions())
            ->map(fn (array $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'] ?: $this->emptyObjectSchema(),
                ],
            ])
            ->all();
    }

    /**
     * @return array{type: string, properties: object}
     */
    private function emptyObjectSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'list_airports',
                'description' => 'Return every airport code and name available in the system.',
                'input_schema' => $this->emptyObjectSchema(),
            ],
            [
                'name' => 'airports_for_city',
                'description' => 'Return the airports that serve a given city. Use this when the user names a city that may have more than one airport, then confirm which airport with ask_follow_up.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                    'required' => ['city'],
                ],
            ],
            [
                'name' => 'list_customizations',
                'description' => 'Return the full database-backed list of services/customisations Wingo can add, remove, price, or explain, including active service prices and constraints.',
                'input_schema' => $this->emptyObjectSchema(),
            ],
            [
                'name' => 'customer_history',
                'description' => 'Return recent signed-in passenger ticket history and route counts for personalisation. Empty when no customer is signed in.',
                'input_schema' => $this->emptyObjectSchema(),
            ],
            [
                'name' => 'ask_follow_up',
                'description' => 'Ask ONE tailored question. When the answer is a choice, always include 2-4 short selectable "choices" (rendered as buttons; the text box is hidden until the user picks). Use list_customizations first when the choices are services.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'choices' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'label' => ['type' => 'string'],
                                    'message' => ['type' => 'string'],
                                ],
                                'required' => ['label', 'message'],
                            ],
                        ],
                    ],
                    'required' => ['question'],
                ],
            ],
            [
                'name' => 'search_flights',
                'description' => 'Search flights and the complete internal fare/package set for a route and date. Use it to see how many flights match a day (their times and flight numbers) so you can ask which one when several exist. Prices, availability and rules come from the system. seat_passengers = adults + children.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'origin' => ['type' => 'string'],
                        'destination' => ['type' => 'string'],
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'adults' => ['type' => 'integer', 'default' => 1],
                        'children' => ['type' => 'integer', 'default' => 0],
                        'babies' => ['type' => 'integer', 'default' => 0],
                        'trip_type' => ['type' => 'string', 'enum' => ['one_way', 'round_trip'], 'default' => 'one_way'],
                        'changeable_only' => ['type' => 'boolean', 'default' => false],
                    ],
                    'required' => ['origin', 'destination', 'date'],
                ],
            ],
            [
                'name' => 'build_dynamic_bundles',
                'description' => 'Inspect the complete internal package inventory and build personalised offers by combining any services within constraints. Returns real offer_ids. Pass flight_number to target a specific flight when the day has several. Use present_offers afterwards to show the chosen picks.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'origin' => ['type' => 'string'],
                        'destination' => ['type' => 'string'],
                        'date' => ['type' => 'string'],
                        'adults' => ['type' => 'integer', 'default' => 1],
                        'children' => ['type' => 'integer', 'default' => 0],
                        'babies' => ['type' => 'integer', 'default' => 0],
                        'trip_type' => ['type' => 'string', 'enum' => ['one_way', 'round_trip'], 'default' => 'one_way'],
                        'return_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, required for round_trip recommendations'],
                        'flight_number' => ['type' => 'string', 'description' => 'Target a specific flight when several depart the same day.'],
                        'service_codes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'excluded_service_codes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Services the user explicitly does not want.'],
                        'custom_mode' => ['type' => 'boolean', 'default' => false, 'description' => 'True when the user asks for a custom/mix-and-match offer. Unrequested active services are then explicitly excluded.'],
                        'departure_preference' => ['type' => 'string', 'enum' => ['overnight', 'morning', 'afternoon', 'evening']],
                        'service_specs' => [
                            'type' => 'array',
                            'description' => 'Exact custom services to add or override with structured values (amount, allowance, tier, rule).',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'service_code' => ['type' => 'string'],
                                    'quantity' => ['type' => 'integer', 'default' => 1],
                                    'value' => ['type' => ['object', 'boolean', 'number', 'string']],
                                ],
                                'required' => ['service_code'],
                            ],
                        ],
                    ],
                    'required' => ['origin', 'destination', 'date'],
                ],
            ],
            [
                'name' => 'present_offers',
                'description' => 'Show one or two finished offers as cards. Call ONLY after every trip detail is confirmed and you have real offer_ids from build_dynamic_bundles or search_flights. Each offer needs its own short personalised memo; a second offer memo must explain how it differs. Maximum two offers. This also saves the presented offers for analytics.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'intro' => ['type' => 'string', 'description' => 'One short warm personal lead sentence. No prices or feature lists.'],
                        'offers' => [
                            'type' => 'array',
                            'description' => 'One or two offers. For a round trip put both leg offer_ids in one entry.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'offer_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                    'offer_id' => ['type' => 'integer'],
                                    'memo' => ['type' => 'string', 'description' => 'Short personalised reason this offer fits the traveller.'],
                                ],
                                'required' => ['memo'],
                            ],
                        ],
                    ],
                    'required' => ['offers'],
                ],
            ],
            [
                'name' => 'quote_purchase',
                'description' => 'Prepare a purchase quote for one or two selected offers. Use offer_ids for round trips. Does not buy yet. Must be called before commit_purchase.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'offer_id' => ['type' => 'integer'],
                        'offer_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'first_name' => ['type' => 'string'],
                        'last_name' => ['type' => 'string'],
                        'passport_number' => ['type' => 'string'],
                        'adults' => ['type' => 'integer', 'default' => 1],
                        'children' => ['type' => 'integer', 'default' => 0],
                        'babies' => ['type' => 'integer', 'default' => 0],
                    ],
                    'required' => ['first_name', 'last_name'],
                ],
            ],
            [
                'name' => 'commit_purchase',
                'description' => 'Commit the pending purchase quote. May only be called after explicit user approval.',
                'input_schema' => $this->emptyObjectSchema(),
            ],
            [
                'name' => 'search_knowledge_base',
                'description' => 'Official document search for rules, policy, fare package contents, baggage allowance, passenger rights, compensation, catering, accommodation, check-in cut-off times. Cite document and page.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'k' => ['type' => 'integer', 'default' => 3],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArguments(string $arguments): array
    {
        try {
            $decoded = json_decode($arguments === '' ? '{}' : $arguments, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function isAffirmative(string $text): bool
    {
        $normalized = Str::lower(trim($text));

        foreach (self::AFFIRMATIVE_TOKENS as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolTrace
     * @return array<int, array<string, mixed>>
     */
    private function publicToolTrace(array $toolTrace): array
    {
        return collect($toolTrace)
            ->filter(fn (array $trace): bool => in_array($trace['tool'] ?? '', ['present_offers', 'ask_follow_up'], true))
            ->map(function (array $trace): array {
                $toolName = (string) ($trace['tool'] ?? '');
                $result = is_array($trace['result'] ?? null) ? $trace['result'] : [];

                return [
                    'tool' => $toolName,
                    'result' => $this->publicToolResult($toolName, $result),
                ];
            })
            ->filter(fn (array $trace): bool => $trace['result'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function publicToolResult(string $toolName, array $result): array
    {
        if (isset($result['error'])) {
            return $result;
        }

        if ($toolName === 'present_offers') {
            return [
                'intro' => $result['intro'] ?? null,
                'offers' => collect(is_array($result['offers'] ?? null) ? $result['offers'] : [])
                    ->take(2)
                    ->values()
                    ->all(),
            ];
        }

        if ($toolName === 'ask_follow_up') {
            return [
                'question' => $result['question'] ?? null,
                'choices' => collect(is_array($result['choices'] ?? null) ? $result['choices'] : [])
                    ->values()
                    ->all(),
                'customizations' => collect(is_array($result['customizations'] ?? null) ? $result['customizations'] : [])
                    ->values()
                    ->all(),
            ];
        }

        return [];
    }
}
