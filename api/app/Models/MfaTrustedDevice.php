<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A browser/device a user has chosen to trust so 2FA is skipped there for a
 * fixed window (Kanwar, 2026-07-22). Only the SHA-256 hash of the opaque token
 * is stored — the raw token lives only in that browser's localStorage.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $label
 * @property string|null $last_ip
 * @property \Carbon\CarbonImmutable|null $last_used_at
 * @property \Carbon\CarbonImmutable $expires_at
 */
class MfaTrustedDevice extends Model
{
    /** Fixed trust window: a trusted browser re-verifies with a code after this. */
    public const TRUST_DAYS = 14;

    protected $fillable = [
        'user_id', 'token_hash', 'label', 'last_ip', 'last_used_at', 'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'immutable_datetime',
        'expires_at'   => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Generate a fresh opaque device token (returned raw to the browser, once). */
    public static function newRawToken(): string
    {
        return Str::random(64);
    }

    /** The stored form of a raw token — SHA-256, so a DB leak can't reuse it. */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Best-effort human label from a user-agent string ("Chrome on macOS"), so
     * the device list is recognisable. Never throws; falls back to "Unknown".
     */
    public static function labelFromUserAgent(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return 'Unknown device';
        }

        $browser = 'Browser';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $browser = $name;
                break;
            }
        }

        $os = 'device';
        foreach (['Windows' => 'Windows', 'Mac OS' => 'macOS', 'Macintosh' => 'macOS', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Linux' => 'Linux'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $os = $name;
                break;
            }
        }

        return "{$browser} on {$os}";
    }
}
