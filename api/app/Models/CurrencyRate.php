<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property \Illuminate\Support\Carbon $date
 * @property string $base_currency
 * @property string $target_currency
 * @property float  $rate
 */
class CurrencyRate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'date', 'base_currency', 'target_currency', 'rate', 'created_at',
    ];

    protected $casts = [
        'date'       => 'date',
        'rate'       => 'decimal:6',
        'created_at' => 'datetime',
    ];
}
