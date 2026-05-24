<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $actor = $this->actor;

        // The frontend AuditLogEntry type wants a flat target string and an
        // actor pick (id+name). Compose a sensible target from target_type
        // and any metadata.label / metadata.target the application stamped on
        // the row when it was written.
        $metadata = (array) ($this->metadata ?? []);
        $target = (string) (
            $metadata['target']
            ?? $metadata['label']
            ?? trim((($this->target_type ?? '') . ' #' . ($this->target_id ?? '')), ' #')
        );

        return [
            'id'        => $this->id,
            'actor'     => $actor
                ? ['id' => $actor->id, 'name' => $actor->name]
                : ['id' => 0, 'name' => 'System'],
            'action'    => $this->action,
            'target'    => $target !== '' ? $target : '—',
            'ip'        => $this->ip ?? '',
            'createdAt' => $this->created_at?->toIso8601String() ?? '',
        ];
    }
}
