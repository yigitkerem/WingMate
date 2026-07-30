<?php

use App\Chatbot\AzureChatClient;
use App\Chatbot\ChatbotAgent;
use App\Chatbot\ChatbotSessionStore;
use App\Chatbot\ChatbotToolbox;
use App\Jobs\ProcessChatMessage;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\AirlineDemoSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeAzureChatClient;

test('chat endpoint queues a chatbot message with consent and context', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/message', [
        'session_id' => 'session-1',
        'message_id' => 'assistant-1',
        'message' => 'Which airports can I search?',
        'source' => 'ours',
        'language' => 'en',
        'consent' => true,
        'context' => [
            'page' => 'flight-search',
            'form' => ['origin' => 'IST', 'destination' => 'LHR', 'date' => '2026-08-01', 'adults' => 1],
        ],
    ])
        ->assertAccepted()
        ->assertJson(['status' => 'queued', 'message_id' => 'assistant-1']);

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => $job->sessionId === 'session-1'
            && $job->language === 'en'
            && $job->userId === $user->id
            && $job->consent === true
            && ($job->context['page'] ?? null) === 'flight-search'
            && ($job->context['form']['destination'] ?? null) === 'LHR',
    );
});

test('chat endpoint accepts a hidden trigger without a message', function () {
    Queue::fake();

    $this->postJson('/api/message', [
        'session_id' => 'trigger-session',
        'trigger' => 'custom_bundle',
        'consent' => true,
        'context' => ['page' => 'flight-results', 'trigger_context' => ['flight_number' => 'TK1980']],
    ])->assertAccepted();

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => $job->trigger === 'custom_bundle'
            && $job->userText === ''
            && ($job->context['trigger_context']['flight_number'] ?? null) === 'TK1980',
    );
});

test('chat message status returns the queued reply when it is ready', function () {
    $this->getJson('/api/message/session-1/assistant-1')->assertAccepted();

    app(ChatbotSessionStore::class)->putMessageResult(
        sessionId: 'session-1',
        messageId: 'assistant-1',
        reply: 'Here is the fare answer.',
        toolTrace: [['tool' => 'ask_follow_up']],
    );

    $this->getJson('/api/message/session-1/assistant-1')
        ->assertOk()
        ->assertJson(['status' => 'ready', 'reply' => 'Here is the fare answer.', 'failed' => false]);
});

test('agent advertises the LLM-first tool schema to the model', function () {
    config()->set('services.azure_openai.base_url', 'https://azure.test/openai/v1');
    config()->set('services.azure_openai.api_key', 'test-key');
    config()->set('services.azure_openai.model', 'test-model');

    Http::fake([
        'azure.test/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Sure.']]],
        ]),
    ]);

    app(ChatbotAgent::class)->send('schema-session-'.Str::uuid(), 'Which airports can I search?', 'ours', 'en');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"name":"present_offers"')
        && str_contains($request->body(), '"name":"airports_for_city"')
        && str_contains($request->body(), '"name":"list_airports"')
        && str_contains($request->body(), '"name":"ask_follow_up"')
        && str_contains($request->body(), '"offer_id"')
        && ! str_contains($request->body(), 'availability_id'));
});

test('agent returns a graceful message when the model is unavailable', function () {
    config()->set('services.azure_openai.base_url', null);
    config()->set('services.azure_openai.api_key', null);

    $response = app(ChatbotAgent::class)->send('offline-session-'.Str::uuid(), 'Hello', 'ours', 'en');

    expect($response['reply'])->toContain('could not reach')
        ->and($response['tool_trace'])->toBe([]);
});

test('agent only shares personal passenger data when consent is granted', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $withConsent = new FakeAzureChatClient;
    $this->app->instance(AzureChatClient::class, $withConsent);
    $withConsent->pushMessage('Hello!');
    app(ChatbotAgent::class)->send('consent-yes', 'hi', 'ours', 'en', $user->id, true, null, ['page' => 'welcome']);

    expect($withConsent->sentText())->toContain('Ada Lovelace');

    $withoutConsent = new FakeAzureChatClient;
    $this->app->instance(AzureChatClient::class, $withoutConsent);
    $withoutConsent->pushMessage('Hello!');
    app(ChatbotAgent::class)->send('consent-no', 'hi', 'ours', 'en', $user->id, false, null, ['page' => 'welcome']);

    expect($withoutConsent->sentText())->not->toContain('Ada Lovelace');
});

