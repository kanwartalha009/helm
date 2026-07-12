<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tracked competitor Facebook page (per-workspace). The nightly adlib:refresh
 * sweeps each page's active EU ads into ad_library_ads. `workspace_id` is the
 * D-022 tenancy seam (nullable, no behavior today).
 */
class AdLibraryPage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_refreshed_at' => 'datetime',
    ];
}
