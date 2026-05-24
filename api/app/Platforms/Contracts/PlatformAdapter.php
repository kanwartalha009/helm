<?php

declare(strict_types=1);

namespace App\Platforms\Contracts;

use App\Models\Brand;
use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;

/**
 * Every ad and commerce platform — current and future — implements this
 * interface. The rest of the codebase depends only on this contract, never
 * on platform-specific classes. See spec §6.
 */
interface PlatformAdapter
{
    /** Stable identifier — 'shopify', 'meta', 'google', 'tiktok', etc. */
    public function key(): string;

    /** Human label — 'Shopify', 'Meta Ads', etc. */
    public function label(): string;

    /* ---- OAuth / connection setup ---------------------------------- */

    /** Build the URL the user is sent to in order to authorize the connection. */
    public function authUrl(Brand $brand): string;

    /** Exchange the OAuth callback payload for a stored PlatformConnection row. */
    public function handleCallback(Brand $brand, array $payload): PlatformConnection;

    /* ---- Account discovery (Meta/Google/TikTok return many) -------- */

    /**
     * List accounts available under the agency's manager-level structure
     * (BM / MCC / BC). Each row: ['external_id' => ..., 'name' => ..., 'currency' => ...].
     */
    public function listAvailableAccounts(PlatformConnection $conn): array;

    /** Attach a specific external account ID to an existing connection. */
    public function attachAccount(PlatformConnection $conn, string $externalId): void;

    /* ---- Sync ------------------------------------------------------ */

    /**
     * Fetch one day of metrics for a brand × platform connection.
     * Date is already resolved to the brand's timezone — do not call now() or date().
     * Must throw on failure; do not return null or zero-filled snapshots.
     */
    public function fetchDay(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot;

    /** Lightweight check that the credentials still work. */
    public function healthCheck(PlatformConnection $conn): bool;
}
