<?php

declare(strict_types=1);

namespace App\Platforms\Support;

use RuntimeException;

/**
 * Thrown instead of a long in-process sleep() when a platform tells us to
 * back off and the caller is a queue worker (Throttle::deferToQueue(true)).
 *
 * SyncBrandDayJob catches this and release()s itself back onto the queue
 * with the platform-reported delay, freeing the worker slot for other
 * brands instead of blocking it for up to 30 seconds per wait. Console
 * commands (backfills, diagnostics) never enable defer mode, so their
 * behavior is unchanged — they still sleep inline.
 */
final class PlatformRateLimitedException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly string $platform,
        string $reason = 'rate limit',
    ) {
        parent::__construct(sprintf(
            '%s asked us to back off for %ds (%s); deferring to queue retry.',
            $platform,
            $retryAfterSeconds,
            $reason,
        ));
    }
}
