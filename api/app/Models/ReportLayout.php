<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report_type's saved layout (M1 + REV2 R2). `brand_id === null` marks the
 * agency-wide DEFAULT layout; `brand_id` set marks a brand's OVERRIDE. See
 * App\Services\ReportLayouts for resolution against config/momreport.php's
 * code-default catalog. `sections` is the ordered
 * [{key, enabled, position, view: 'chart'|'table'|'both', settings?}] array.
 */
class ReportLayout extends Model
{
    protected $table = 'report_layouts';

    protected $guarded = [];

    protected $casts = [
        'sections' => 'array',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
