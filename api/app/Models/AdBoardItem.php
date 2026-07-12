<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One saved ad on a board — internal winner or market ad, with tags. */
class AdBoardItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tags'     => 'array',
        'position' => 'integer',
    ];
}
