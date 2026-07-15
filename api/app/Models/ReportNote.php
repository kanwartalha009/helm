<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M4 (monthly-report-v2-mom.md §M4) — S19 "Novedades". `brand_id` null = the
 * agency-wide default note for that month (Settings -> Novedades); a set
 * `brand_id` = that brand's own edited copy for that month. See the
 * migration's docblock for the full override-layering contract.
 */
class ReportNote extends Model
{
    protected $table = 'report_notes';

    protected $guarded = [];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
