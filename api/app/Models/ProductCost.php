<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operator-entered, EFFECTIVE-DATED product cost (GO-1.2). The override/fallback for
 * Shopify's `InventoryItem.unitCost`. Resolved by CostResolver — never read directly
 * for margin math. See the migration for why it is row-per-change.
 */
class ProductCost extends Model
{
    protected $table = 'product_costs';

    protected $guarded = [];

    protected $casts = [
        'unit_cost'      => 'float',
        'effective_from' => 'date',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
