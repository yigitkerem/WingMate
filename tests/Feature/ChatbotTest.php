<?php

use App\Chatbot\ChatbotAgent;
use App\Chatbot\ChatbotSessionStore;
use App\Chatbot\ChatbotToolbox;
use App\Jobs\ProcessChatMessage;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Pricing\OfferBuilder;
use Database\Seeders\AirlineDemoSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('chat endpoint queues a chatbot message', function () {
    Queue::fake();

    $this->postJson('/api/message', [
        'session_id' => 'session-1',
        'message_id' => 'assistant-1',
        'message' => 'Which airports can I search?',
        'source' => 'ours',
        'language' => 'en',
    ])
        ->assertAccepted()
        ->assertJson(['status' => 'queued', 'message_id' => 'assistant-1']);

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->sessionId === 'session-1');
});

test('chat message status returns the queued reply when it is ready', function () {
    $this->getJson('/api/message/session-1/assistant-1')->assertAccepted();

    app(ChatbotSessionStore::class)->putMessageResult(
        sessionId: 'session-1',
        messageId: 'assistant-1',
        reply: 'Here is the fare answer.',
        toolTrace: [['tool' => 'list_airports']],
    );

    $this->getJson('/api/message/session-1/assistant-1')
        ->assertOk()
        ->assertJson(['status' => 'ready', 'reply' => 'Here is the fare answer.', 'failed' => false]);
});

test('agent sends offer based tool schemas', function () {
    config()->set('services.azure_openai.base_url', 'https://azure.test/openai/v1');
    config()->set('services.azure_openai.api_key', 'test-key');
    config()->set('services.azure_openai.model', 'test-model');

    Http::fake([
        'azure.test/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'You can search airport codes from the system.']]],
        ]),
    ]);

    app(ChatbotAgent::class)->send('schema-session', 'Which airports can I search?', 'ours', 'en');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"name":"list_airports"')
        && str_contains($request->body(), '"name":"quote_purchase"')
        && str_contains($request->body(), '"offer_id"')
        && ! str_contains($request->body(), 'availability_id'));
});

test('agent handles structured bundle requests without the external chat service', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');
    try {
        $this->seed(AirlineDemoSeeder::class);

        config()->set('services.azure_openai.base_url', null);
        config()->set('services.azure_openai.api_key', null);

        $response = app(ChatbotAgent::class)->send(
            'direct-bundle-session',
            'Build a custom bundle from IST to LHR on 2026-07-29. Preferred services: lowest sensible package.',
            'ours',
            'en',
        );
    } finally {
        Carbon::setTestNow();
    }

    expect($response['reply'])->toContain('Here are my top picks for you')
        ->and($response['reply'])->toContain('Do these look good to you?')
        ->and($response['reply'])->not->toContain('best custom bundle')
        ->and($response['reply'])->not->toContain('Backup pick')
        ->and($response['reply'])->not->toContain('could not reach')
        ->and($response['tool_trace'][0]['tool'])->toBe('build_dynamic_bundles');
});

test('agent handles structured round trip bundle requests', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');
    try {
        $this->seed(AirlineDemoSeeder::class);

        config()->set('services.azure_openai.base_url', null);
        config()->set('services.azure_openai.api_key', null);

        $response = app(ChatbotAgent::class)->send(
            'direct-roundtrip-session',
            'Build a custom bundle from IST to LHR on 2026-07-29. Return on 2026-08-06. Preferred services: CHECKED_BAG.',
            'ours',
            'en',
        );
    } finally {
        Carbon::setTestNow();
    }

    expect($response['tool_trace'][0]['result']['recommended_picks'][0]['offer_ids'])->toHaveCount(2)
        ->and($response['tool_trace'][0]['result']['recommended_picks'][0]['segments'])->toHaveCount(2);
});

