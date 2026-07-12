<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encrypted platform-level credential. Replaces env-only storage so the
 * agency owner can rotate accounts from the UI without an SSH session.
 *
 * @property int $id
 * @property string $platform     'meta' | 'google' | 'tiktok'
 * @property string $key          'system_user_token', 'mcc_refresh_token', etc.
 * @property string $value        decrypted automatically via the 'encrypted' cast
 * @property ?string $label
 * @property ?array $metadata
 * @property string $status       'active' | 'rotated' | 'revoked'
 */
class PlatformCredential extends Model
{
    protected $fillable = [
        'platform', 'key', 'value', 'label', 'metadata', 'status',
        'brand_id', 'last_used_at', 'expires_at', 'created_by_user_id',
    ];

    protected $casts = [
        'value'         => 'encrypted',   // AES-256 via APP_KEY
        'metadata'      => 'array',
        'last_used_at'  => 'datetime',
        'expires_at'    => 'datetime',
    ];

    protected $hidden = ['value'];        // belt-and-suspenders; never serialize raw

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Masked preview for the UI list view — first 4 + last 4 chars, dots
     * in between. If the encrypted column can't be decrypted (APP_KEY
     * drifted from what was used to encrypt), we return a placeholder so
     * the list endpoint keeps working. The UI uses isCorrupted() to flag
     * the row.
     */
    protected function maskedValue(): Attribute
    {
        return Attribute::get(function (): string {
            try {
                $v = $this->value;
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                return '••••••••';
            }
            if ($v === null || $v === '') return '';
            if (strlen($v) <= 12) return str_repeat('•', strlen($v));
            return substr($v, 0, 4) . str_repeat('•', 12) . substr($v, -4);
        });
    }

    /**
     * True if ciphertext exists in `value` but can't be decrypted with the
     * current APP_KEY. Lets the UI mark the row as "unreadable — please
     * re-enter" instead of silently showing an opaque masked preview.
     */
    public function isCorrupted(): bool
    {
        if (empty($this->attributes['value'])) {
            return false;
        }
        try {
            $this->value;
            return false;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return true;
        }
    }
}
