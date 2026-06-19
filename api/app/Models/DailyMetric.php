<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The polymorphic daily metrics table. One row per (brand, platform, date).
 * Never split into per-platform tables.
 *
 * @property int    $id
 * @property int    $brand_id
 * @property string $platform
 * @property \Illuminate\Support\Carbon $date
 * @property ?float $revenue
 * @property ?float $revenue_net
 * @property ?int   $orders
 * @property ?float $refunds_amount
 * @property ?int   $refunded_orders
 * @property ?float $spend
 * @property ?int   $impressions
 * @property ?int   $clicks
 * @property ?int   $conversions
 * @property ?float $conversion_value
 * @property string $currency
 * @property float  $fx_rate_to_usd
 * @property ?array $metadata
 * @property bool   $is_complete
 * @property \Illuminate\Support\Carbon $pulled_at
 */
class DailyMetric extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'brand_id', 'platform', 'date',
        'revenue', 'revenue_net', 'net_sales', 'total_sales', 'orders', 'refunds_amount', 'refunded_orders',
        'spend', 'impressions', 'clicks', 'conversions', 'conversion_value',
        'currency', 'fx_rate_to_usd', 'metadata', 'is_complete', 'pulled_at',
    ];

    protected $casts = [
        'date'             => 'date',
        'revenue'          => 'decimal:2',
        'revenue_net'      => 'decimal:2',
        'net_sales'        => 'decimal:2',
        'total_sales'      => 'decimal:2',
        'orders'           => 'integer',
        'refunds_amount'   => 'decimal:2',
        'refunded_orders'  => 'integer',
        'spend'            => 'decimal:2',
        'impressions'      => 'integer',
        'clicks'           => 'integer',
        'conversions'      => 'integer',
        'conversion_value' => 'decimal:2',
        'fx_rate_to_usd'   => 'decimal:6',
        'metadata'         => 'array',
        'is_complete'      => 'boolean',
        'pulled_at'        => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
