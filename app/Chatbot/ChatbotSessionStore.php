<?php

namespace App\Chatbot;

use Illuminate\Support\Facades\Cache;

class ChatbotSessionStore
{
    /**
     * @return array{messages: array<int, array<string, mixed>>, pending_purchase: array<string, mixed>|null}
     */
    public function get(string $sessionId): array
    {
        $state = Cache::get($this->key($sessionId));

        if (! is_array($state)) {
            return [
                'messages' => [],
                'pending_purchase' => null,
            ];
        }

        return [
            'messages' => is_array($state['messages'] ?? null) ? $state['messages'] : [],
            'pending_purchase' => is_array($state['pending_purchase'] ?? null) ? $state['pending_purchase'] : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>|null  $pendingPurchase
     */
    public function put(string $sessionId, array $messages, ?array $pendingPurchase): void
    {
        Cache::put($this->key($sessionId), [
            'messages' => $messages,
            'pending_purchase' => $pendingPurchase,
        ], now()->addHours(6));
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolTrace
     */
    public function putMessageResult(string $sessionId, string $messageId, string $reply, array $toolTrace, bool $failed = false): void
    {
        Cache::put($this->messageKey($sessionId, $messageId), [
            'message_id' => $messageId,
            'reply' => $reply,
            'tool_trace' => $toolTrace,
            'failed' => $failed,
        ], now()->addMinutes(10));
    }

    /**
     * @return array{message_id: string, reply: string, tool_trace: array<int, array<string, mixed>>, failed: bool}|null
     */
    public function getMessageResult(string $sessionId, string $messageId): ?array
    {
        $result = Cache::get($this->messageKey($sessionId, $messageId));

        if (! is_array($result)) {
            return null;
        }

        return [
            'message_id' => (string) ($result['message_id'] ?? $messageId),
            'reply' => (string) ($result['reply'] ?? ''),
            'tool_trace' => is_array($result['tool_trace'] ?? null) ? $result['tool_trace'] : [],
            'failed' => (bool) ($result['failed'] ?? false),
        ];
    }

    private function key(string $sessionId): string
    {
        return "wingo-chat:{$sessionId}";
    }

    private function messageKey(string $sessionId, string $messageId): string
    {
        return "wingo-chat-message:{$sessionId}:{$messageId}";
    }
}
