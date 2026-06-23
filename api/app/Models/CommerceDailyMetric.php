<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of granular Shopify commerce per (brand, date, dimension_type,
 * dimension_key) — the by-country / by-product / by-category breakdown that
 * powers the Country and Product reports (feature spec slice 2.1). Written by
 * shopify:backfill-commerce; read by the report types.
 */
class CommerceDailyMetric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date'           => 'date',
        'orders'         => 'integer',
        'units'          => 'integer',
        'net_sales'      => 'float',
        'total_sales'    => 'float',
        'refunds_amount' => 'float',
        'fx_rate_to_usd' => 'float',
        'is_complete'    => 'boolean',
        'pulled_at'      => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
