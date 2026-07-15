<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GO-5.1 — a single generated-then-reviewed creative asset. See the migration
 * for the lifecycle and provenance contract.
 */
class CreativeDraft extends Model
{
    protected $guarded = [];

    protected $casts = [
        'content' => 'array',
    ];

    /** The lifecycle states, in order. */
    public const STATUSES = ['draft', 'approved', 'exported', 'launched'];

    public const KINDS = ['copy', 'hook', 'ugc_script'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
