<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta spend attributed to a Shopify product (by ad landing URL) per (brand,
 * date, product_key). product_key is a product handle, or the reserved
 * __collection / __other buckets. Written by meta:backfill-ad-products; read by
 * the Inventory Intelligence report. See the migration for the full contract.
 */
class AdProductDaily extends Model
{
    protected $table = 'ad_product_daily';

    protected $guarded = [];

    protected $casts = [
        'date'           => 'date',
        'spend'          => 'float',
        'ads_count'      => 'integer',
        'fx_rate_to_usd' => 'float',
        'is_complete'    => 'boolean',
        'pulled_at'      => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
