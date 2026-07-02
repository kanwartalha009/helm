<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of a Shopify product (stock + variants) per (brand, handle). Handle is
 * lower-cased to match ad landing-URL handles. Written by shopify:sync-catalog;
 * read by the Inventory Intelligence report. See the migration for the contract.
 */
class ProductCatalog extends Model
{
    protected $table = 'product_catalog';

    protected $guarded = [];

    protected $casts = [
        'tags'            => 'array',
        'variants'        => 'array',
        'variant_count'   => 'integer',
        'total_inventory' => 'integer',
        'captured_at'     => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
