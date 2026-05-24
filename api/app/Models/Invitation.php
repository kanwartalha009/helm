<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int     $id
 * @property string  $email
 * @property string  $role        manager | team_member | brand_user
 * @property string  $token
 * @property ?string $note
 * @property ?array  $brand_ids
 * @property ?int    $invited_by_user_id
 * @property ?int    $accepted_by_user_id
 * @property \Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $accepted_at
 * @property ?\Illuminate\Support\Carbon $revoked_at
 */
class Invitation extends Model
{
    protected $fillable = [
        'email', 'role', 'token', 'note', 'brand_ids',
        'invited_by_user_id', 'accepted_by_user_id',
        'expires_at', 'accepted_at', 'revoked_at',
    ];

    protected $casts = [
        'brand_ids'   => 'array',
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    /** Tokens are sensitive — never serialize them in the API by accident. */
    protected $hidden = ['token'];

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        if ($this->accepted_at !== null) return 'accepted';
        if ($this->revoked_at !== null) return 'revoked';
        if ($this->expires_at->isPast()) return 'expired';
        return 'pending';
    }
}
