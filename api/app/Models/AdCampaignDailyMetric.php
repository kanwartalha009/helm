<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of campaign-level ad performance per (brand, platform, date,
 * campaign) — the grain the Meta + Google ads audit reads to rank campaigns,
 * compute waste, and build the kill-list (feature spec slice 2.2 / 2.4).
 * Written by ads:backfill-campaigns; read by the AdAudit service.
 */
class AdCampaignDailyMetric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date'             => 'date',
        'spend'            => 'float',
        'impressions'      => 'integer',
        'clicks'           => 'integer',
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
