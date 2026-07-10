<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Services\PlatformCredentialService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Anthropic Messages API driver (plain Guzzle, no SDK — §02 stack lock).
 * Key resolves through PlatformCredentialService('llm', 'anthropic_api_key')
 * → Settings UI row first, HELM_ANTHROPIC_API_KEY env fallback.
 */
final class AnthropicClient implements LlmClient
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
        return 'anthropic';
    }

    public function model(): string
    {
        return (string) config('llm.anthropic.model');
    }

    public function complete(string $system, array $messages, ?int $maxTokens = null): string
    {
        $key = $this->credentials->get('llm', 'anthropic_api_key');

        try {
            $response = $this->http->post(config('llm.anthropic.base') . '/v1/messages', [
                'headers' => [
                    'x-api-key'         => $key,
                    'anthropic-version' => (string) config('llm.anthropic.version'),
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'       => $this->model(),
                    'max_tokens'  => $maxTokens ?? (int) config('llm.max_tokens'),
                    'temperature' => (float) config('llm.temperature'),
                    'system'      => $system,
                    'messages'    => $messages,
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new LlmException('Anthropic request failed: ' . str_replace($key, '[redacted]', $e->getMessage()), 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true);

        if (! is_array($body)) {
            throw new LlmException("Anthropic returned non-JSON (HTTP {$status}).");
        }

        if (isset($body['error'])) {
            $msg = (string) ($body['error']['message'] ?? 'unknown error');
            throw new LlmException("Anthropic API error (HTTP {$status}): {$msg}");
        }

        // Concatenate text blocks; tool/thinking blocks (not requested) are skipped.
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        if ($text === '') {
            throw new LlmException('Anthropic returned an empty completion.');
        }

        return $text;
    }
}
