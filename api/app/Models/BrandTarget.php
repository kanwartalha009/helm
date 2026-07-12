<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand's monthly targets (GO-2.1). `month` is a 'Y-m' string in the BRAND's
 * timezone. Every target is nullable and independent — an unset target is unset, never
 * zero, and its pacing chip simply doesn't render. See the migration for the contract.
 */
class BrandTarget extends Model
{
    protected $table = 'brand_targets';

    protected $guarded = [];

    protected $casts = [
        'revenue_target' => 'float',
        'spend_cap'      => 'float',
        'roas_target'    => 'float',
        'mer_target'     => 'float',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