test('present_offers persists the personalized memo into the offer context and returns a card', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');

    try {
        $this->seed(AirlineDemoSeeder::class);

        $build = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
            'origin' => 'IST',
            'destination' => 'LHR',
            'date' => '2026-07-29',
            'adults' => 1,
        ], null, false, null);

        $offerIds = $build['result']['recommended_picks'][0]['offer_ids'];

        $fake = new FakeAzureChatClient;
        $this->app->instance(AzureChatClient::class, $fake);
        $fake->pushToolCall('present_offers', [
            'intro' => 'Here is a great pick',
            'offers' => [[
                'offer_ids' => $offerIds,
                'memo' => 'Perfect for your quick London hop.',
            ]],
        ]);
        $fake->pushMessage('Here is a lovely way to fly.');

        $response = app(ChatbotAgent::class)->send('present-session', 'show me an offer', 'ours', 'en', null, true, null, ['page' => 'flight-search']);
    } finally {
        Carbon::setTestNow();
    }

    expect($response['reply'])->toBe('Here is a lovely way to fly.')
        ->and($response['tool_trace'])->toHaveCount(1)
        ->and($response['tool_trace'][0]['tool'])->toBe('present_offers')
        ->and($response['tool_trace'][0]['result']['offers'][0]['memo'])->toBe('Perfect for your quick London hop.')
        ->and($response['tool_trace'][0]['result']['offers'][0]['offer_ids'])->toBe($offerIds)
        ->and($response['tool_trace'][0]['result']['offers'][0])->toHaveKey('cabin')
        ->and($response['tool_trace'][0]['result']['offers'][0]['title'])->not->toBe('');

    $offer = Offer::query()->find($offerIds[0]);

    expect($offer->context['wingo']['memo'])->toBe('Perfect for your quick London hop.')
        ->and($offer->context['wingo']['consent'])->toBeTrue()
        ->and($offer->context['wingo']['page'])->toBe('flight-search');
});

test('agent grounds the model with the exact airport list from the database', function () {
    $this->seed(AirlineDemoSeeder::class);

    $fake = new FakeAzureChatClient;
    $this->app->instance(AzureChatClient::class, $fake);
    $fake->pushMessage('Where would you like to fly?');

    app(ChatbotAgent::class)->send('airport-ground-session', 'hi', 'ours', 'en', null, true);

    $systemPrompt = $fake->sentText();

    expect($systemPrompt)->toContain('AIRPORTS IN THE SYSTEM')
        ->and($systemPrompt)->toContain('Paris')
        ->and($systemPrompt)->toContain('(CDG)')
        ->and($systemPrompt)->toContain('(IST)')
        ->and($systemPrompt)->not->toContain('(ORY)');
});

test('present_offers card exposes a per-leg total for price breakdowns', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');

    try {
        $this->seed(AirlineDemoSeeder::class);

        $build = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
            'origin' => 'IST',
            'destination' => 'LHR',
            'date' => '2026-07-29',
            'adults' => 2,
        ], null, false, null);

        $offerIds = $build['result']['recommended_picks'][0]['offer_ids'];

        $fake = new FakeAzureChatClient;
        $this->app->instance(AzureChatClient::class, $fake);
        $fake->pushToolCall('present_offers', [
            'offers' => [['offer_ids' => $offerIds, 'memo' => 'Room for two.']],
        ]);
        $fake->pushMessage('Here you go.');

        $response = app(ChatbotAgent::class)->send('leg-total-session', 'show me an offer', 'ours', 'en', null, true);
    } finally {
        Carbon::setTestNow();
    }

    $segment = $response['tool_trace'][0]['result']['offers'][0]['segments'][0];

    expect($segment)->toHaveKey('total_price_usd')
        ->and($segment['total_price_usd'])->toBeGreaterThan(0);
});

