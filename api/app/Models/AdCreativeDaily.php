<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ad-level (creative) daily metrics — one row per (brand, platform, date, ad).
 * Powers the Ads hub Creatives view (Phase D). Meta today.
 *
 * @property int     $id
 * @property int     $brand_id
 * @property string  $platform
 * @property \Illuminate\Support\Carbon $date
 * @property string  $ad_id
 * @property ?string $ad_name
 * @property ?string $campaign_id
 * @property ?string $thumbnail_url
 * @property float   $spend
 * @property int     $impressions
 * @property int     $clicks
 * @property int     $conversions
 * @property float   $conversion_value
 * @property ?string $currency
 * @property ?float  $fx_rate_to_usd
 * @property bool    $is_complete
 */
class AdCreativeDaily extends Model
{
    use HasFactory;

    protected $table = 'ad_creative_daily';

    protected $fillable = [
        'brand_id', 'platform', 'date', 'ad_id', 'ad_name', 'campaign_id', 'thumbnail_url', 'media_type',
        'spend', 'impressions', 'clicks', 'conversions', 'conversion_value',
        'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at',
    ];

    protected $casts = [
        'date'             => 'date',
        'spend'            => 'decimal:2',
        'impressions'      => 'integer',
        'clicks'           => 'integer',
        'conversions'      => 'integer',
        'conversion_value' => 'decimal:2',
        'fx_rate_to_usd'   => 'decimal:8',
        'is_complete'      => 'boolean',
        'pulled_at'        => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
