<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Services\PlatformCredentialService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * OpenAI chat-completions driver (plain Guzzle, no SDK — §02 stack lock).
 * Key resolves through PlatformCredentialService('llm', 'openai_api_key')
 * → Settings UI row first, HELM_OPENAI_API_KEY env fallback.
 */
final class OpenAiClient implements LlmClient
{
    private readonly Client $http;

    public function __construct(
        private readonly PlatformCredentialService $credentials,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'timeout'         => (int) config('llm.timeout', 90),
            'connect_timeout' => 10,
        ]);
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) config('llm.openai.model');
    }

    public function complete(string $system, array $messages, ?int $maxTokens = null): string
    {
        $key = $this->credentials->get('llm', 'openai_api_key');

        try {
            $response = $this->http->post(config('llm.openai.base') . '/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => $this->model(),
                    'max_tokens'  => $maxTokens ?? (int) config('llm.max_tokens'),
                    'temperature' => (float) config('llm.temperature'),
                    'messages'    => array_merge(
                        [['role' => 'system', 'content' => $system]],
                        $messages,
                    ),
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new LlmException('OpenAI request failed: ' . str_replace($key, '[redacted]', $e->getMessage()), 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true);

        if (! is_array($body)) {
            throw new LlmException("OpenAI returned non-JSON (HTTP {$status}).");
        }

        if (isset($body['error'])) {
            $msg = (string) ($body['error']['message'] ?? 'unknown error');
            throw new LlmException("OpenAI API error (HTTP {$status}): {$msg}");
        }

        $text = (string) ($body['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            throw new LlmException('OpenAI returned an empty completion.');
        }

        return $text;
    }
}
