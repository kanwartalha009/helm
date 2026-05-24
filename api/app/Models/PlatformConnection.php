<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $brand_id
 * @property string $platform       shopify | meta | google | tiktok
 * @property string $external_id    shop domain, ad account id, etc.
 * @property ?string $display_name
 * @property array  $credentials    encrypted JSONB
 * @property ?array $metadata
 * @property string $status         active | paused | errored
 * @property ?\Illuminate\Support\Carbon $last_sync_at
 * @property ?string $last_error
 */
class PlatformConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'platform', 'external_id', 'display_name',
        'credentials', 'metadata', 'status', 'last_sync_at', 'last_error',
    ];

    protected $casts = [
        'credentials'  => 'encrypted:array',
        'metadata'     => 'array',
        'last_sync_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
