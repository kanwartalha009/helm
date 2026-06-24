<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inventory snapshot per (brand, captured_on, dimension_type, key) — stock
 * on hand, units sold, and sell-through over a trailing window, by product or
 * collection. Written by shopify:sync-inventory; read by the DeadInventory
 * report support to surface stock that isn't selling.
 */
class InventorySnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'captured_on'       => 'date',
        'ending_units'      => 'integer',
        'units_sold'        => 'integer',
        'sell_through_rate' => 'float',
        'window_days'       => 'integer',
        'pulled_at'         => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
