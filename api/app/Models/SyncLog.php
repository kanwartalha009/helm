<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property ?int   $brand_id
 * @property string $platform
 * @property \Illuminate\Support\Carbon $target_date
 * @property string $status         queued | running | success | failed
 * @property ?\Illuminate\Support\Carbon $started_at
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?int   $records_processed
 * @property ?string $error_message
 */
class SyncLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'brand_id', 'platform', 'target_date', 'status',
        'started_at', 'completed_at', 'records_processed', 'error_message',
        'created_at',
    ];

    protected $casts = [
        'target_date'  => 'date',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