test('toolbox searches local offers and builds custom bundle recommendations', function () {
    createSellablePricingFixture();

    $toolbox = app(ChatbotToolbox::class);
    $search = $toolbox->dispatch('search_flights', [
        'origin' => 'IST',
        'destination' => 'LHR',
        'date' => '2026-08-10',
        'adults' => 1,
    ], null, false)['result'];
    $bundle = $toolbox->dispatch('build_dynamic_bundles', [
        'origin' => 'IST',
        'destination' => 'LHR',
        'date' => '2026-08-10',
        'service_codes' => ['CHECKED_BAG'],
    ], null, false)['result'];

    expect($search['flight_count'])->toBe(1)
        ->and($search['flights'][0]['fares'][0]['class'])->toBe('ExtraFly')
        ->and($bundle['recommended_picks'])->toHaveCount(1);
});

test('bundle recommendations can use non public package inventory', function () {
    $fixture = createSellablePricingFixture();
    $fixture['bundle']->forceFill(['public' => false])->save();

    $bundle = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
        'origin' => 'IST',
        'destination' => 'LHR',
        'date' => '2026-08-10',
        'service_codes' => ['CHECKED_BAG'],
    ], null, false)['result'];

    expect($bundle['recommended_picks'][0]['public'])->toBeFalse();
});

test('bundle recommendations create custom add on offers for unmet demand', function () {
    createSellablePricingFixture();
    $lounge = Service::query()->firstOrCreate(['code' => 'LOUNGE'], [
        'name' => 'Lounge access',
        'category' => 'LOUNGE',
        'value_type' => 'boolean',
        'default_unit' => 'passenger',
    ]);
    ServicePrice::query()->firstOrCreate(['service_id' => $lounge->id], [
        'unit_price' => 55,
        'max_quantity' => 1,
    ]);

    $bundle = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
        'origin' => 'IST',
        'destination' => 'LHR',
        'date' => '2026-08-10',
        'service_codes' => ['LOUNGE'],
    ], null, false)['result'];
    $pick = $bundle['recommended_picks'][0];

    expect($pick['customized'])->toBeTrue()
        ->and($pick['class'])->toBe('Custom offer')
        ->and($pick['offer_ids'])->toHaveCount(1)
        ->and(collect($pick['services'])->firstWhere('code', 'LOUNGE')['source'])->toBe('customer');
});

test('bundle recommendations can apply exact refund and wifi service specs', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');
    try {
        $this->seed(AirlineDemoSeeder::class);

        $bundle = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
            'origin' => 'IST',
            'destination' => 'LHR',
            'date' => '2026-07-29',
            'service_specs' => [
                [
                    'service_code' => 'REFUNDABLE',
                    'value' => [
                        'amount' => 240,
                        'allowed' => true,
                        'window_hours' => 240,
                        'fee_type' => 'percent',
                        'fee_amount' => 25,
                    ],
                ],
                ['service_code' => 'WIFI_1GB', 'value' => ['amount' => 1024, 'data_mb' => 1024]],
            ],
        ], null, false)['result'];
    } finally {
        Carbon::setTestNow();
    }

    $pick = $bundle['recommended_picks'][0];

    expect($pick['customized'])->toBeTrue()
        ->and($pick['package_code'])->toBe('ECOFLY')
        ->and($pick['latest_refund_hours'])->toBe(240)
        ->and($pick['refund_fee_percent'])->toBe(25)
        ->and(collect($pick['services'])->firstWhere('code', 'REFUNDABLE')['source'])->toBe('customer')
        ->and(collect($pick['services'])->firstWhere('code', 'WIFI_1GB')['value']['data_mb'])->toBe(1024);
});

test('agent parses direct rich custom bundle requests', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');
    try {
        $this->seed(AirlineDemoSeeder::class);

        config()->set('services.azure_openai.base_url', null);
        config()->set('services.azure_openai.api_key', null);

        $response = app(ChatbotAgent::class)->send(
            'direct-rich-service-session',
            'Build a custom bundle from IST to LHR on 2026-07-29 with 1GB Wi-Fi and refund with 25% fee up to 10 days before.',
            'ours',
            'en',
        );
    } finally {
        Carbon::setTestNow();
    }

    $pick = $response['tool_trace'][0]['result']['recommended_picks'][0];

    expect($pick['latest_refund_hours'])->toBe(240)
        ->and($pick['refund_fee_percent'])->toBe(25)
        ->and(collect($pick['services'])->firstWhere('code', 'WIFI_1GB')['source'])->toBe('customer');
});

