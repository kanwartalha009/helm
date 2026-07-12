<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A creative-planning board (Ads Library Phase 4). Holds saved ads (items). */
class AdBoard extends Model
{
    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(AdBoardItem::class, 'board_id')->orderBy('position')->orderBy('id');
    }
}
