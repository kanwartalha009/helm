<?php

declare(strict_types=1);

namespace App\Platforms\Support;

/**
 * Single choke point for every "the platform told us to wait" pause in the
 * adapter clients (Shopify cost ceiling, Meta rate limits, TikTok 40100,
 * transient connect retries).
 *
 * Two modes:
 *  - default: sleep inline, exactly as the clients always did. Console
 *    commands (backfills, diagnose) run in this mode.
 *  - defer-to-queue (enabled by SyncBrandDayJob for real queue workers):
 *    short pauses still sleep inline, but anything longer than
 *    DEFER_THRESHOLD_SECS throws PlatformRateLimitedException so the job
 *    can release() itself with the delay instead of blocking a worker
 *    slot. At 12 sync workers, 30-second in-process sleeps are the
 *    difference between a sync run finishing in minutes vs hours once
 *    brand count grows (audit 2026-07-10, layer: API contract).
 *
 * The flag is process-global on purpose: one worker processes one job at
 * a time, and the job resets it in a finally block.
 */
final class Throttle
{
    /** Pauses at or below this many seconds always sleep inline — a release/retry round-trip costs more than the wait. */
    private const DEFER_THRESHOLD_SECS = 5;

    private static bool $deferToQueue = false;

    public static function deferToQueue(bool $on): void
    {
        self::$deferToQueue = $on;
    }

    /**
     * Honor a platform-requested pause of $seconds.
     *
     * @throws PlatformRateLimitedException in defer mode for long waits
     */
    public static function wait(int $seconds, string $platform, string $reason = 'rate limit'): void
    {
        if ($seconds <= 0) {
            return;
        }

        if (self::$deferToQueue && $seconds > self::DEFER_THRESHOLD_SECS) {
            throw new PlatformRateLimitedException($seconds, $platform, $reason);
        }

        sleep($seconds);
    }
}
