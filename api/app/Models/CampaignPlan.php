<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated seasonal campaign plan (GO-4.3). `blocks` are RULE-ASSEMBLED numbers, each
 * carrying its own basis (Verified / Proxy / Modeled / Source). `narrative` is LLM prose,
 * stored separately so prose can never be mistaken for a figure. See the migration.
 */
class CampaignPlan extends Model
{
    protected $table = 'campaign_plans';

    protected $guarded = [];

    protected $casts = [
        'blocks' => 'array',
        'year'   => 'integer',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
