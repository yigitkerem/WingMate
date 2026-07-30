<?php

namespace App\Chatbot;

use App\Support\ServiceValue;
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
        'SEAT_EXIT_ROW' => ['SEAT_EXIT_ROW', 'EXIT ROW', 'EMERGENCY EXIT', 'EXTRA LEGROOM'],
        'SEAT_STANDARD' => ['SEAT_STANDARD', 'STANDARD SEAT'],
        'SEAT_SELECTION' => ['SEAT_SELECTION', 'SEAT SELECTION', 'SEAT'],
        'CHANGE_ALLOWED' => ['CHANGE_ALLOWED', 'CHANGE RIGHT', 'CHANGE ALLOWED', 'CHANGE', 'CHANGES', 'CHANGEABLE'],
        'REFUNDABLE' => ['REFUNDABLE', 'REFUND RIGHT', 'REFUND', 'REFUNDS'],
        'LOUNGE' => ['LOUNGE', 'LOUNGE ACCESS'],
        'FAST_TRACK' => ['FAST_TRACK', 'FAST TRACK'],
        'PRIORITY_BOARDING' => ['PRIORITY_BOARDING', 'PRIORITY BOARDING', 'BOARDING'],
        'PRIORITY_CHECKIN' => ['PRIORITY_CHECKIN', 'PRIORITY CHECKIN', 'PRIORITY CHECK-IN', 'PRIORITY CHECK IN'],
        'WIFI_UNLIMITED' => ['WIFI_UNLIMITED', 'UNLIMITED WIFI', 'UNLIMITED WI-FI'],
        'WIFI_5GB' => ['WIFI_5GB', '5GB WIFI', '5 GB WIFI', 'WIFI 5GB', 'WI-FI 5GB'],
        'WIFI_1GB' => ['WIFI_1GB', '1GB WIFI', '1 GB WIFI', 'WIFI 1GB', 'WI-FI 1GB'],
        'WIFI' => ['WIFI', 'WI-FI', 'WI FI', 'INTERNET'],
        'MEAL' => ['MEAL', 'SPECIAL MEAL', 'FOOD'],
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
            $toolTrace = $this->withOfferHighlights(
                $toolTrace,
                $this->defaultOfferHighlights($result, $directBundleRequest),
            );

            $messages[] = ['role' => 'assistant', 'content' => $reply];
            $this->sessionStore->put($sessionId, $messages, $pendingPurchase);

            return [
                'reply' => $reply,
                'tool_trace' => $this->publicToolTrace($toolTrace),
            ];
        }

        $shoppingResponse = $this->localShoppingResponse($messages, $userText, $pendingPurchase, $language);

        if ($shoppingResponse !== null) {
            $messages[] = ['role' => 'assistant', 'content' => $shoppingResponse['reply']];
            $this->sessionStore->put($sessionId, $messages, $shoppingResponse['pending_purchase']);

            return [
                'reply' => $shoppingResponse['reply'],
                'tool_trace' => $shoppingResponse['tool_trace'],
            ];
        }

        $guidedChoiceReply = $this->guidedChoiceReply($userText, $language);

        if ($guidedChoiceReply !== null) {
            $messages[] = ['role' => 'assistant', 'content' => $guidedChoiceReply['reply']];
            $this->sessionStore->put($sessionId, $messages, $pendingPurchase);

            return [
                'reply' => $guidedChoiceReply['reply'],
                'tool_trace' => [[
                    'tool' => 'guided_choices',
                    'result' => [
                        'choices' => $guidedChoiceReply['choices'],
                    ],
                ]],
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
                $reply = (string) ($message['content'] ?? '');

                if ($this->hasKnowledgeTrace($toolTrace)) {
                    if ($this->isOfferNarration($reply)) {
                        $reply = $this->knowledgeReplyFromTrace($toolTrace, $language) ?? $reply;
                    }
                } elseif ($this->hasBundleTrace($toolTrace)) {
                    $presentation = $this->extractOfferPresentation($reply);
                    $reply = $presentation['reply'];

                    if (! $this->isCleanBundleReply($reply)) {
                        $reply = $this->bundleReplyFromTrace($toolTrace, $language) ?? $this->genericBundleReply($language);
                    }

                    $toolTrace = $this->withOfferHighlights($toolTrace, $presentation['offer_highlights']);
                }

                $messages[count($messages) - 1]['content'] = $reply;
                $this->sessionStore->put($sessionId, $messages, $pendingPurchase);

                return [
                    'reply' => $reply,
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
     * @return array{reply: string, choices: array<int, array{label: string, message: string}>}|null
     */
    private function guidedChoiceReply(string $text, string $language): ?array
    {
        if (! $this->shouldGuideBeforeRecommendation($text)) {
            return null;
        }

        $choices = $this->guidedChoices($text, $language);
        $reply = $language === 'tr'
            ? 'Öneri göstermeden önce birkaç şeyi netleştireyim. Bu yolculukta en önemli olan ne?'
            : 'Before I suggest flights, let me narrow this down with you. What matters most for this trip?';

        return [
            'reply' => $reply,
            'choices' => $choices,
        ];
    }

    private function shouldGuideBeforeRecommendation(string $text): bool
    {
        $normalized = Str::lower($text);

        if ($this->isKnowledgeQuestion($normalized) || str_contains($normalized, 'current search form:')) {
            return false;
        }

        return Str::contains($normalized, [
            'recommend',
            'suggest',
            'find me',
            'help me choose',
            'best flight',
            'cheapest',
            'comfortable',
            'comfort',
            'plan a trip',
            'want to fly',
            'need a flight',
            'flight to',
            'ticket to',
            'uçmak istiyorum',
            'uçuş öner',
            'bilet öner',
            'en ucuz',
            'konfor',
        ]);
    }

    private function isKnowledgeQuestion(string $normalizedText): bool
    {
        if (preg_match('/\b(what|which|how|when|where|why|can|do|does|are|is)\b.*\b(rights?|rules?|policy|policies|cancel(?:led|lation)?|refunds?|compensation|allowance)\b/', $normalizedText) === 1) {
            return true;
        }

        return Str::contains($normalizedText, [
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
            'hak',
            'kural',
            'iptal',
            'iade',
            'tazminat',
            'bagaj hakkı',
            'yolcu hakları',
        ]);
    }

    /**
     * @return array<int, array{label: string, message: string}>
     */
    private function guidedChoices(string $text, string $language): array
    {
        if ($language === 'tr') {
            return [
                ['label' => 'En düşük fiyat', 'message' => 'Bu yolculukta en düşük fiyat benim için en önemli kriter.'],
                ['label' => 'Bagaj ve koltuk', 'message' => 'Bagaj ve koltuk seçimi dahil bir seçenek istiyorum.'],
                ['label' => 'Esnek değişiklik', 'message' => 'Değişiklik ve iade esnekliği olan bir seçenek istiyorum.'],
            ];
        }

        return [
            ['label' => 'Lowest price', 'message' => 'Lowest price matters most for this trip.'],
            ['label' => 'Bags and seats', 'message' => 'I want bags and seat selection included.'],
            ['label' => 'Flexible changes', 'message' => 'I want change and refund flexibility.'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>|null  $pendingPurchase
     * @return array{reply: string, tool_trace: array<int, array<string, mixed>>, pending_purchase: array<string, mixed>|null}|null
     */
    private function localShoppingResponse(array $messages, string $userText, ?array $pendingPurchase, string $language): ?array
    {
        $conversationText = $this->conversationText($messages);
        $visibleUserText = $this->withoutSearchPrefill($userText);
        $normalizedUserText = Str::lower($visibleUserText);

        if ($this->isKnowledgeQuestion($normalizedUserText) || ! $this->shouldHandleShoppingLocally($conversationText, $visibleUserText)) {
            return null;
        }

        if ($this->shouldGuideBeforeRecommendation($visibleUserText)
            && ! $this->hasExplicitShoppingPriority($visibleUserText)
            && ! $this->hasDateCue($conversationText)
            && ! $this->hasGuidedChoiceContext($conversationText)) {
            return null;
        }

        $request = $this->shoppingRequestFromConversation($conversationText);

        $missing = collect(['origin', 'destination', 'date'])
            ->filter(fn (string $field): bool => ! isset($request[$field]) || $request[$field] === '')
            ->values()
            ->all();

        if ($missing !== []) {
            return [
                'reply' => $this->missingShoppingInfoReply($missing, $language),
                'tool_trace' => [],
                'pending_purchase' => $pendingPurchase,
            ];
        }

        if (($request['trip_type'] ?? 'one_way') === 'round_trip' && ! isset($request['return_date'])) {
            $request['return_date'] = Carbon::parse((string) $request['date'])->addDay()->toDateString();
        }

        $toolInput = [
            'origin' => (string) $request['origin'],
            'destination' => (string) $request['destination'],
            'date' => (string) $request['date'],
            'adults' => (int) ($request['adults'] ?? 1),
            'children' => (int) ($request['children'] ?? 0),
            'babies' => (int) ($request['babies'] ?? 0),
            'trip_type' => (string) ($request['trip_type'] ?? 'one_way'),
            'service_codes' => $request['service_codes'] ?? [],
            'excluded_service_codes' => $request['excluded_service_codes'] ?? [],
            'service_specs' => [],
        ];

        if (isset($request['return_date'])) {
            $toolInput['return_date'] = (string) $request['return_date'];
        }

        $toolResult = $this->toolbox->dispatch('build_dynamic_bundles', $toolInput, $pendingPurchase, false);
        $toolTrace = [[
            'tool' => 'build_dynamic_bundles',
            'input' => $toolInput,
            'result' => $toolResult['result'],
        ]];

        $toolTrace = $this->withOfferHighlights($toolTrace, $this->defaultOfferHighlights($toolResult['result'], $toolInput));

        return [
            'reply' => $this->bundleReply($toolResult['result'], $toolInput, $language),
            'tool_trace' => $this->publicToolTrace($toolTrace),
            'pending_purchase' => $toolResult['pending_purchase'],
        ];
    }

    private function conversationText(array $messages): string
    {
        return collect($messages)
            ->filter(fn (array $message): bool => ($message['role'] ?? null) === 'user')
            ->map(fn (array $message): string => (string) ($message['content'] ?? ''))
            ->implode("\n");
    }

    private function shouldHandleShoppingLocally(string $conversationText, string $userText): bool
    {
        $normalizedConversation = Str::lower($conversationText);
        $normalizedUserText = Str::lower($userText);

        if (str_contains($normalizedUserText, 'offer')
            && ! str_contains($normalizedUserText, 'build a custom bundle')
            && ! str_contains($normalizedConversation, 'current search form:')) {
            return false;
        }

        if (Str::contains($normalizedConversation, ['current search form:', 'lowest price matters', 'bags and seat selection', 'change and refund flexibility'])) {
            return true;
        }

        return Str::contains($normalizedConversation, [
            'cheapest',
            'lowest',
            'flight to',
            'ticket to',
            'fare to',
            'from ',
            ' to ',
            'roundtrip',
            'round trip',
            'one way',
            'one-way',
            'this weekend',
            'this weeekend',
            'today',
            'tomorrow',
        ]);
    }

    private function hasExplicitShoppingPriority(string $text): bool
    {
        return Str::contains(Str::lower($text), [
            'cheapest',
            'lowest',
            'lowest price',
            'bags and seats',
            'bag and seat',
            'flexible',
            'flexibility',
            'comfortable',
            'comfort',
            'en ucuz',
            'konfor',
        ]);
    }

    private function hasDateCue(string $text): bool
    {
        return preg_match('/\b(\d{4}-\d{2}-\d{2}|today|tomorrow|this\s+wee+kend)\b/i', $text) === 1;
    }

    private function hasGuidedChoiceContext(string $text): bool
    {
        return Str::contains(Str::lower($text), [
            'lowest price matters',
            'bags and seat selection',
            'change and refund flexibility',
            'en düşük fiyat',
            'bagaj ve koltuk',
            'esnek değişiklik',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shoppingRequestFromConversation(string $conversationText): array
    {
        $request = [
            'adults' => 1,
            'children' => 0,
            'babies' => 0,
            'trip_type' => Str::contains(Str::lower($conversationText), ['roundtrip', 'round trip', 'return trip', 'gidiş dönüş'])
                ? 'round_trip'
                : 'one_way',
            'service_codes' => $this->shoppingServiceCodes($conversationText),
            'excluded_service_codes' => [],
        ];

        $visibleConversationText = $this->withoutSearchPrefill($conversationText);

        $this->applyPrefillContext($request, $conversationText);
        $this->applyRouteContext($request, $visibleConversationText);
        $this->applyPassengerContext($request, $conversationText);
        $this->applyDateContext($request, $conversationText);

        return $request;
    }

    private function withoutSearchPrefill(string $conversationText): string
    {
        return preg_replace(
            '/\n*current search form:.*?(?:knowledge-base questions\.|$)/is',
            '',
            $conversationText,
        ) ?? $conversationText;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function applyPrefillContext(array &$request, string $conversationText): void
    {
        if (preg_match_all('/current search form:\s*from\s+([a-z]{3})\s+to\s+([a-z]{3})\s+on\s+(\d{4}-\d{2}-\d{2})(?:\.\s*return on\s+(\d{4}-\d{2}-\d{2}))?.*?passengers:\s*adults\s+(\d+),\s*children\s+(\d+),\s*babies\s+(\d+)/is', $conversationText, $matches, PREG_SET_ORDER) < 1) {
            return;
        }

        $match = array_pop($matches);
        $request['origin'] = Str::upper($match[1]);
        $request['destination'] = Str::upper($match[2]);
        $request['date'] = $match[3];
        $request['adults'] = (int) $match[5];
        $request['children'] = (int) $match[6];
        $request['babies'] = (int) $match[7];

        if (($match[4] ?? '') !== '') {
            $request['trip_type'] = 'round_trip';
            $request['return_date'] = $match[4];
        }
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function applyRouteContext(array &$request, string $conversationText): void
    {
        if (preg_match_all('/\bfrom\s+([a-zçğıöşü\s]{2,30}?)\s+to\s+([a-zçğıöşü\s]{2,30}?)(?:\s+(?:on|today|tomorrow|this|round|one|with|and|for|preferred)|[.?!,\n]|$)/iu', $conversationText, $matches, PREG_SET_ORDER) > 0) {
            $match = array_pop($matches);
            $request['origin'] = $this->airportCodeFromText($match[1], 'origin') ?? ($request['origin'] ?? null);
            $request['destination'] = $this->airportCodeFromText($match[2], 'destination') ?? ($request['destination'] ?? null);
        }

        if (preg_match_all('/\bto\s+([a-zçğıöşü\s]{2,30}?)(?:\s+(?:this|today|tomorrow|on|from|with|for|and)|[.?!,\n]|$)/iu', $conversationText, $matches, PREG_SET_ORDER) > 0) {
            $match = array_pop($matches);
            $request['destination'] = $this->airportCodeFromText($match[1], 'destination') ?? ($request['destination'] ?? null);
        }

        if (preg_match_all('/\bfrom\s+([a-zçğıöşü\s]{2,30}?)(?:\s+(?:this|today|tomorrow|on|to|with|for|and)|[.?!,\n]|$)/iu', $conversationText, $matches, PREG_SET_ORDER) > 0) {
            $match = array_pop($matches);
            $request['origin'] = $this->airportCodeFromText($match[1], 'origin') ?? ($request['origin'] ?? null);
        }

        if (! isset($request['origin']) && preg_match_all('/\b(IST|SAW|AMS|CDG|FRA|DXB|JFK|SIN)\b/i', $conversationText, $matches) > 0) {
            $request['origin'] = Str::upper((string) end($matches[1]));
        }
    }

    private function airportCodeFromText(string $value, string $slot): ?string
    {
        $normalized = Str::of($value)->lower()->squish()->toString();
        $codeCandidate = Str::upper($normalized);

        if (preg_match('/^[A-Z]{3}$/', $codeCandidate) === 1) {
            return $codeCandidate;
        }

        if (str_contains($normalized, 'sabiha') || $normalized === 'saw') {
            return 'SAW';
        }

        return match (true) {
            str_contains($normalized, 'istanbul') || $normalized === 'ist' => 'IST',
            str_contains($normalized, 'london') || $normalized === 'lhr' => 'LHR',
            str_contains($normalized, 'amsterdam') || $normalized === 'ams' => 'AMS',
            str_contains($normalized, 'paris') || $normalized === 'cdg' => 'CDG',
            str_contains($normalized, 'new york') || $normalized === 'jfk' => 'JFK',
            str_contains($normalized, 'singapore') || $normalized === 'sin' => 'SIN',
            default => $slot === 'origin' && str_contains($normalized, 'here') ? 'IST' : null,
        };
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function applyPassengerContext(array &$request, string $conversationText): void
    {
        if (preg_match_all('/\b(\d+)\s+adults?\b/i', $conversationText, $matches) > 0) {
            $request['adults'] = (int) end($matches[1]);
        }

        if (preg_match_all('/\badults?\s+(\d+)\b/i', $conversationText, $matches) > 0) {
            $request['adults'] = (int) end($matches[1]);
        }

        if (preg_match_all('/\b(\d+)\s+(?:children|child|kids?)\b/i', $conversationText, $matches) > 0) {
            $request['children'] = (int) end($matches[1]);
        }

        if (preg_match_all('/\b(?:children|child|kids?)\s+(\d+)\b/i', $conversationText, $matches) > 0) {
            $request['children'] = (int) end($matches[1]);
        }

        if (preg_match_all('/\b(\d+)\s+(?:babies|baby|infants?)\b/i', $conversationText, $matches) > 0) {
            $request['babies'] = (int) end($matches[1]);
        }

        if (preg_match_all('/\b(?:babies|baby|infants?)\s+(\d+)\b/i', $conversationText, $matches) > 0) {
            $request['babies'] = (int) end($matches[1]);
        }

        if (Str::contains(Str::lower($conversationText), ['1 adult only', 'one adult only', 'just me'])) {
            $request['adults'] = 1;
            $request['children'] = 0;
            $request['babies'] = 0;
        }
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function applyDateContext(array &$request, string $conversationText): void
    {
        $normalized = Str::lower($conversationText);

        if (preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/', $conversationText, $matches) > 0) {
            $request['date'] = end($matches[1]);
        }

        if (str_contains($normalized, 'today')) {
            $request['date'] = Carbon::today()->toDateString();
        } elseif (str_contains($normalized, 'tomorrow')) {
            $request['date'] = Carbon::tomorrow()->toDateString();
        } elseif (preg_match('/\bthis\s+wee+kend\b/', $normalized) === 1) {
            $weekendStart = Carbon::today()->next(Carbon::SATURDAY);
            $request['date'] = $weekendStart->toDateString();

            if (($request['trip_type'] ?? 'one_way') === 'round_trip') {
                $request['return_date'] = $weekendStart->copy()->addDay()->toDateString();
            }
        }

        if (preg_match_all('/\breturn(?:ing)?\s+(?:on\s+)?(\d{4}-\d{2}-\d{2})\b/i', $conversationText, $matches) > 0) {
            $request['trip_type'] = 'round_trip';
            $request['return_date'] = end($matches[1]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function shoppingServiceCodes(string $conversationText): array
    {
        $normalized = Str::lower($conversationText);

        if (Str::contains($normalized, ['bag and seat', 'bags and seat', 'bagaj ve koltuk'])) {
            return ['CHECKED_BAG', 'SEAT_SELECTION'];
        }

        if (Str::contains($normalized, ['change and refund flexibility', 'flexible changes', 'flexibility', 'esnek değişiklik'])) {
            return ['CHANGE_ALLOWED', 'REFUNDABLE'];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function missingShoppingInfoReply(array $missing, string $language): string
    {
        if ($language === 'tr') {
            return match ($missing[0] ?? '') {
                'origin' => 'Nereden uçmak istiyorsun? Aksi belirtilmezse 1 yetişkin olarak devam edeceğim.',
                'destination' => 'Nereye uçmak istiyorsun? Aksi belirtilmezse 1 yetişkin olarak devam edeceğim.',
                'date' => 'Ne zaman uçmak istiyorsun? Bugün, yarın veya bu hafta sonu diyebilirsin; 1 yetişkin varsayacağım.',
                default => 'Rota ve tarihi netleştirir misin? Aksi belirtilmezse 1 yetişkin olarak devam edeceğim.',
            };
        }

        return match ($missing[0] ?? '') {
            'origin' => 'Where are you flying from? I will use 1 adult unless you tell me otherwise.',
            'destination' => 'Where are you flying to? I will use 1 adult unless you tell me otherwise.',
            'date' => 'When do you want to fly? You can say today, tomorrow, or this weekend; I will use 1 adult unless you tell me otherwise.',
            default => 'Please share the route and date. I will use 1 adult unless you tell me otherwise.',
        };
    }

    /**
     * @return array{origin: string, destination: string, date: string, trip_type?: string, return_date?: string, service_codes: array<int, string>, excluded_service_codes: array<int, string>, service_specs: array<int, array<string, mixed>>}|null
     */
    private function parseBundleRequest(string $text): ?array
    {
        $requestText = $this->withoutSearchPrefill($text);

        if (! str_contains(Str::lower($requestText), 'bundle')) {
            return null;
        }

        if (preg_match('/\bfrom\s+([a-z]{3})\s+to\s+([a-z]{3})\s+on\s+(\d{4}-\d{2}-\d{2})\b/i', $requestText, $matches) !== 1) {
            return null;
        }

        $excludedServiceCodes = $this->parseExcludedServiceCodes($requestText);
        $serviceSpecs = $this->parseServiceSpecs($requestText, $excludedServiceCodes);
        $serviceSpecCodes = collect($serviceSpecs)
            ->pluck('service_code')
            ->filter(fn (mixed $serviceCode): bool => is_string($serviceCode))
            ->all();

        $request = [
            'origin' => Str::upper($matches[1]),
            'destination' => Str::upper($matches[2]),
            'date' => $matches[3],
            'service_codes' => array_values(array_diff($this->parsePreferredServiceCodes($requestText), $excludedServiceCodes, $serviceSpecCodes)),
            'excluded_service_codes' => $excludedServiceCodes,
            'service_specs' => $serviceSpecs,
        ];

        if (preg_match('/\breturn(?:ing)?\s+(?:on\s+)?(\d{4}-\d{2}-\d{2})\b/i', $requestText, $returnMatches) === 1
            || preg_match('/\bround\s*trip\b.*?\b(\d{4}-\d{2}-\d{2})\b/i', $requestText, $returnMatches) === 1) {
            $request['trip_type'] = 'round_trip';
            $request['return_date'] = $returnMatches[1];
        }

        $this->applyPassengerContext($request, $requestText);

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
     * @param  array<int, string>  $excludedServiceCodes
     * @return array<int, array{service_code: string, quantity?: int, value: mixed}>
     */
    private function parseServiceSpecs(string $text, array $excludedServiceCodes): array
    {
        $specs = [];

        if (! in_array('CHECKED_BAG', $excludedServiceCodes, true)
            && (preg_match('/\bchecked\s+(?:bag|baggage).*?\b(\d{1,2})\s*kg\b/i', $text, $matches) === 1
                || preg_match('/\b(\d{1,2})\s*kg\s+checked\s+(?:bag|baggage)\b/i', $text, $matches) === 1)) {
            $specs[] = ['service_code' => 'CHECKED_BAG', 'value' => ['amount' => (int) $matches[1]]];
        }

        if (! in_array('WIFI', $excludedServiceCodes, true)) {
            $wifiSpec = $this->wifiServiceSpec($text);

            if ($wifiSpec !== null) {
                $specs[] = $wifiSpec;
            }
        }

        $changeSpec = $this->flexibilityServiceSpec($text, 'CHANGE_ALLOWED', ['change', 'changes', 'changeable'], 55);

        if ($changeSpec !== null && ! in_array('CHANGE_ALLOWED', $excludedServiceCodes, true)) {
            $specs[] = $changeSpec;
        }

        $refundSpec = $this->flexibilityServiceSpec($text, 'REFUNDABLE', ['refund', 'refunds', 'refundable'], 45);

        if ($refundSpec !== null && ! in_array('REFUNDABLE', $excludedServiceCodes, true)) {
            $specs[] = $refundSpec;
        }

        return collect($specs)
            ->unique(fn (array $spec): string => $spec['service_code'])
            ->values()
            ->all();
    }

    /**
     * @return array{service_code: string, value: mixed}|null
     */
    private function wifiServiceSpec(string $text): ?array
    {
        $normalized = Str::lower($text);

        if (preg_match('/\b(wi-?fi|internet)\b/i', $text) !== 1) {
            return null;
        }

        if (str_contains($normalized, 'unlimited')) {
            return ['service_code' => 'WIFI_UNLIMITED', 'value' => true];
        }

        if (preg_match('/\b5\s*gb\b/i', $text) === 1) {
            return ['service_code' => 'WIFI_5GB', 'value' => ['amount' => 5120, 'data_mb' => 5120]];
        }

        if (preg_match('/\b1\s*gb\b/i', $text) === 1) {
            return ['service_code' => 'WIFI_1GB', 'value' => ['amount' => 1024, 'data_mb' => 1024]];
        }

        return ['service_code' => 'WIFI', 'value' => ['amount' => 250, 'data_mb' => 250]];
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array{service_code: string, value: array{amount: int, allowed: bool, window_hours: int, fee_type: string, fee_amount: float}}|null
     */
    private function flexibilityServiceSpec(string $text, string $serviceCode, array $keywords, int $defaultPaidFee): ?array
    {
        $normalized = Str::lower($text);

        if (! collect($keywords)->contains(fn (string $keyword): bool => str_contains($normalized, $keyword))) {
            return null;
        }

        $negativePattern = '/\b(no|without|exclude|excluding|not)\s+('.implode('|', array_map(fn (string $keyword): string => preg_quote($keyword, '/'), $keywords)).')\b/i';

        if (preg_match($negativePattern, $text) === 1) {
            return null;
        }

        $windowHours = $this->parseWindowHours($text) ?? 24;
        [$feeType, $feeAmount] = $this->parseFee($text, $keywords, $defaultPaidFee);

        return [
            'service_code' => $serviceCode,
            'value' => [
                'amount' => $windowHours,
                'allowed' => true,
                'window_hours' => $windowHours,
                'fee_type' => $feeType,
                'fee_amount' => $feeAmount,
            ],
        ];
    }

    private function parseWindowHours(string $text): ?int
    {
        if (preg_match('/\b(\d{1,3})\s*(?:h|hr|hrs|hour|hours)\b/i', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/\b(\d{1,2})\s*(?:day|days)\b/i', $text, $matches) === 1) {
            return (int) $matches[1] * 24;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array{0: string, 1: float}
     */
    private function parseFee(string $text, array $keywords, int $defaultPaidFee): array
    {
        $normalized = Str::lower($text);

        if (collect($keywords)->contains(fn (string $keyword): bool => str_contains($normalized, "free {$keyword}") || str_contains($normalized, "{$keyword} free"))) {
            return ['fixed', 0];
        }

        if (preg_match('/\b(\d{1,2}(?:\.\d+)?)\s*%\s*(?:fee|penalty)?\b/i', $text, $matches) === 1) {
            return ['percent', (float) $matches[1]];
        }

        if (preg_match('/(?:\$\s*(\d{1,4})|\b(\d{1,4})\s*(?:usd|dollars?)\b)/i', $text, $matches) === 1) {
            return ['fixed', (float) (($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? 0))];
        }

        return collect($keywords)->contains(fn (string $keyword): bool => str_contains($normalized, "paid {$keyword}") || str_contains($normalized, "{$keyword} fee"))
            ? ['fixed', $defaultPaidFee]
            : ['fixed', 0];
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

        $picks = collect(is_array($result['recommended_picks'] ?? null) ? $result['recommended_picks'] : []);
        $pick = $picks->first();

        if (! is_array($pick)) {
            return $language === 'tr'
                ? 'Bu tarih ve rota için uygun paket bulamadım. Başka bir tarih deneyebiliriz.'
                : 'I could not find a suitable package for that route and date. Try another date.';
        }

        return $language === 'tr'
            ? $this->turkishBundleReply($request, $pick)
            : $this->englishBundleReply($request, $pick, $result);
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolTrace
     */
    private function hasBundleTrace(array $toolTrace): bool
    {
        return collect($toolTrace)
            ->contains(fn (array $trace): bool => ($trace['tool'] ?? null) === 'build_dynamic_bundles');
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolTrace
     */
    private function hasKnowledgeTrace(array $toolTrace): bool
    {
        return collect($toolTrace)
            ->contains(fn (array $trace): bool => ($trace['tool'] ?? null) === 'search_knowledge_base');
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolTrace
     */
    private function bundleReplyFromTrace(array $toolTrace, string $language): ?string
    {
        $bundleTrace = collect($toolTrace)
            ->reverse()
            ->first(fn (array $trace): bool => ($trace['tool'] ?? null) === 'build_dynamic_bundles');

        if (! is_array($bundleTrace)) {
            return null;
        }

        $result = is_array($bundleTrace['result'] ?? null) ? $bundleTrace['result'] : [];
        $input = is_array($bundleTrace['input'] ?? null) ? $bundleTrace['input'] : [];

        return $this->bundleReply($result, $input, $language);
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function englishBundleReply(array $request, array $pick, array $result): string
    {
        $requestedServiceCodes = $this->requestedServiceCodes($request);
        $included = $this->serviceLabelsForPick($pick, $requestedServiceCodes, true);
        $missing = $this->serviceLabelsForPick($pick, $requestedServiceCodes, false);
        $exclusions = $this->excludedServiceLabels($request);
        $route = $this->routeLabelFromResult($result);

        if ($included !== [] && $missing !== []) {
            return "I found an offer with {$this->joinLabels($included)}, but {$this->joinLabels($missing)} ".(count($missing) === 1 ? 'is' : 'are').' not included on this fare.';
        }

        if ($included !== []) {
            return "I found an offer that includes {$this->joinLabels($included)}, here's the offer.";
        }

        if ($missing !== []) {
            return "I found the lowest available offer{$route}, but {$this->joinLabels($missing)} ".(count($missing) === 1 ? 'is' : 'are').' not included on this fare.';
        }

        if ($exclusions !== []) {
            return "Of course, I can build this without {$this->joinLabels($exclusions)}, here's the offer.";
        }

        return "Here's my offer for you.";
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function turkishBundleReply(array $request, array $pick): string
    {
        $requestedServiceCodes = $this->requestedServiceCodes($request);
        $included = $this->serviceLabelsForPick($pick, $requestedServiceCodes, true);
        $missing = $this->serviceLabelsForPick($pick, $requestedServiceCodes, false);
        $exclusions = $this->excludedServiceLabels($request);

        if ($included !== [] && $missing !== []) {
            return "{$this->joinLabels($included)} içeren bir teklif buldum, fakat bu ücrette {$this->joinLabels($missing)} dahil değil.";
        }

        if ($included !== []) {
            return "{$this->joinLabels($included)} içeren bir teklif buldum; teklifin burada.";
        }

        if ($missing !== []) {
            return "En düşük uygun teklifi buldum, fakat bu ücrette {$this->joinLabels($missing)} dahil değil.";
        }

        if ($exclusions !== []) {
            return "{$this->joinLabels($exclusions)} olmadan hazırlayabilirim; teklifin burada.";
        }

        return 'Teklifini hazırladım.';
    }

    private function genericBundleReply(string $language): string
    {
        return $language === 'tr' ? 'Teklifini hazırladım.' : "Here's my offer for you.";
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<int, string>
     */
    private function requestedServiceLabels(array $request): array
    {
        return collect($this->requestedServiceCodes($request))
            ->map(fn (string $serviceCode): string => $this->serviceLabel($serviceCode))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<int, string>
     */
    private function requestedServiceCodes(array $request): array
    {
        $serviceCodes = collect(is_array($request['service_codes'] ?? null) ? $request['service_codes'] : []);
        $serviceSpecs = collect(is_array($request['service_specs'] ?? null) ? $request['service_specs'] : [])
            ->pluck('service_code');

        return $serviceCodes
            ->merge($serviceSpecs)
            ->filter(fn (mixed $serviceCode): bool => is_string($serviceCode))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $pick
     * @param  array<int, string>  $serviceCodes
     * @return array<int, string>
     */
    private function serviceLabelsForPick(array $pick, array $serviceCodes, bool $included): array
    {
        return collect($serviceCodes)
            ->filter(fn (string $serviceCode): bool => $this->pickIncludesServiceCode($pick, $serviceCode) === $included)
            ->map(fn (string $serviceCode): string => $this->serviceLabel($serviceCode))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $pick
     */
    private function pickIncludesServiceCode(array $pick, string $serviceCode): bool
    {
        return match ($serviceCode) {
            'CHECKED_BAG' => (int) ($pick['checked_baggage_kg'] ?? 0) > 0,
            'CABIN_BAG' => (int) ($pick['cabin_baggage_kg'] ?? 0) > 0,
            'SEAT_SELECTION' => (bool) ($pick['seat_selection_free'] ?? false)
                || $this->pickServiceIsEnabled($pick, 'SEAT_STANDARD')
                || $this->pickServiceIsEnabled($pick, 'SEAT_EXIT_ROW'),
            'SEAT_STANDARD', 'SEAT_EXIT_ROW' => $this->pickServiceIsEnabled($pick, $serviceCode),
            'CHANGE_ALLOWED' => ($pick['latest_change_hours'] ?? null) !== null,
            'REFUNDABLE' => ($pick['latest_refund_hours'] ?? null) !== null,
            'WIFI' => $this->pickServiceIsEnabled($pick, 'WIFI')
                || $this->pickServiceIsEnabled($pick, 'WIFI_1GB')
                || $this->pickServiceIsEnabled($pick, 'WIFI_5GB')
                || $this->pickServiceIsEnabled($pick, 'WIFI_UNLIMITED'),
            default => $this->pickServiceIsEnabled($pick, $serviceCode),
        };
    }

    /**
     * @param  array<string, mixed>  $pick
     */
    private function pickServiceIsEnabled(array $pick, string $serviceCode): bool
    {
        $service = collect(is_array($pick['services'] ?? null) ? $pick['services'] : [])
            ->first(fn (mixed $service): bool => is_array($service) && ($service['code'] ?? null) === $serviceCode);

        return is_array($service) && ServiceValue::isEnabled($service['value'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function routeLabelFromResult(array $result): string
    {
        $flight = is_array($result['flight'] ?? null) ? $result['flight'] : [];
        $origin = (string) ($flight['origin'] ?? '');
        $destination = (string) ($flight['destination'] ?? '');

        return $origin !== '' && $destination !== '' ? " for {$origin}-{$destination}" : '';
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<int, string>
     */
    private function excludedServiceLabels(array $request): array
    {
        return collect(is_array($request['excluded_service_codes'] ?? null) ? $request['excluded_service_codes'] : [])
            ->filter(fn (mixed $serviceCode): bool => is_string($serviceCode))
            ->map(fn (string $serviceCode): string => $this->serviceLabel($serviceCode))
            ->unique()
            ->values()
            ->all();
    }

    private function serviceLabel(string $serviceCode): string
    {
        return match ($serviceCode) {
            'CHECKED_BAG' => 'checked bags',
            'CABIN_BAG' => 'cabin bags',
            'SEAT_SELECTION' => 'seat selection',
            'SEAT_STANDARD' => 'a standard seat',
            'SEAT_EXIT_ROW' => 'an exit row seat',
            'CHANGE_ALLOWED' => 'change flexibility',
            'REFUNDABLE' => 'refund flexibility',
            'LOUNGE' => 'lounge access',
            'FAST_TRACK' => 'fast track',
            'PRIORITY_BOARDING' => 'priority boarding',
            'PRIORITY_CHECKIN' => 'priority check-in',
            'WIFI', 'WIFI_1GB', 'WIFI_5GB', 'WIFI_UNLIMITED' => 'Wi-Fi',
            'MEAL' => 'a special meal',
            default => Str::of($serviceCode)->replace('_', ' ')->lower()->toString(),
        };
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function joinLabels(array $labels): string
    {
        if (count($labels) <= 1) {
            return $labels[0] ?? '';
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }

    /**
     * @return array{reply: string, offer_highlights: array<int, array{index: int, label: string}>}
     */
    private function extractOfferPresentation(string $reply): array
    {
        $offerHighlights = [];
        $cleanReply = preg_replace_callback('/\[\[offer_highlights:(.*?)\]\]/is', function (array $matches) use (&$offerHighlights): string {
            $offerHighlights = $this->parseOfferHighlights((string) $matches[1]);

            return '';
        }, $reply) ?? $reply;

        return [
            'reply' => trim((string) preg_replace("/\n{3,}/", "\n\n", $cleanReply)),
            'offer_highlights' => $offerHighlights,
        ];
    }

    /**
     * @return array<int, array{index: int, label: string}>
     */
    private function parseOfferHighlights(string $value): array
    {
        return collect(explode(';', $value))
            ->map(function (string $entry): ?array {
                if (preg_match('/^\s*(\d+)\s*=\s*(.+?)\s*$/', $entry, $matches) !== 1) {
                    return null;
                }

                $label = Str::of($matches[2])
                    ->replaceMatches('/[\[\]\r\n]+/', ' ')
                    ->squish()
                    ->limit(28, '')
                    ->toString();

                if ($label === '') {
                    return null;
                }

                return [
                    'index' => (int) $matches[1],
                    'label' => $label,
                ];
            })
            ->filter()
            ->take(2)
            ->values()
            ->all();
    }

    private function isCleanBundleReply(string $reply): bool
    {
        if (trim($reply) === '' || Str::length($reply) > 240) {
            return false;
        }

        if ($this->isOfferNarration($reply)) {
            return false;
        }

        return substr_count($reply, "\n") <= 2;
    }

    private function isOfferNarration(string $reply): bool
    {
        $normalized = Str::lower($reply);

        if (Str::contains($normalized, ['best custom bundle', 'backup pick', 'en uygun özel paket'])) {
            return true;
        }

        if (preg_match('/\([A-Z]{3}\s*-\s*[A-Z]{3},\s*\d{4}-\d{2}-\d{2}/', $reply) === 1) {
            return true;
        }

        return preg_match('/\$\d|\b\d+\s*kg\s+(?:cabin|checked)\s+bag/i', $reply) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolTrace
     */
    private function knowledgeReplyFromTrace(array $toolTrace, string $language): ?string
    {
        $knowledgeTrace = collect($toolTrace)
            ->reverse()
            ->first(fn (array $trace): bool => ($trace['tool'] ?? null) === 'search_knowledge_base');

        if (! is_array($knowledgeTrace)) {
            return null;
        }

        $result = is_array($knowledgeTrace['result'] ?? null) ? $knowledgeTrace['result'] : [];
        $hit = collect(is_array($result['hits'] ?? null) ? $result['hits'] : [])->first();

        if (! is_array($hit)) {
            return $language === 'tr'
                ? 'Resmi kaynaklarda bu konuda net bir bilgi bulamadım.'
                : 'I could not find a clear answer in the official sources.';
        }

        $content = Str::of((string) ($hit['content'] ?? ''))
            ->squish()
            ->limit(360)
            ->toString();
        $title = (string) ($hit['title'] ?? 'Knowledge base');
        $page = $hit['page'] ?? null;
        $source = $page ? "{$title}, p.{$page}" : $title;

        return $language === 'tr'
            ? "{$content} (Kaynak: {$source})"
            : "{$content} (Source: {$source})";
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $request
     * @return array<int, array{index: int, label: string}>
     */
    private function defaultOfferHighlights(array $result, array $request): array
    {
        $picks = collect(is_array($result['recommended_picks'] ?? null) ? $result['recommended_picks'] : []);

        if ($picks->isEmpty()) {
            return [];
        }

        $pick = $picks->first();

        if (! is_array($pick)) {
            return [];
        }

        $requestedServiceCodes = $this->requestedServiceCodes($request);
        $included = $this->serviceLabelsForPick($pick, $requestedServiceCodes, true);

        if ($included !== []) {
            return [['index' => 0, 'label' => Str::headline($included[0])]];
        }

        $label = $this->bestActualHighlight($pick);

        return [['index' => 0, 'label' => Str::headline($label)]];
    }

    /**
     * @param  array<string, mixed>  $pick
     */
    private function bestActualHighlight(array $pick): string
    {
        if (($pick['customized'] ?? false) === true) {
            return 'custom offer';
        }

        if ((int) ($pick['checked_baggage_kg'] ?? 0) > 0) {
            return ((int) $pick['checked_baggage_kg']).' kg checked bag';
        }

        if (($pick['latest_refund_hours'] ?? null) !== null) {
            return 'refundable';
        }

        if (($pick['latest_change_hours'] ?? null) !== null) {
            return 'changeable';
        }

        if ((int) ($pick['cabin_baggage_kg'] ?? 0) > 0) {
            return ((int) $pick['cabin_baggage_kg']).' kg cabin bag';
        }

        return 'lowest fare';
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolTrace
     * @param  array<int, array{index: int, label: string}>  $offerHighlights
     * @return array<int, array<string, mixed>>
     */
    private function withOfferHighlights(array $toolTrace, array $offerHighlights): array
    {
        if ($offerHighlights === []) {
            return $toolTrace;
        }

        $lastBundleIndex = collect($toolTrace)
            ->keys()
            ->reverse()
            ->first(fn (int $index): bool => ($toolTrace[$index]['tool'] ?? null) === 'build_dynamic_bundles');

        if (! is_int($lastBundleIndex) || ! is_array($toolTrace[$lastBundleIndex]['result']['recommended_picks'] ?? null)) {
            return $toolTrace;
        }

        foreach ($offerHighlights as $highlight) {
            $index = $highlight['index'];

            if (! is_array($toolTrace[$lastBundleIndex]['result']['recommended_picks'][$index] ?? null)) {
                continue;
            }

            $existing = is_array($toolTrace[$lastBundleIndex]['result']['recommended_picks'][$index]['highlight_pills'] ?? null)
                ? $toolTrace[$lastBundleIndex]['result']['recommended_picks'][$index]['highlight_pills']
                : [];

            $toolTrace[$lastBundleIndex]['result']['recommended_picks'][$index]['highlight_pills'] = collect($existing)
                ->push($highlight['label'])
                ->unique()
                ->values()
                ->all();
        }

        return $toolTrace;
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
- Choose whether one or two offer cards should be highlighted. Do not mention unhighlighted backup picks in text.
- For recommendation tool results, write one short dynamic sentence, e.g. "Of course, I can add Wi-Fi, here's the offer." Avoid route, date, price, package names, feature lists, and backup-pick text because the cards show that.
- To highlight cards, append a hidden marker on its own line: [[offer_highlights:0=Best Wi-Fi fit;1=Lower price]]. Use zero-based card indexes, choose at most two highlights, and keep labels under 28 characters. The UI hides this marker.

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
                        'service_specs' => [
                            'type' => 'array',
                            'description' => 'Exact custom services to add or override. Use REFUNDABLE or CHANGE_ALLOWED with value.allowed, value.window_hours, value.fee_type fixed/percent, and value.fee_amount. Use WIFI, WIFI_1GB, WIFI_5GB, or WIFI_UNLIMITED for Wi-Fi tiers.',
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
        if ($this->hasKnowledgeTrace($toolTrace)) {
            $toolTrace = collect($toolTrace)
                ->filter(fn (array $trace): bool => ($trace['tool'] ?? null) === 'search_knowledge_base')
                ->values()
                ->all();
        }

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
            'change_fee_usd' => $fare['change_fee_usd'] ?? null,
            'change_fee_percent' => $fare['change_fee_percent'] ?? null,
            'refund_fee_usd' => $fare['refund_fee_usd'] ?? null,
            'refund_fee_percent' => $fare['refund_fee_percent'] ?? null,
            'latest_change_hours' => $fare['latest_change_hours'] ?? null,
            'latest_refund_hours' => $fare['latest_refund_hours'] ?? null,
            'change_rule' => $fare['change_rule'] ?? null,
            'refund_rule' => $fare['refund_rule'] ?? null,
            'highlight_pills' => is_array($fare['highlight_pills'] ?? null) ? $fare['highlight_pills'] : [],
            'base_price_usd' => $fare['base_price_usd'] ?? null,
            'price_breakdown' => is_array($fare['price_breakdown'] ?? null)
                ? ['grand_total_usd' => $fare['price_breakdown']['grand_total_usd'] ?? null]
                : null,
            'services' => is_array($fare['services'] ?? null) ? $fare['services'] : [],
            'price_components' => is_array($fare['price_components'] ?? null) ? $fare['price_components'] : [],
        ];
    }
}
