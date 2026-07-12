<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One detected anomaly (GO-2.4). Deterministic rules only — see config/anomalies.php.
 * `evidence` always carries the numbers, the rule and the threshold, so a human can
 * re-derive the alert by hand. Dismissal requires a reason (see the migration).
 */
class Anomaly extends Model
{
    protected $table = 'anomalies';

    protected $guarded = [];

    protected $casts = [
        'date'        => 'date',
        'evidence'    => 'array',
        'resolved_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
