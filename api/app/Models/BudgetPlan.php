<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned next-month spend for (brand, month, platform, country) — GO-2.2.
 * A PLAN DOCUMENT only: nothing reads this and pushes it to an ad platform.
 * `country` = '' means "all countries". See the migration for the contract.
 */
class BudgetPlan extends Model
{
    protected $table = 'budget_plans';

    protected $guarded = [];

    protected $casts = [
        'planned_spend' => 'float',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
