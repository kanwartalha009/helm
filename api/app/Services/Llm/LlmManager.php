<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Services\PlatformCredentialService;
use InvalidArgumentException;

/**
 * Resolves the active LLM driver from config('llm.provider') — the single
 * place narrative + chat + diagnose obtain a client. Mirrors
 * PlatformRegistry's role for ad platforms.
 */
final class LlmManager
{
    public function __construct(private readonly PlatformCredentialService $credentials)
    {
    }

    /** The credential key the active provider needs. */
    public function credentialKey(): string
    {
        return $this->providerName() . '_api_key';
    }

    public function providerName(): string
    {
        // Settings UI choice first (workspace_settings.llm_provider — the
        // agency picks in Settings → Platform keys → AI / LLM), then the
        // HELM_LLM_PROVIDER env default. Wrapped so a missing table during
        // early bootstrap/tests falls back to config cleanly.
        try {
            $fromSettings = \App\Models\WorkspaceSetting::getValue('llm_provider');
        } catch (\Throwable) {
            $fromSettings = null;
        }

        $provider = is_string($fromSettings) && $fromSettings !== ''
            ? $fromSettings
            : (string) config('llm.provider', 'anthropic');

        if (! in_array($provider, ['anthropic', 'openai'], true)) {
            throw new InvalidArgumentException("Unknown LLM provider: {$provider} (expected anthropic|openai).");
        }

        return $provider;
    }

    /** True when the active provider has a key on file (DB row or env). */
    public function enabled(): bool
    {
        try {
            return $this->credentials->has('llm', $this->credentialKey());
        } catch (\Throwable) {
            return false;
        }
    }

    public function client(): LlmClient
    {
        return match ($this->providerName()) {
            'anthropic' => app(AnthropicClient::class),
            'openai'    => app(OpenAiClient::class),
        };
    }

    /**
     * Cheapest possible live check — used by `php artisan llm:diagnose` and
     * the Settings "Test" button. Returns ok/message + timing; never throws.
     *
     * @return array{ok: bool, message: string, provider: string, model: ?string, ms: ?int}
     */
    public function ping(): array
    {
        $provider = $this->providerName();

        if (! $this->enabled()) {
            return [
                'ok'       => false,
                'provider' => $provider,
                'model'    => null,
                'ms'       => null,
                'message'  => "No {$provider} API key on file. Paste it at Settings → Platform keys → AI / LLM"
                    . ' (or set ' . ($provider === 'anthropic' ? 'HELM_ANTHROPIC_API_KEY' : 'HELM_OPENAI_API_KEY') . ' in .env).',
            ];
        }

        try {
            $client = $this->client();
            $t0     = hrtime(true);
            $out    = $client->complete(
                'You are a connectivity check. Reply with exactly: ok',
                [['role' => 'user', 'content' => 'ping']],
                maxTokens: 8,
            );
            $ms = (int) ((hrtime(true) - $t0) / 1e6);

            return [
                'ok'       => true,
                'provider' => $provider,
                'model'    => $client->model(),
                'ms'       => $ms,
                'message'  => 'Completion OK (' . trim($out) . ", {$ms} ms).",
            ];
        } catch (\Throwable $e) {
            return [
                'ok'       => false,
                'provider' => $provider,
                'model'    => null,
                'ms'       => null,
                'message'  => $e->getMessage(),
            ];
        }
    }
}
