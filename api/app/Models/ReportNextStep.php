<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M4 (monthly-report-v2-mom.md §M4) — one brand's S0 "Next Steps" checklist
 * for one month. `items` is the whole json array [{text, group, status,
 * carried_from}]; see the migration's docblock for why this is one row per
 * (brand, month) rather than one row per item.
 */
class ReportNextStep extends Model
{
    protected $table = 'report_next_steps';

    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
