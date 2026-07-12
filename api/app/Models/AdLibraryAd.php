<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One archived EU ad (GLOBAL corpus — public data, keyed on ad_archive_id).
 * TEXT + metadata only (no media files, per Ad Library ToS). `permalink` is the
 * token-free public URL; the token-bearing ad_snapshot_url is NEVER stored.
 *
 * Cross-tenant note (D-022): this table is global, but any per-tenant ranking or
 * benchmark that reads it must scope through the tracked pages/searches — the
 * corpus is shared, performance conclusions are not pooled across agencies.
 */
class AdLibraryAd extends Model
{
    protected $guarded = [];

    protected $casts = [
        'countries'         => 'array',
        'creative_bodies'   => 'array',
        'link_titles'       => 'array',
        'link_captions'     => 'array',
        'link_descriptions' => 'array',
        'languages'         => 'array',
        'platforms'         => 'array',
        'reach_breakdown'   => 'array',
        'target_ages'       => 'array',
        'target_locations'  => 'array',
        'beneficiary_payers' => 'array',
        'raw'               => 'array',
        'eu_total_reach'    => 'integer',
        'longevity_days'    => 'integer',
        'signal_score'      => 'float',
        'is_active'         => 'boolean',
        'delivery_start'    => 'date',
        'delivery_stop'     => 'date',
        'ad_created_at'     => 'datetime',
        'first_seen_at'     => 'datetime',
        'last_seen_at'      => 'datetime',
    ];
}
