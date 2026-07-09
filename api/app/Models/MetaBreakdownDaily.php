<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of Meta spend per (brand, date, breakdown_type, segment) — powers the
 * dashboard's "Audience" view and its breakdown selector. Written by
 * meta:backfill-breakdown + the live sync; read by the audience dashboard query.
 */
class MetaBreakdownDaily extends Model
{
    protected $table = 'meta_breakdown_daily';

    protected $guarded = [];

    protected $casts = [
        'date'             => 'date',
        'spend'            => 'float',
        'impressions'      => 'integer',
        'clicks'           => 'integer',
        'reach'            => 'integer',
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
