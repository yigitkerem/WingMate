<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatbotMessageReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $toolTrace
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $messageId,
        public readonly string $reply,
        public readonly array $toolTrace = [],
        public readonly bool $failed = false,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("wingo-chat.{$this->sessionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ChatbotMessageReady';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'reply' => $this->reply,
            'tool_trace' => $this->toolTrace,
            'failed' => $this->failed,
        ];
    }
}
