<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of ad-set / ad-group level performance per (brand, platform, date,
 * ad_set_id) — the middle layer between campaigns and ads (spec §4 Phase 3).
 * `entity_kind` is 'ad_set' normally, 'asset_group' for Google Performance Max
 * (which has no ad groups). Written by AdSetSync + ads:backfill-adsets; read by
 * the AdSetFlags engine (Phase 4).
 *
 * Budget / learning_status / status are POINT-IN-TIME snapshots at sync time —
 * the platform APIs expose no budget history, so a row shows that day's snapshot.
 * Any UI rendering budget must caption "as of last sync".
 */
class AdSetDailyMetric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date'                    => 'date',
        'daily_budget'            => 'float',
        'lifetime_budget'         => 'float',
        'spend'                   => 'float',
        'impressions'             => 'integer',
        'clicks'                  => 'integer',
        'reach'                   => 'integer',
        'frequency'               => 'float',
        'conversions'             => 'integer',
        'conversion_value'        => 'float',
        'search_impression_share' => 'float',
        'search_budget_lost_is'   => 'float',
        'fx_rate_to_usd'          => 'float',
        'is_complete'             => 'boolean',
        'pulled_at'               => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
