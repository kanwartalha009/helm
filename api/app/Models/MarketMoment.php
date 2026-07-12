<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One shopping moment in one market, in one year (GO-4.1). Dates are COMPUTED per year
 * (soldes move; Mother's Day moves; Black Friday moves) and every row carries its
 * `source`. See the migration for the contract.
 */
class MarketMoment extends Model
{
    protected $table = 'market_moments';

    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on'   => 'date',
        'year'      => 'integer',
    ];
}