test('agent can build custom offers with excluded bags and included changes', function () {
    createSellablePricingFixture();
    $change = Service::query()->firstOrCreate(['code' => 'CHANGE_ALLOWED'], [
        'name' => 'Change right',
        'category' => 'FLEXIBILITY',
        'value_type' => 'boolean',
        'default_unit' => 'trip',
    ]);
    ServicePrice::query()->firstOrCreate(['service_id' => $change->id], [
        'unit_price' => 0,
        'max_quantity' => 1,
    ]);

    config()->set('services.azure_openai.base_url', null);
    config()->set('services.azure_openai.api_key', null);

    $response = app(ChatbotAgent::class)->send(
        'direct-no-bag-change-session',
        'Build a custom bundle from IST to LHR on 2026-08-10 with no bags but changes.',
        'ours',
        'en',
    );
    $pick = $response['tool_trace'][0]['result']['recommended_picks'][0];

    expect($pick['customized'])->toBeTrue()
        ->and($pick['checked_baggage_kg'])->toBe(0)
        ->and($pick['latest_change_hours'])->toBe(24)
        ->and(collect($pick['services'])->firstWhere('code', 'CHECKED_BAG')['value'])->toBeFalse()
        ->and(collect($pick['services'])->firstWhere('code', 'CHANGE_ALLOWED')['source'])->toBe('customer');
});

test('bundle recommendations only count services that are actually included', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');
    try {
        $this->seed(AirlineDemoSeeder::class);

        $bundle = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
            'origin' => 'IST',
            'destination' => 'LHR',
            'date' => '2026-07-29',
            'service_codes' => ['CHECKED_BAG', 'SEAT_SELECTION', 'CHANGE_ALLOWED', 'REFUNDABLE'],
        ], null, false)['result'];
    } finally {
        Carbon::setTestNow();
    }

    expect($bundle['recommended_picks'][0]['package_code'])->toBe('PRIMEFLY')
        ->and($bundle['recommended_picks'][0]['checked_baggage_kg'])->toBe(30)
        ->and($bundle['recommended_picks'][0]['seat_selection_free'])->toBeTrue()
        ->and($bundle['recommended_picks'][0]['latest_refund_hours'])->toBe(24);
});

test('bundle recommendations can return round trip checkout payloads', function () {
    Carbon::setTestNow('2026-07-29 10:00:00');
    try {
        $this->seed(AirlineDemoSeeder::class);

        $bundle = app(ChatbotToolbox::class)->dispatch('build_dynamic_bundles', [
            'origin' => 'IST',
            'destination' => 'LHR',
            'date' => '2026-07-29',
            'trip_type' => 'round_trip',
            'return_date' => '2026-08-06',
            'service_codes' => ['CHECKED_BAG'],
        ], null, false)['result'];
    } finally {
        Carbon::setTestNow();
    }

    expect($bundle['recommended_picks'][0]['offer_ids'])->toHaveCount(2)
        ->and($bundle['recommended_picks'][0]['segments'])->toHaveCount(2)
        ->and($bundle['recommended_picks'][0]['segments'][0]['origin'])->toBe('IST')
        ->and($bundle['recommended_picks'][0]['segments'][1]['origin'])->toBe('LHR');
});

test('toolbox requires explicit confirmation before committing a purchase', function () {
    $fixture = createSellablePricingFixture();
    $offer = app(OfferBuilder::class)->build($fixture['baseFare'], 1, 0, 0);

    $toolbox = app(ChatbotToolbox::class);
    $quote = $toolbox->dispatch('quote_purchase', [
        'offer_id' => $offer->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'passport_number' => 'x123',
    ], null, false);

    $blocked = $toolbox->dispatch('commit_purchase', [], $quote['pending_purchase'], false);
    $confirmed = $toolbox->dispatch('commit_purchase', [], $quote['pending_purchase'], true);

    expect($blocked['result']['error'])->toBe('confirmation_missing')
        ->and($confirmed['result']['success'])->toBeTrue()
        ->and($confirmed['result']['booking_reference'])->not->toBeEmpty();
});
