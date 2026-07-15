<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tier row (M1, monthly-report-v2-mom.md §M1). `brand_id === null` marks the
 * agency-wide DEFAULT set; `brand_id` set marks a brand's OVERRIDE set. See
 * App\Services\CountryTiers for resolution. `countries` is a JSON array of ISO-2
 * codes; a country absent from every tier auto-buckets to "Other" at resolve time.
 */
class CountryTier extends Model
{
    protected $table = 'country_tiers';

    protected $guarded = [];

    protected $casts = [
        'countries' => 'array',
        'position'  => 'integer',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
