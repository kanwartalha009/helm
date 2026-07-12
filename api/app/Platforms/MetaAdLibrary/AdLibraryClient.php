<?php

declare(strict_types=1);

namespace App\Platforms\MetaAdLibrary;

use App\Platforms\Support\PlatformRateLimitedException;
use App\Services\PlatformCredentialService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Client for the OFFICIAL Meta Ad Library API (Graph `ads_archive`) — the ONLY
 * place Ad Library HTTP is allowed (guardrail: all external HTTP in the adapter).
 *
 * Enforces a HARD hourly call budget (config adslibrary.call_budget_per_hour,
 * default 150 — clear of the ~200/hr unpublished cap). When the budget for the
 * current clock hour is spent it throws PlatformRateLimitedException with the
 * seconds until the next hour; the caller (adlib:refresh) decides sleep-and-resume
 * vs. hard-stop at 06:00. Pagination counts: each cursor page is one get().
 *
 * The access token is a query param Meta requires; it is NEVER logged and NEVER
 * returned outward. ad_snapshot_url embeds the token — callers must strip it (the
 * ArchiveFetcher does) and store only the token-free public permalink.
 *
 * Product lens (D-022): the token is a per-workspace credential — each agency
 * supplies its own verified token; Helm never runs one shared scraping identity.
 */
class AdLibraryClient
{
    private const BASE = 'https://graph.facebook.com';

    private int $callsThisHour = 0;
    private ?string $hourKey = null;
    private readonly int $budget;

    public function __construct(private readonly PlatformCredentialService $credentials)
    {
        $this->budget = max(1, (int) config('adslibrary.call_budget_per_hour', 150));
    }

    /**
     * One Graph GET against the Ad Library edge (default ads_archive). Counts
     * against the hourly budget; throws PlatformRateLimitedException when spent.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed> decoded body (data + paging), or throws
     */
    public function get(array $params, string $edge = 'ads_archive'): array
    {
        $this->guardBudget();

        $token   = $this->credentials->get('meta_adlib', 'access_token');
        $version = (string) config('services.meta.version', 'v24.0');

        try {
            $res = Http::timeout(30)->get(self::BASE . "/{$version}/{$edge}", array_merge($params, [
                'access_token' => $token,
            ]));
        } catch (Throwable $e) {
            throw new RuntimeException('Ad Library request failed: ' . $e->getMessage(), 0, $e);
        }

        $body = $res->json();

        if (! $res->successful()) {
            $err  = is_array($body) ? (array) ($body['error'] ?? []) : [];
            $code = (int) ($err['code'] ?? 0);
            $msg  = (string) ($err['message'] ?? ('HTTP ' . $res->status()));

            // Meta throttling codes (4 app-level, 17 user-level, 32 page-level,
            // 613 custom-rate) → treat as a rate limit so the command backs off,
            // never a hard failure. Log without the token (params are not logged).
            if (in_array($code, [4, 17, 32, 613], true)) {
                Log::warning('adlib.rate_limited', ['code' => $code, 'edge' => $edge]);
                throw new PlatformRateLimitedException($this->secondsToNextHour(), 'meta_adlib', 'Meta rate limit ' . $code);
            }

            throw new RuntimeException('Ad Library API: ' . $msg);
        }

        return is_array($body) ? $body : [];
    }

    /** How many budgeted calls have been made in the current clock hour. */
    public function callsUsed(): int
    {
        $this->rollHour();

        return $this->callsThisHour;
    }

    /**
     * One ads_archive call (limit 1) to confirm the saved token is valid and the
     * app has Ad Library access. Returns the credential-card test shape.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(): array
    {
        if (! $this->credentials->has('meta_adlib', 'access_token')) {
            return ['ok' => false, 'message' => 'No Ad Library token saved yet.'];
        }

        $countries = array_values((array) config('adslibrary.default_countries', ['ES']));

        try {
            $body = $this->get([
                'ad_reached_countries' => json_encode($countries),
                'ad_type'              => 'ALL',
                'ad_active_status'     => 'ALL',
                'search_terms'         => 'shop',
                'fields'               => 'id',
                'limit'                => 1,
            ]);
        } catch (Throwable $e) {
            // The message is Meta's (or a transport error) — never the token.
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (array_key_exists('data', $body)) {
            return ['ok' => true, 'message' => 'Ad Library token is valid (' . implode(', ', $countries) . ').'];
        }

        return ['ok' => false, 'message' => 'Unexpected Ad Library response (no data envelope).'];
    }

    private function guardBudget(): void
    {
        $this->rollHour();
        if ($this->callsThisHour >= $this->budget) {
            throw new PlatformRateLimitedException($this->secondsToNextHour(), 'meta_adlib', 'hourly call budget reached');
        }
        $this->callsThisHour++;
    }

    private function rollHour(): void
    {
        $key = date('YmdH');
        if ($this->hourKey !== $key) {
            $this->hourKey = $key;
            $this->callsThisHour = 0;
        }
    }

    private function secondsToNextHour(): int
    {
        return 3600 - ((int) date('i') * 60 + (int) date('s')) + 5; // + small jitter cushion
    }
}
