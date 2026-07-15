<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GO-4.4 — one brand's confirmed (or draft) style profile. See the migration
 * for the column contract and the confirm-gate rationale.
 *
 * `isConfirmed()` is the single check GO-5 will call before grounding any
 * generation in this style — an unconfirmed profile is suggestions only.
 */
class BrandStyle extends Model
{
    protected $guarded = [];

    protected $casts = [
        'palette'      => 'array',
        'tone_words'   => 'array',
        'do_dont'      => 'array',
        'refs'         => 'array',
        'confirmed_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
