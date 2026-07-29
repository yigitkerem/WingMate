<?php

namespace App\Chatbot;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;

class AzureChatClient
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function chat(array $messages, array $tools): array
    {
        $baseUrl = rtrim((string) config('services.azure_openai.base_url'), '/');
        $apiKey = (string) config('services.azure_openai.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            throw new \RuntimeException('Azure OpenAI is not configured.');
        }

        return $this->http
            ->timeout(45)
            ->retry(2, 250)
            ->withToken($apiKey)
            ->withHeaders(['api-key' => $apiKey])
            ->post("{$baseUrl}/chat/completions", [
                'model' => config('services.azure_openai.model'),
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
            ])
            ->throw()
            ->json();
    }
}
