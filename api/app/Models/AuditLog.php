<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail. Never deleted, never truncated. See spec §8 — RBAC.
 *
 * @property int    $id
 * @property ?int   $actor_user_id
 * @property string $action
 * @property ?string $target_type
 * @property ?int   $target_id
 * @property ?array $metadata
 * @property ?string $ip
 * @property ?string $user_agent
 */
class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'actor_user_id', 'action', 'target_type', 'target_id',
        'metadata', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (AuditLog $log): void {
            if (! $log->created_at) {
                $log->created_at = now();
            }
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
