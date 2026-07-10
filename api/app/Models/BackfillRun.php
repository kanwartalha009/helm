<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Operator-triggered dataset backfill (campaigns / creatives / commerce). */
class BackfillRun extends Model
{
    protected $fillable = [
        'brand_id', 'dataset', 'status', 'window_start', 'message',
        'triggered_by_user_id', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'window_start' => 'date',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
