<?php

namespace Tests\Support;

use App\Chatbot\AzureChatClient;

/**
 * Deterministic, offline stand-in for the Azure OpenAI chat client.
 *
 * Script a sequence of assistant turns with pushToolCall()/pushMessage();
 * each ChatbotAgent iteration shifts the next scripted turn.
 */
class FakeAzureChatClient extends AzureChatClient
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $scriptedTurns = [];

    /**
     * @var array<int, array{messages: array<int, array<string, mixed>>, tools: array<int, array<string, mixed>>}>
     */
    public array $calls = [];

    public function __construct()
    {
        // Intentionally bypasses the HTTP factory dependency for tests.
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function pushToolCall(string $name, array $arguments = []): void
    {
        $this->scriptedTurns[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_'.count($this->scriptedTurns),
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
                ],
            ]],
        ];
    }

    public function pushMessage(string $content): void
    {
        $this->scriptedTurns[] = [
            'role' => 'assistant',
            'content' => $content,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    public function chat(array $messages, array $tools): array
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools];

        $message = array_shift($this->scriptedTurns) ?? [
            'role' => 'assistant',
            'content' => '(no scripted reply)',
        ];

        return ['choices' => [['message' => $message]]];
    }

    /**
     * Concatenated text content of every message sent to the model.
     */
    public function sentText(): string
    {
        return collect($this->calls)
            ->flatMap(fn (array $call): array => $call['messages'])
            ->map(fn (array $message): string => is_string($message['content'] ?? null) ? $message['content'] : '')
            ->implode("\n");
    }
}
