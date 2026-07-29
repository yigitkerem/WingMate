<?php

namespace App\Jobs;

use App\Chatbot\ChatbotAgent;
use App\Chatbot\ChatbotSessionStore;
use App\Events\ChatbotMessageReady;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessChatMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $messageId,
        public readonly string $userText,
        public readonly string $source = 'ours',
        public readonly string $language = 'tr',
    ) {}

    public function handle(ChatbotAgent $agent, ChatbotSessionStore $sessionStore): void
    {
        try {
            $response = $agent->send(
                sessionId: $this->sessionId,
                userText: $this->userText,
                source: $this->source,
                language: $this->language,
            );

            $sessionStore->putMessageResult(
                sessionId: $this->sessionId,
                messageId: $this->messageId,
                reply: $response['reply'],
                toolTrace: $response['tool_trace'],
            );

            ChatbotMessageReady::dispatch(
                sessionId: $this->sessionId,
                messageId: $this->messageId,
                reply: $response['reply'],
                toolTrace: $response['tool_trace'],
            );
        } catch (\Throwable $exception) {
            Log::warning('Wingo chatbot message failed.', [
                'session_id' => $this->sessionId,
                'message_id' => $this->messageId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $fallbackReply = 'I could not reach the fare assistant just now. Please try again in a moment.';

            $sessionStore->putMessageResult(
                sessionId: $this->sessionId,
                messageId: $this->messageId,
                reply: $fallbackReply,
                toolTrace: [],
                failed: true,
            );

            ChatbotMessageReady::dispatch(
                sessionId: $this->sessionId,
                messageId: $this->messageId,
                reply: $fallbackReply,
                toolTrace: [],
                failed: true,
            );
        }
    }
}
