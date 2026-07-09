<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of Shopify web-funnel counts per (brand, dimension, segment) — sessions
 * → cart → checkout → purchase, split by country or landing path. Written by the
 * Shopify daily sync + shopify:backfill-funnel; read (summed to the month) by the
 * monthly report's web-funnel sections.
 */
class ShopifyFunnelDaily extends Model
{
    protected $table = 'shopify_funnel_daily';

    protected $guarded = [];

    protected $casts = [
        'date'               => 'date',
        'sessions'           => 'integer',
        'cart_additions'     => 'integer',
        'reached_checkout'   => 'integer',
        'completed_checkout' => 'integer',
        'is_complete'        => 'boolean',
        'pulled_at'          => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
