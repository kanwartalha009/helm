<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Application-level clock correction for TOTP (Kanwar, 2026-07-21).
 *
 * The Cloudways host runs ~45s behind UTC and there is no sudo/NTP access to
 * fix the OS clock, which is what broke 2FA the first time: a 45s-off server
 * puts every TOTP code right on the failure boundary (reliable only within
 * ~15s of true time). Rather than widen the verification window — which just
 * enlarges the attack surface — we correct the time in the app: query a real
 * NTP server over UDP, cache the offset for an hour, and feed the corrected
 * timestep into google2fa. The OS clock drifts only ~1-2s/day, so an hourly
 * refresh keeps us well inside tolerance while a ±1-step window (±30s) stays
 * tight.
 *
 * Fails safe: any NTP error (unreachable host, timeout, garbled packet, an
 * absurd offset) yields a 0 offset — i.e. fall back to the system clock — and
 * is logged, never thrown. A single request per hour pays the (≤2s) lookup;
 * every other request reads the cached offset.
 */
final class NtpTime
{
    private const CACHE_KEY   = 'helm.mfa.ntp_offset';
    private const NTP_EPOCH_DELTA = 2_208_988_800; // seconds between 1900 and 1970 epochs

    /** Offset in whole seconds to ADD to the system clock to reach true UTC (0 when unknown/disabled). */
    public function offsetSeconds(): int
    {
        if (! (bool) config('helm.mfa.ntp.enabled', true)) {
            return 0;
        }

        $ttl = max(60, (int) config('helm.mfa.ntp.cache_ttl', 3600));

        // Never let a clock-sync problem break authentication: any failure here
        // (cache driver, network, a disabled socket function) falls back to the
        // raw system clock (offset 0).
        try {
            return (int) Cache::remember(self::CACHE_KEY, now()->addSeconds($ttl), function (): int {
                return $this->queryOffset();
            });
        } catch (Throwable $e) {
            Log::warning('mfa.ntp.offset_unavailable', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /** Corrected wall-clock time (UNIX seconds, float) — system time plus the NTP offset. */
    public function now(): float
    {
        return microtime(true) + $this->offsetSeconds();
    }

    /**
     * The TOTP time-step for the corrected clock — floor(correctedSeconds /
     * period). This is exactly what google2fa's getTimestamp() computes off the
     * system clock; we hand it the corrected value instead.
     */
    public function timeStep(int $period = 30): int
    {
        $period = max(1, $period);

        return (int) floor($this->now() / $period);
    }

    /** Force a fresh NTP lookup (used by a scheduled warm-up command, so live requests never pay the cost). */
    public function refresh(): int
    {
        Cache::forget(self::CACHE_KEY);

        return $this->offsetSeconds();
    }

    /**
     * Minimal SNTP client: one UDP round-trip, parse the transmit timestamp,
     * return (serverTime − localMidpoint) rounded to whole seconds. Returns 0
     * on any failure so authentication never depends on the network being up.
     */
    private function queryOffset(): int
    {
        $host    = (string) config('helm.mfa.ntp.host', 'time.google.com');
        $timeout = max(1, (int) config('helm.mfa.ntp.timeout', 2));
        $maxAbs  = max(1, (int) config('helm.mfa.ntp.max_offset', 3600));

        $sock = null;
        try {
            // Inside the try so a disabled `stream_socket_client` (some managed
            // hosts restrict it) degrades to offset 0 instead of a 500.
            $sock = @stream_socket_client("udp://{$host}:123", $errno, $errstr, $timeout);
            if ($sock === false) {
                Log::warning('mfa.ntp.connect_failed', ['host' => $host, 'error' => $errstr]);

                return 0;
            }

            stream_set_timeout($sock, $timeout);

            // Request: LI = 0, VN = 3, Mode = 3 (client) in the first byte, rest zero.
            $packet = "\x1b" . str_repeat("\0", 47);

            $t0 = microtime(true);
            if (@fwrite($sock, $packet) === false) {
                return 0;
            }
            $response = @fread($sock, 48);
            $t1 = microtime(true);

            if (! is_string($response) || strlen($response) < 48) {
                Log::warning('mfa.ntp.bad_response', ['host' => $host, 'len' => is_string($response) ? strlen($response) : 0]);

                return 0;
            }

            // Transmit Timestamp integer seconds = the 11th 32-bit big-endian word (byte offset 40).
            $words = unpack('N12', $response);
            if ($words === false || ! isset($words[11])) {
                return 0;
            }

            $serverUnix = ((int) $words[11]) - self::NTP_EPOCH_DELTA;
            $localMid   = ($t0 + $t1) / 2.0;
            $offset     = (int) round($serverUnix - $localMid);

            if (abs($offset) > $maxAbs) {
                // A wildly implausible offset is more likely a garbled/hostile
                // packet than a truly hours-off server — don't let it break auth.
                Log::warning('mfa.ntp.offset_rejected', ['host' => $host, 'offset' => $offset, 'max' => $maxAbs]);

                return 0;
            }

            return $offset;
        } catch (Throwable $e) {
            Log::warning('mfa.ntp.query_failed', ['host' => $host, 'error' => $e->getMessage()]);

            return 0;
        } finally {
            if (is_resource($sock)) {
                @fclose($sock);
            }
        }
    }
}