test('agent shows a friendly lead line when the model hiccups after presenting offers', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');

    try {
        $this->seed(AirlineDemoSeeder::class);

        $build = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
            'origin' => 'IST',
            'destination' => 'LHR',
            'date' => '2026-07-29',
            'adults' => 1,
        ], null, false, null);

        $offerIds = $build['result']['recommended_picks'][0]['offer_ids'];

        $fake = new class extends FakeAzureChatClient
        {
            public function chat(array $messages, array $tools): array
            {
                $this->calls[] = ['messages' => $messages, 'tools' => $tools];

                if ($this->scriptedTurns === []) {
                    throw new RuntimeException('azure down');
                }

                return ['choices' => [['message' => array_shift($this->scriptedTurns)]]];
            }
        };
        $this->app->instance(AzureChatClient::class, $fake);
        $fake->pushToolCall('present_offers', [
            'offers' => [['offer_ids' => $offerIds, 'memo' => 'A tidy pick.']],
        ]);

        $response = app(ChatbotAgent::class)->send('hiccup-session', 'show me an offer', 'ours', 'en', null, true);
    } finally {
        Carbon::setTestNow();
    }

    expect($response['reply'])->not->toContain('could not reach')
        ->and($response['reply'])->toContain('put together')
        ->and(collect($response['tool_trace'])->pluck('tool')->all())->toContain('present_offers');
});

test('agent persists only clean conversational text and never raw tool messages', function () {
    $this->seed(AirlineDemoSeeder::class);

    $fake = new FakeAzureChatClient;
    $this->app->instance(AzureChatClient::class, $fake);
    $fake->pushToolCall('airports_for_city', ['city' => 'Istanbul']);
    $fake->pushToolCall('ask_follow_up', [
        'question' => 'Which Istanbul airport?',
        'choices' => [
            ['label' => 'IST', 'message' => 'Istanbul Airport'],
            ['label' => 'SAW', 'message' => 'Sabiha Gokcen'],
        ],
    ]);
    $fake->pushMessage('Which Istanbul airport?');

    app(ChatbotAgent::class)->send('persist-session', 'I want to fly from Istanbul', 'ours', 'en', null, true);

    $stored = app(ChatbotSessionStore::class)->get('persist-session')['messages'];
    $roles = collect($stored)->pluck('role')->all();

    expect($roles)->not->toContain('tool')
        ->and(collect($stored)->every(fn (array $message): bool => ! isset($message['tool_calls'])))->toBeTrue()
        ->and($roles[0])->toBe('system')
        ->and($roles)->toContain('user')
        ->and($roles)->toContain('assistant');
});

test('agent falls back to the pending question when the model hiccups after asking', function () {
    $fake = new class extends FakeAzureChatClient
    {
        public function chat(array $messages, array $tools): array
        {
            $this->calls[] = ['messages' => $messages, 'tools' => $tools];

            if ($this->scriptedTurns === []) {
                throw new RuntimeException('azure down');
            }

            return ['choices' => [['message' => array_shift($this->scriptedTurns)]]];
        }
    };
    $this->app->instance(AzureChatClient::class, $fake);
    $fake->pushToolCall('ask_follow_up', [
        'question' => 'One-way or round-trip?',
        'choices' => [
            ['label' => 'One-way', 'message' => 'One-way'],
            ['label' => 'Round-trip', 'message' => 'Round-trip'],
        ],
    ]);

    $response = app(ChatbotAgent::class)->send('ask-hiccup-session', 'I want to fly to London', 'ours', 'en', null, true);

    expect($response['reply'])->toBe('One-way or round-trip?')
        ->and($response['reply'])->not->toContain('could not reach')
        ->and(collect($response['tool_trace'])->pluck('tool')->all())->toContain('ask_follow_up');
});

test('a failing tool becomes a recoverable result instead of crashing the turn', function () {
    $fake = new FakeAzureChatClient;
    $this->app->instance(AzureChatClient::class, $fake);
    $fake->pushToolCall('build_dynamic_bundles', [
        'origin' => 'IST',
        'destination' => 'LHR',
        'date' => '2026-08-01',
        'service_specs' => [
            ['service_code' => 'CHECKED_BAG', 'quantity' => 9],
        ],
    ]);
    $fake->pushMessage('Let me adjust that for you.');

    $response = app(ChatbotAgent::class)->send('tool-fail-session', 'nine checked bags please', 'ours', 'en', null, true);

    expect($response['reply'])->toBe('Let me adjust that for you.');
});

