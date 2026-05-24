<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlatformCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformCredential
 *
 * NEVER serializes `value`. The masked_value accessor on the model gives
 * the UI a "abcd••••••••wxyz" preview without leaking the full secret.
 */
class PlatformCredentialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'platform'     => $this->platform,
            'key'          => $this->key,
            'label'        => $this->label,
            'status'       => $this->status,
            'maskedValue'  => $this->masked_value,
            // True if the encrypted column can't be decrypted with the
            // current APP_KEY — UI surfaces a "re-enter required" warning.
            'corrupted'    => $this->resource->isCorrupted(),
            'lastUsedAt'   => $this->last_used_at?->toIso8601String(),
            'expiresAt'    => $this->expires_at?->toIso8601String(),
            'createdAt'    => $this->created_at?->toIso8601String(),
            'createdBy'    => $this->created_by_user_id,
            'metadata'     => $this->metadata,
        ];
    }
}
