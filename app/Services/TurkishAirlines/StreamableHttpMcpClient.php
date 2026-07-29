<?php

namespace App\Services\TurkishAirlines;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class StreamableHttpMcpClient
{
    private const string PROTOCOL_VERSION = '2025-03-26';

    private int $nextRequestId = 1;

    public function isConfigured(): bool
    {
        return filled($this->url());
    }

    /**
     * @return array{status: string, message?: string, raw?: array<string, mixed>}
     */
    public function callTool(string $toolName, array $arguments): array
    {
        if (! $this->isConfigured()) {
            return [
                'status' => 'not_configured',
                'message' => 'Set THY_MCP_URL before running a live import.',
            ];
        }

        $token = $this->accessToken();

        if (! $token) {
            return [
                'status' => 'auth_required',
                'message' => 'Set THY_MCP_TOKEN or sign in once so THY_MCP_TOKEN_FILE contains OAuth tokens.',
            ];
        }

        try {
            $initialize = $this->jsonRpcRequest('initialize', [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new \stdClass,
                'clientInfo' => [
                    'name' => (string) config('services.thy_mcp.client_name', 'Dynamic Pricer'),
                    'version' => '1.0.0',
                ],
            ], $token);

            if ($initialize->unauthorized() && $token = $this->refreshAccessToken()) {
                $initialize = $this->jsonRpcRequest('initialize', [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => new \stdClass,
                    'clientInfo' => [
                        'name' => (string) config('services.thy_mcp.client_name', 'Dynamic Pricer'),
                        'version' => '1.0.0',
                    ],
                ], $token);
            }

            if ($initialize->unauthorized()) {
                return [
                    'status' => 'auth_required',
                    'message' => 'The stored THY MCP token was rejected. Sign in again to refresh the token file.',
                    'raw' => $this->decodeResponse($initialize),
                ];
            }

            $initialize->throw();
            $sessionId = $initialize->header('Mcp-Session-Id');

            $this->jsonRpcNotification('notifications/initialized', $token, $sessionId);

            $toolResponse = $this->jsonRpcRequest('tools/call', [
                'name' => $toolName,
                'arguments' => $arguments,
            ], $token, $sessionId);

            if ($toolResponse->unauthorized()) {
                return [
                    'status' => 'auth_required',
                    'message' => 'The THY MCP token expired before the tool call completed. Run the import again after refreshing auth.',
                    'raw' => $this->decodeResponse($toolResponse),
                ];
            }

            $toolResponse->throw();

            return [
                'status' => 'ok',
                'raw' => $this->decodeResponse($toolResponse),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'message' => "THY MCP connection error: {$exception->getMessage()}",
            ];
        }
    }

    private function jsonRpcRequest(string $method, array $params, string $token, ?string $sessionId = null): Response
    {
        return $this->pendingRequest($token, $sessionId)->post($this->url(), [
            'jsonrpc' => '2.0',
            'id' => $this->nextRequestId++,
            'method' => $method,
            'params' => $params,
        ]);
    }

    private function jsonRpcNotification(string $method, string $token, ?string $sessionId = null): void
    {
        $this->pendingRequest($token, $sessionId)->post($this->url(), [
            'jsonrpc' => '2.0',
            'method' => $method,
        ]);
    }

    private function pendingRequest(string $token, ?string $sessionId = null): PendingRequest
    {
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
        ];

        if ($sessionId) {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        return Http::withHeaders($headers)
            ->withToken($token)
            ->asJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([100, 300], throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        $json = $response->json();

        if (is_array($json)) {
            return $json;
        }

        $body = $response->body();

        if (str_contains($response->header('Content-Type', ''), 'text/event-stream')) {
            foreach (array_reverse(preg_split('/\R/', $body) ?: []) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $decoded = json_decode(trim(Str::after($line, 'data:')), true);

                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['body' => $body];
    }

    private function accessToken(): ?string
    {
        $token = config('services.thy_mcp.token');

        if (filled($token)) {
            return (string) $token;
        }

        $tokens = $this->storedTokens();

        return is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : null;
    }

    private function refreshAccessToken(): ?string
    {
        $tokenFile = $this->tokenFile();

        if (! $tokenFile || ! File::exists($tokenFile)) {
            return null;
        }

        $stored = json_decode(File::get($tokenFile), true);
        $refreshToken = data_get($stored, 'tokens.refresh_token');
        $clientId = data_get($stored, 'client_info.client_id');

        if (! is_string($refreshToken) || ! is_string($clientId)) {
            return null;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->connectTimeout(5)
            ->post($this->tokenEndpoint(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $tokens = $response->json();

        if (! is_array($tokens) || ! is_string($tokens['access_token'] ?? null)) {
            return null;
        }

        $stored['tokens'] = $tokens;
        $stored['tokens']['obtained_at'] = now()->timestamp;

        File::put($tokenFile, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $tokens['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedTokens(): array
    {
        $tokenFile = $this->tokenFile();

        if (! $tokenFile || ! File::exists($tokenFile)) {
            return [];
        }

        $tokens = json_decode(File::get($tokenFile), true);

        return is_array($tokens) ? (array) data_get($tokens, 'tokens', []) : [];
    }

    private function tokenFile(): ?string
    {
        $tokenFile = config('services.thy_mcp.token_file');

        if (! is_string($tokenFile) || $tokenFile === '') {
            return null;
        }

        return str_starts_with($tokenFile, DIRECTORY_SEPARATOR) ? $tokenFile : base_path($tokenFile);
    }

    private function tokenEndpoint(): string
    {
        $parts = parse_url($this->url());

        if (! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('THY_MCP_URL must be an absolute URL.');
        }

        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}/token";
    }

    private function url(): string
    {
        return (string) config('services.thy_mcp.url', '');
    }
}