test('agent presents at most two offers', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');

    try {
        $this->seed(AirlineDemoSeeder::class);

        $build = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
            'origin' => 'IST',
            'destination' => 'LHR',
            'date' => '2026-07-29',
            'adults' => 1,
        ], null, false, null);

        $offerId = $build['result']['recommended_picks'][0]['offer_ids'][0];

        $fake = new FakeAzureChatClient;
        $this->app->instance(AzureChatClient::class, $fake);
        $fake->pushToolCall('present_offers', [
            'offers' => [
                ['offer_id' => $offerId, 'memo' => 'Option A'],
                ['offer_id' => $offerId, 'memo' => 'Option B'],
                ['offer_id' => $offerId, 'memo' => 'Option C'],
            ],
        ]);
        $fake->pushMessage('Take your pick.');

        $response = app(ChatbotAgent::class)->send('max-two-session', 'show me offers', 'ours', 'en', null, true);
    } finally {
        Carbon::setTestNow();
    }

    expect($response['tool_trace'][0]['result']['offers'])->toHaveCount(2);
});

test('airports_for_city returns every airport serving a multi-airport city', function () {
    $this->seed(AirlineDemoSeeder::class);

    $result = app(ChatbotToolbox::class)->dispatch('airports_for_city', ['city' => 'Istanbul'], null, false, null)['result'];
    $codes = collect($result['airports'])->pluck('code')->all();

    expect($codes)->toContain('IST')
        ->and($codes)->toContain('SAW')
        ->and($result['count'])->toBeGreaterThanOrEqual(2);
});

test('a hidden custom bundle trigger asks a question with choices and no offer', function () {
    $fake = new FakeAzureChatClient;
    $this->app->instance(AzureChatClient::class, $fake);
    $fake->pushToolCall('ask_follow_up', [
        'question' => 'Which services matter most?',
        'choices' => [
            ['label' => 'Baggage', 'message' => 'I want extra baggage'],
            ['label' => 'Flexibility', 'message' => 'I want flexible changes'],
        ],
    ]);
    $fake->pushMessage('Which services matter most?');

    $response = app(ChatbotAgent::class)->send(
        'trigger-session',
        '',
        'ours',
        'en',
        null,
        true,
        'custom_bundle',
        ['page' => 'flight-results', 'trigger_context' => ['flight_number' => 'TK1980', 'cabin' => 'economy', 'origin' => 'IST', 'destination' => 'LHR']],
    );

    expect($response['reply'])->toBe('Which services matter most?')
        ->and($response['tool_trace'])->toHaveCount(1)
        ->and($response['tool_trace'][0]['tool'])->toBe('ask_follow_up')
        ->and($response['tool_trace'][0]['result']['choices'])->toHaveCount(2)
        ->and(collect($response['tool_trace'])->pluck('tool')->all())->not->toContain('present_offers');

    expect($fake->sentText())->toContain('Build with Wingo');
});

test('agent defers an off-topic request with a plain conversational reply', function () {
    $fake = new FakeAzureChatClient;
    $this->app->instance(AzureChatClient::class, $fake);
    $fake->pushMessage('I can only help with flights and travel. Where would you like to go?');

    $response = app(ChatbotAgent::class)->send('defer-session', 'What is the capital of France?', 'ours', 'en', null, true);

    expect($response['reply'])->toBe('I can only help with flights and travel. Where would you like to go?')
        ->and($response['tool_trace'])->toBe([]);
});

test('agent purchases only after an explicit confirmation across turns', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');

    try {
        $this->seed(AirlineDemoSeeder::class);

        $build = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
            'origin' => 'IST',
            'destination' => 'LHR',
            'date' => '2026-07-29',
            'adults' => 1,
        ], null, false, null);

        $offerIds = $build['result']['recommended_picks'][0]['offer_ids'];

        $fake = new FakeAzureChatClient;
        $this->app->instance(AzureChatClient::class, $fake);
        $agent = app(ChatbotAgent::class);

        $fake->pushToolCall('quote_purchase', [
            'offer_ids' => $offerIds,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'adults' => 1,
        ]);
        $fake->pushMessage('Please confirm and I will book it.');
        $agent->send('buy-session', 'Buy it for Ada Lovelace', 'ours', 'en', null, true);

        $fake->pushToolCall('commit_purchase');
        $fake->pushMessage('Booked!');
        $response = $agent->send('buy-session', 'Yes, please confirm.', 'ours', 'en', null, true);
    } finally {
        Carbon::setTestNow();
    }

    expect($response['reply'])->toBe('Booked!')
        ->and(Order::query()->where('first_name', 'Ada')->where('last_name', 'Lovelace')->exists())->toBeTrue();
});
