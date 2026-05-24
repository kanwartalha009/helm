<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit log read API. The table itself is append-only — writes happen from
 * the rest of the application (auth, credential rotation, etc.) — so this
 * controller only ships an `index` method for the Audit log page.
 *
 * Gated to master_admin|manager via the role middleware on the route.
 */
class AuditLogController extends Controller
{
    /**
     * GET /api/audit-logs?per_page=50&cursor=<opaque>
     *
     * Cursor pagination on created_at. Cursor + per_page are optional;
     * default returns the 50 most recent. Response includes nextCursor so
     * the SPA can ask for older pages without losing entries.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(200, max(10, (int) $request->query('per_page', 50)));

        $logs = AuditLog::query()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return response()->json([
            'data' => AuditLogResource::collection($logs->items())->resolve(),
            'nextCursor' => $logs->nextCursor()?->encode(),
            'prevCursor' => $logs->previousCursor()?->encode(),
            'hasMore'    => $logs->hasMorePages(),
        ]);
    }

    /**
     * GET /api/audit-logs/export.csv
     *
     * Streams the full audit log as CSV. Uses a streamed response with
     * chunked DB reads so the memory footprint stays flat regardless of
     * table size.
     */
    public function export(): StreamedResponse
    {
        $filename = 'helm-audit-log-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['When', 'Actor email', 'Actor name', 'Action', 'Target type', 'Target id', 'IP', 'User agent', 'Metadata']);

            AuditLog::query()
                ->with('actor:id,name,email')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        fputcsv($out, [
                            $r->created_at?->toIso8601String(),
                            $r->actor?->email ?? '',
                            $r->actor?->name ?? 'system',
                            $r->action,
                            $r->target_type ?? '',
                            $r->target_id ?? '',
                            $r->ip ?? '',
                            $r->user_agent ?? '',
                            $r->metadata ? json_encode($r->metadata) : '',
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
