<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SyncLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SyncLog
 */
class SyncLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $brand = $this->brand;

        // Frontend SyncLog type expects an embedded brand pick (id+name+initials)
        // plus durationMs (computed from started_at → completed_at).
        $durationMs = null;
        if ($this->started_at && $this->completed_at) {
            $durationMs = (int) ($this->completed_at->getPreciseTimestamp(3) - $this->started_at->getPreciseTimestamp(3));
        }

        return [
            'id'               => $this->id,
            'brand'            => $brand ? [
                'id'       => $brand->id,
                'name'     => $brand->name,
                'initials' => self::initialsFor((string) $brand->name),
            ] : [
                'id'       => $this->brand_id,
                'name'     => 'Unknown',
                'initials' => '?',
            ],
            'platform'         => $this->platform,
            'targetDate'       => $this->target_date?->toDateString(),
            'status'           => $this->status,
            'durationMs'       => $durationMs,
            'recordsProcessed' => $this->records_processed,
            'errorMessage'     => $this->error_message,
            'completedAt'      => $this->completed_at?->toIso8601String(),
        ];
    }

    private static function initialsFor(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name) ?: [$name];
        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }
        return mb_strtoupper(mb_substr($name, 0, 2));
    }
}
