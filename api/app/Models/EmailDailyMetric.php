<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Klaviyo-attributed email revenue per (brand, date, source, source_id) — GO-1.1.
 * source is 'flow' | 'campaign'. Written by the Klaviyo day-sync + klaviyo:backfill;
 * read as its OWN channel column (never summed into total revenue — §0.1 honesty law).
 * See the migration for the full contract.
 */
class EmailDailyMetric extends Model
{
    protected $table = 'email_daily_metrics';

    protected $guarded = [];

    protected $casts = [
        'date'             => 'date',
        'conversions'      => 'integer',
        'conversion_value' => 'float',
        'fx_rate_to_usd'   => 'float',
        'is_complete'      => 'boolean',
        'pulled_at'        => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
