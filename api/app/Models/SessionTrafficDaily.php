<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sessions by traffic type, per landing entity, per day (Bosco item B).
 *
 * One row = (brand, day, entity, traffic_type). The landing path was already resolved to a
 * product / collection / 'other' at sync time — see the migration for why raw paths are not
 * stored (unbounded cardinality: every checkout mints a unique URL).
 *
 * `is_complete = false` means the day's rows did not reconcile against Shopify's own store
 * total. Read surfaces must render "—" for such a day, never the short number.
 */
class SessionTrafficDaily extends Model
{
    protected $table = 'session_traffic_daily';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'date'        => 'date',
        'sessions'    => 'integer',
        'is_complete' => 'boolean',
        'pulled_at'   => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
