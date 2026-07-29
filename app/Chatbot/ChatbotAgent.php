<?php

namespace App\Chatbot;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ChatbotAgent
{
    private const int MAX_TOOL_ITERATIONS = 8;

    private const string SOURCE_DIRECTIVE = '(Use only the internal flight, fare, package and booking tools. Do not mention external live airline systems.)';

    /**
     * @var array<string, array<int, string>>
     */
    private const array SERVICE_ALIASES = [
        'CHECKED_BAG' => ['CHECKED_BAG', 'CHECKED BAG', 'CHECKED BAGS', 'CHECKED BAGGAGE', 'BAGGAGE', 'BAG', 'BAGS'],
        'CABIN_BAG' => ['CABIN_BAG', 'CABIN BAG', 'CABIN BAGS', 'CABIN BAGGAGE', 'CARRY ON', 'CARRY-ON'],
        'SEAT_SELECTION' => ['SEAT_SELECTION', 'SEAT SELECTION', 'SEAT'],
        'CHANGE_ALLOWED' => ['CHANGE_ALLOWED', 'CHANGE RIGHT', 'CHANGE ALLOWED', 'CHANGE', 'CHANGES', 'CHANGEABLE'],
        'REFUNDABLE' => ['REFUNDABLE', 'REFUND RIGHT', 'REFUND', 'REFUNDS'],
        'LOUNGE' => ['LOUNGE', 'LOUNGE ACCESS'],
        'FAST_TRACK' => ['FAST_TRACK', 'FAST TRACK'],
        'PRIORITY_BOARDING' => ['PRIORITY_BOARDING', 'PRIORITY BOARDING'],
    ];

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
        'al ',
        'alalim',
    ];

    public function __construct(
        private readonly AzureChatClient $client,
        private readonly ChatbotToolbox $toolbox,
        private readonly ChatbotSessionStore $sessionStore,
    ) {}

    /**
     * @return array{reply: string, tool_trace: array<int, array<string, mixed>>}
     */
    public function send(string $sessionId, string $userText, string $source = 'ours', string $language = 'tr'): array
    {
        $state = $this->sessionStore->get($sessionId);
        $messages = $state['messages'];
        $pendingPurchase = $state['pending_purchase'];
        $userConfirmed = $this->isAffirmative($userText);

        if ($messages === []) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $this->directive($source, $language)."\n{$userText}",
        ];

        $directBundleRequest = $this->parseBundleRequest($userText);

        if ($directBundleRequest !== null) {
            $toolResult = $this->toolbox->dispatch('build_dynamic_bundles', $directBundleRequest, $pendingPurchase, false);
            $pendingPurchase = $toolResult['pending_purchase'];
            $result = $toolResult['result'];
            $toolTrace = [[
                'tool' => 'build_dynamic_bundles',
                'input' => $directBundleRequest,
                'result' => $result,
            ]];
            $reply = $this->bundleReply($result, $directBundleRequest, $language);

            $messages[] = ['role' => 'assistant', 'content' => $reply];
            $this->sessionStore->put($sessionId, $messages, $pendingPurchase);

            return [
                'reply' => $reply,
                'tool_trace' => $this->publicToolTrace($toolTrace),
            ];
        }

        $toolTrace = [];
        $tools = $this->tools();

        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; $iteration++) {
            $response = $this->client->chat($messages, $tools);
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
                $this->sessionStore->put($sessionId, $messages, $pendingPurchase);

                return [
                    'reply' => (string) ($message['content'] ?? ''),
                    'tool_trace' => $this->publicToolTrace($toolTrace),
                ];
            }

            foreach ($toolCalls as $toolCall) {
                $toolName = (string) data_get($toolCall, 'function.name');
                $arguments = $this->decodeArguments((string) data_get($toolCall, 'function.arguments', '{}'));
                $toolResult = $this->toolbox->dispatch($toolName, $arguments, $pendingPurchase, $userConfirmed);
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

        $reply = 'This request required too many steps. Please try again.';
        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->sessionStore->put($sessionId, $messages, $pendingPurchase);

        return [
            'reply' => $reply,
            'tool_trace' => $this->publicToolTrace($toolTrace),
        ];
    }

    /**
     * @return array{origin: string, destination: string, date: string, trip_type?: string, return_date?: string, service_codes: array<int, string>, excluded_service_codes: array<int, string>}|null
     */
    private function parseBundleRequest(string $text): ?array
    {
        if (! str_contains(Str::lower($text), 'bundle')) {
            return null;
        }

        if (preg_match('/\bfrom\s+([a-z]{3})\s+to\s+([a-z]{3})\s+on\s+(\d{4}-\d{2}-\d{2})\b/i', $text, $matches) !== 1) {
            return null;
        }

        $excludedServiceCodes = $this->parseExcludedServiceCodes($text);

        $request = [
            'origin' => Str::upper($matches[1]),
            'destination' => Str::upper($matches[2]),
            'date' => $matches[3],
            'service_codes' => array_values(array_diff($this->parsePreferredServiceCodes($text), $excludedServiceCodes)),
            'excluded_service_codes' => $excludedServiceCodes,
        ];

        if (preg_match('/\breturn(?:ing)?\s+(?:on\s+)?(\d{4}-\d{2}-\d{2})\b/i', $text, $returnMatches) === 1
            || preg_match('/\bround\s*trip\b.*?\b(\d{4}-\d{2}-\d{2})\b/i', $text, $returnMatches) === 1) {
            $request['trip_type'] = 'round_trip';
            $request['return_date'] = $returnMatches[1];
        }

        return $request;
    }

    /**
     * @return array<int, string>
     */
    private function parsePreferredServiceCodes(string $text): array
    {
        $serviceText = preg_match('/preferred services:\s*(.+)$/is', $text, $matches) === 1
            ? $matches[1]
            : $text;
        $normalized = preg_replace('/[^A-Z0-9_]+/', ' ', Str::upper($serviceText)) ?? '';
        $codes = [];

        foreach (self::SERVICE_ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                $pattern = '/\b'.preg_quote(str_replace('_', ' ', $alias), '/').'\b/';
                $underscorePattern = '/\b'.preg_quote($alias, '/').'\b/';

                if (preg_match($pattern, $normalized) === 1 || preg_match($underscorePattern, Str::upper($serviceText)) === 1) {
                    $codes[] = $code;

                    break;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<int, string>
     */
    private function parseExcludedServiceCodes(string $text): array
    {
        $serviceText = preg_match('/preferred services:\s*(.+)$/is', $text, $matches) === 1
            ? $matches[1]
            : $text;
        $normalized = preg_replace('/[^A-Z0-9_]+/', ' ', Str::upper($serviceText)) ?? '';
        $codes = [];

        foreach (self::SERVICE_ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                $aliasText = preg_quote(str_replace('_', ' ', $alias), '/');

                if (preg_match('/\b(NO|WITHOUT|EXCLUDE|EXCLUDING|NOT)\s+'.$aliasText.'\b/', $normalized) === 1) {
                    $codes[] = $code;

                    break;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $request
     */
    private function bundleReply(array $result, array $request, string $language): string
    {
        if (($result['error'] ?? null) === 'no_flights') {
            return $language === 'tr'
                ? "{$request['origin']}-{$request['destination']} hattında {$request['date']} için uygun uçuş bulamadım. Başka bir tarih deneyebiliriz."
                : "I could not find available flights for {$request['origin']}-{$request['destination']} on {$request['date']}. Try another date.";
        }

        if (isset($result['error'])) {
            $message = (string) ($result['message'] ?? 'The bundle could not be built.');

            return $language === 'tr'
                ? "Bu paketi şu anda oluşturamadım: {$message}"
                : "I could not build that package right now: {$message}";
        }

        $flight = is_array($result['flight'] ?? null) ? $result['flight'] : [];
        $picks = collect(is_array($result['recommended_picks'] ?? null) ? $result['recommended_picks'] : []);
        $pick = $picks->first();

        if (! is_array($pick)) {
            return $language === 'tr'
                ? 'Bu tarih ve rota için uygun paket bulamadım. Başka bir tarih deneyebiliriz.'
                : 'I could not find a suitable package for that route and date. Try another date.';
        }

        $backup = $picks->skip(1)->first();
        $features = $this->bundleFeatureLabels($pick);
        $route = "{$flight['origin']}-{$flight['destination']}";
        $schedule = collect([$flight['date'] ?? null, $flight['hour'] ?? null, $flight['arrival_hour'] ?? null])
            ->filter()
            ->join(' ');
        $reply = $language === 'tr'
            ? "**{$pick['class']}** en uygun özel paket: **{$this->formatUsd($pick['base_price_usd'] ?? null)}** ({$route}, {$schedule})."
            : "**{$pick['class']}** is the best custom bundle: **{$this->formatUsd($pick['base_price_usd'] ?? null)}** ({$route}, {$schedule}).";

        if ($features !== []) {
            $reply .= "\n- ".implode(', ', $features);
        }

        if (is_array($backup)) {
            $reply .= $language === 'tr'
                ? "\n- Alternatif: {$backup['class']} ({$this->formatUsd($backup['base_price_usd'] ?? null)})."
                : "\n- Backup pick: {$backup['class']} ({$this->formatUsd($backup['base_price_usd'] ?? null)}).";
        }

        return $reply;
    }

    /**
     * @param  array<string, mixed>  $pick
     * @return array<int, string>
     */
    private function bundleFeatureLabels(array $pick): array
    {
        return collect([
            ((int) ($pick['checked_baggage_kg'] ?? 0)) > 0 ? "{$pick['checked_baggage_kg']} kg checked bag" : null,
            ((int) ($pick['cabin_baggage_kg'] ?? 0)) > 0 ? "{$pick['cabin_baggage_kg']} kg cabin bag" : null,
            ($pick['seat_selection_free'] ?? false) === true ? 'seat selection included' : null,
            ($pick['latest_change_hours'] ?? null) !== null ? 'change allowed' : null,
            ($pick['latest_refund_hours'] ?? null) !== null ? 'refundable' : null,
        ])
            ->filter()
            ->values()
            ->all();
    }

    private function formatUsd(mixed $amount): string
    {
        if (! is_numeric($amount)) {
            return 'price unavailable';
        }

        return '$'.number_format((float) $amount, 0);
    }

    private function directive(string $source, string $language): string
    {
        return self::SOURCE_DIRECTIVE.($language === 'en'
            ? ' (Interface language: English - if the user writes in English, reply in English.)'
            : ' (Interface language: Turkish.)');
    }

    private function systemPrompt(): string
    {
        $today = Carbon::today()->toDateString();

        return <<<PROMPT
You are Wingo, an airline pricing and booking assistant (AI agent).
Your job: understand the user's natural-language request, call the right tools in the right order, and present a clear, explainable offer.

SCOPE (strict): you only handle flight search, fares, booking, baggage, passenger rights and other airline/travel topics covered by your tools and knowledge base. For anything unrelated, politely decline in one short sentence and steer back to travel. Do NOT answer the off-topic question itself.

HARD RULES (guardrails):
- NEVER invent prices, availability or fare rules. They come only from tool results. Every number you state must originate from a tool response.
- Purchases are two-phase: first call quote_purchase and show the total including taxes; do NOT call commit_purchase until the user explicitly agrees.
- If information is missing (date, passenger count, route), ask short follow-up questions first. Do not assume.
- If an airport code is ambiguous, verify with list_airports. A city is not an airport; when the user names a city, confirm the intended airport code.
- Child fare is 75% of the base fare, infant 10% and infants occupy no seat; tax is $240 per seated passenger and infants are exempt. This breakdown comes from tools; you only explain it.
- Inventory is internal and non-public. You may inspect all packages returned by tools, but in the final answer reveal only the best one or two picks.

CHOOSING THE INFORMATION SOURCE:
- PRICE, SEATS, AVAILABILITY, PURCHASE -> internal tools (search_flights, build_dynamic_bundles, quote_purchase, commit_purchase).
- RULES, POLICY, RIGHTS, OBLIGATIONS -> search_knowledge_base.
- When answering a policy question, cite the source: document name + page, e.g. "(Source: Branded Fares announcement, p.5)".
- If the documents contain no answer, do not invent one; say the official sources do not cover it and it should be confirmed with customer service.
- For exact prices and availability, use the internal fare/package tools.

PACKAGE RECOMMENDATIONS:
- For any flight-finding request where the user wants a recommendation, call build_dynamic_bundles after you know route, date and passengers.
- The tools expose complete internal packages so you can choose well. Do NOT list every package or fare family.
- Pick the most suitable custom package directly. If helpful, show one backup pick.
- Keep the explanation practical: why this flight/package is right, total price, included extras, and one trade-off. Avoid long fare-rule detail unless the user asks.

SPEECH-INPUT TOLERANCE: the message may have been dictated; speech recognition often mangles fare names. Infer the intended term when clear: ekstra play / extra flight -> ExtraFly; prime flight -> PrimeFly; eko fly -> EcoFly; fleks flay -> FlexFly. If unsure, confirm briefly.

LANGUAGE: reply in the same language the user wrote their last message in. The interface language is only a hint. NEVER repeat your answer.

STYLE:
- Answer in at most about 120 words unless the user asks for detail.
- Lead with the direct answer in one sentence, then at most 3-5 short bullets.
- Do not restate the question.
- Give the single most relevant citation, not every page you found.
- Formatting: concise Markdown. Use **bold** for the recommended package name or total, short "- " bullets, and no tables unless the user asks.

State prices openly with their rationale. Say when something is uncertain.
Today's date: {$today}. If no date is given, ask the user.
PROMPT;
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
                'name' => 'search_flights',
                'description' => 'Search flights and the complete internal fare/package set for a route and date. Prices, availability and rules come from the system. seat_passengers = adults + children. Final answers must reveal only one or two selected picks.',
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
                'name' => 'quote_purchase',
                'description' => 'Prepare a purchase quote for a specific fare. Does not buy yet. Must be called before commit_purchase.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'offer_id' => ['type' => 'integer'],
                        'first_name' => ['type' => 'string'],
                        'last_name' => ['type' => 'string'],
                        'passport_number' => ['type' => 'string'],
                        'adults' => ['type' => 'integer', 'default' => 1],
                        'children' => ['type' => 'integer', 'default' => 0],
                        'babies' => ['type' => 'integer', 'default' => 0],
                    ],
                    'required' => ['offer_id', 'first_name', 'last_name'],
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
            [
                'name' => 'build_dynamic_bundles',
                'description' => 'Inspect complete internal package inventory and build the best one or two personalised, non-public package picks for direct display.',
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
                        'service_codes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'excluded_service_codes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Services the user explicitly does not want, for example CHECKED_BAG for no checked bags.'],
                    ],
                    'required' => ['origin', 'destination', 'date'],
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

        if ($toolName === 'build_dynamic_bundles') {
            return [
                'flight' => is_array($result['flight'] ?? null) ? $result['flight'] : [],
                'passengers' => is_array($result['passengers'] ?? null) ? $result['passengers'] : [],
                'seat_passengers' => $result['seat_passengers'] ?? null,
                'recommended_picks' => collect(is_array($result['recommended_picks'] ?? null) ? $result['recommended_picks'] : [])
                    ->take(2)
                    ->values()
                    ->all(),
            ];
        }

        if ($toolName === 'search_flights') {
            return [
                'origin' => $result['origin'] ?? null,
                'destination' => $result['destination'] ?? null,
                'date' => $result['date'] ?? null,
                'trip_type' => $result['trip_type'] ?? null,
                'passengers' => is_array($result['passengers'] ?? null) ? $result['passengers'] : [],
                'seat_passengers' => $result['seat_passengers'] ?? null,
                'flight_count' => $result['flight_count'] ?? 0,
                'flights' => collect(is_array($result['flights'] ?? null) ? $result['flights'] : [])
                    ->take(2)
                    ->map(fn (array $flight): array => $this->publicFlight($flight))
                    ->values()
                    ->all(),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $flight
     * @return array<string, mixed>
     */
    private function publicFlight(array $flight): array
    {
        return [
            'flight_id' => $flight['id'] ?? $flight['flight_id'] ?? null,
            'flight_number' => $flight['flight_number'] ?? null,
            'plane_model' => $flight['plane_model'] ?? null,
            'date' => $flight['date'] ?? null,
            'hour' => $flight['hour'] ?? null,
            'arrival_hour' => $flight['arrival_hour'] ?? $flight['arrival_time'] ?? null,
            'duration_str' => $flight['duration_str'] ?? $flight['duration'] ?? null,
            'origin' => is_array($flight['origin'] ?? null) ? data_get($flight, 'origin.code') : ($flight['origin'] ?? null),
            'origin_city' => is_array($flight['origin'] ?? null) ? data_get($flight, 'origin.name') : ($flight['origin_city'] ?? null),
            'destination' => is_array($flight['destination'] ?? null) ? data_get($flight, 'destination.code') : ($flight['destination'] ?? null),
            'dest_city' => is_array($flight['destination'] ?? null) ? data_get($flight, 'destination.name') : ($flight['dest_city'] ?? null),
            'fare_type' => $flight['fare_type'] ?? null,
            'fares' => collect(is_array($flight['fares'] ?? null) ? $flight['fares'] : [])
                ->filter(fn (array $fare): bool => ($fare['available'] ?? false) === true)
                ->sortBy('base_price_usd')
                ->take(2)
                ->map(fn (array $fare): array => $this->publicFare($fare))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $fare
     * @return array<string, mixed>
     */
    private function publicFare(array $fare): array
    {
        return [
            'id' => $fare['id'] ?? null,
            'class' => $fare['class'] ?? null,
            'package_code' => $fare['package_code'] ?? null,
            'public' => $fare['public'] ?? null,
            'customized' => $fare['customized'] ?? false,
            'customized_service_codes' => $fare['customized_service_codes'] ?? [],
            'class_letters' => $fare['class_letters'] ?? null,
            'checked_baggage_kg' => $fare['checked_baggage_kg'] ?? null,
            'cabin_baggage_kg' => $fare['cabin_baggage_kg'] ?? null,
            'seat_selection_free' => $fare['seat_selection_free'] ?? null,
            'changeable' => $fare['changeable'] ?? null,
            'refundable' => $fare['refundable'] ?? null,
            'base_price_usd' => $fare['base_price_usd'] ?? null,
            'price_breakdown' => is_array($fare['price_breakdown'] ?? null)
                ? ['grand_total_usd' => $fare['price_breakdown']['grand_total_usd'] ?? null]
                : null,
            'services' => is_array($fare['services'] ?? null) ? $fare['services'] : [],
            'price_components' => is_array($fare['price_components'] ?? null) ? $fare['price_components'] : [],
        ];
    }
}
