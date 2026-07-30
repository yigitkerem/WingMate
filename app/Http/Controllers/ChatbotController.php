<?php

namespace App\Http\Controllers;

use App\Chatbot\ChatbotSessionStore;
use App\Http\Requests\ChatMessageRequest;
use App\Jobs\ProcessChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ChatbotController extends Controller
{
    public function __invoke(ChatMessageRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $messageId = (string) ($validated['message_id'] ?? Str::uuid());

        ProcessChatMessage::dispatch(
            sessionId: (string) $validated['session_id'],
            messageId: $messageId,
            userText: (string) ($validated['message'] ?? ''),
            source: (string) ($validated['source'] ?? 'ours'),
            language: (string) ($validated['language'] ?? 'tr'),
            userId: $request->user()?->id,
            consent: (bool) ($validated['consent'] ?? false),
            trigger: isset($validated['trigger']) ? (string) $validated['trigger'] : null,
            context: is_array($validated['context'] ?? null) ? $validated['context'] : [],
        );

        return response()->json([
            'status' => 'queued',
            'message_id' => $messageId,
        ], 202);
    }

    public function show(string $sessionId, string $messageId, ChatbotSessionStore $sessionStore): JsonResponse
    {
        $result = $sessionStore->getMessageResult($sessionId, $messageId);

        if ($result === null) {
            return response()->json([
                'status' => 'pending',
                'message_id' => $messageId,
            ], 202);
        }

        return response()->json([
            'status' => 'ready',
            ...$result,
        ]);
    }
}
