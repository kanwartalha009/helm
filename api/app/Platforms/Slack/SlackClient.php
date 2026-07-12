<?php

declare(strict_types=1);

namespace App\Platforms\Slack;

use App\Services\PlatformCredentialService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Slack incoming-webhook client (GO-3.5, master plan §3.3). The ONLY place Slack HTTP
 * lives (adapter guardrail).
 *
 * The webhook URL is a SECRET — Slack revokes leaked ones — so it is stored in
 * platform_credentials (encrypted at rest via the model's `encrypted` cast) and is never
 * logged, echoed, or returned to the client.
 *
 * FAILURE TOLERANCE IS THE POINT: a digest is a nice-to-have. If Slack is down, rate-limits
 * us, or the workspace revoked the webhook, that must NEVER break the scheduled run or
 * surface as an incident. Every failure is caught, logged, and reported as `ok: false` —
 * the sender returns a result, it does not throw.
 */
class SlackClient
{
    public function __construct(private readonly PlatformCredentialService $credentials) {}

    public function configured(): bool
    {
        return $this->credentials->has('slack', 'webhook_url');
    }

    /**
     * POST Block Kit blocks to the workspace webhook.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array{ok: bool, message: string}
     */
    public function post(array $blocks, string $fallbackText = 'Helm weekly digest'): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'No Slack webhook configured.'];
        }

        try {
            $url = $this->credentials->get('slack', 'webhook_url');

            $res = Http::timeout(15)->post($url, [
                // `text` is the notification/fallback line; blocks are the rendered body.
                'text'   => $fallbackText,
                'blocks' => $blocks,
            ]);
        } catch (Throwable $e) {
            // Never let a chat integration take down the scheduler.
            Log::warning('slack.post.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Slack request failed: ' . $e->getMessage()];
        }

        if ($res->status() === 429) {
            $retry = (int) ($res->header('Retry-After') ?: 1);
            Log::warning('slack.rate_limited', ['retry_after' => $retry]);

            return ['ok' => false, 'message' => "Slack rate-limited (retry after {$retry}s)."];
        }

        if (! $res->successful()) {
            // Slack returns a plain-text reason ('no_service', 'invalid_payload', …).
            Log::warning('slack.post.rejected', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 200)]);

            return ['ok' => false, 'message' => 'Slack rejected the message: ' . mb_substr($res->body(), 0, 120)];
        }

        return ['ok' => true, 'message' => 'Sent to Slack.'];
    }

    /**
     * The Settings "Test" button — posts a single harmless message so the operator can
     * confirm the webhook lands in the right channel before a real digest goes out.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'No Slack webhook saved yet. Create one via the Slack app install flow and paste it here.'];
        }

        return $this->post(
            [[
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => ':white_check_mark: *Helm is connected.* This is a test message — your weekly digest will arrive in this channel.'],
            ]],
            'Helm test message',
        );
    }
}
