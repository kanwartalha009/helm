<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One section's commentary + To-Do block for one brand+month (M2). Carried into
 * shares by value (the share snapshot copies the current text/todo at
 * share-creation time, same pattern as ReportLayouts — see that class's docblock).
 */
class ReportCommentary extends Model
{
    protected $table = 'report_commentaries';

    protected $guarded = [];

    protected $casts = [
        'todo' => 'array',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
