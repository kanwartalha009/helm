<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A snapshot of a generated report, addressable by an unguessable token for the
 * public read-only link. Stores the filters and the edited narrative/comments
 * as they were at send time, so a shared link stays stable even as live data
 * moves. Created when the operator hits "Create share link" or exports.
 */
class ReportShare extends Model
{
    protected $fillable = [
        'brand_id', 'report_type', 'token', 'filters', 'content',
        'created_by_user_id', 'expires_at',
    ];

    protected $casts = [
        'filters'    => 'array',
        'content'    => 'array',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $share): void {
            $share->token ??= Str::random(40);
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
