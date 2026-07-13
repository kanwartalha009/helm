<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One brand-day of session traffic: did we pull it, and did it RECONCILE?
 *
 * The row-level table (session_traffic_daily) holds the breakdown. This holds the verdict. They
 * are different questions, and inferring the second from the first is what made a quiet day
 * indistinguishable from a failed one.
 */
class SessionTrafficDay extends Model
{
    public $timestamps = false;   // mirrors session_traffic_daily — pulled_at is the only clock

    protected $fillable = [
        'brand_id', 'workspace_id', 'date', 'is_complete',
        'store_total', 'paged_total', 'rows_written', 'pulled_at',
    ];

    protected $casts = [
        'date'        => 'date',
        'is_complete' => 'bool',
        'store_total' => 'int',
        'paged_total' => 'int',
        'rows_written' => 'int',
        'pulled_at'   => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * How many sessions the landing-page breakdown is SHORT by. Null when we never established
     * Shopify's own total, which is a different failure from "we established it and missed rows".
     */
    public function shortfall(): ?int
    {
        return $this->store_total === null ? null : $this->store_total - $this->paged_total;
    }
}
