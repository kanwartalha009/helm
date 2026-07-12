<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A creative brief assembled from a board (Ads Library Phase 4). */
class AdBrief extends Model
{
    protected $guarded = [];

    protected $casts = [
        'blocks' => 'array',
    ];
}
