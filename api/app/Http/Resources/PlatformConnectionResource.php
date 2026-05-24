<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlatformConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformConnection
 */
class PlatformConnectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'brandId'     => $this->brand_id,
            'platform'    => $this->platform,
            'externalId'  => $this->external_id,
            'displayName' => $this->display_name,
            'status'      => $this->status,
            'lastSyncAt'  => $this->last_sync_at?->toIso8601String(),
            'lastError'   => $this->last_error,
            'metadata'    => $this->metadata,
            // credentials NEVER serialized
        ];
    }
}
