<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A saved keyword/niche search against the EU Ad Library (per-workspace),
 * re-run on its schedule by adlib:refresh. `workspace_id` is the D-022 seam.
 */
class AdLibrarySearch extends Model
{
    protected $guarded = [];

    protected $casts = [
        'countries'  => 'array',
        'filters'    => 'array',
        'last_run_at' => 'datetime',
    ];
}
