<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WorkspaceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Settings → General tab. Master_admin only.
 *
 * GET    /api/workspace-settings       — returns all settings as a flat map
 * PATCH  /api/workspace-settings       — partial update, writes audit log per changed key
 */
class WorkspaceSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $this->assertTablesExist();
        return response()->json(WorkspaceSetting::allAsMap());
    }

    public function update(Request $request): JsonResponse
    {
        $this->assertTablesExist();

        $data = $request->validate([
            'workspace_name'   => ['sometimes', 'string', 'max:120'],
            'primary_currency' => ['sometimes', Rule::in(['USD', 'EUR', 'GBP'])],
            // White-label report theme — the SPA sends the full object on save.
            'report_branding'             => ['sometimes', 'array'],
            'report_branding.agency_name' => ['sometimes', 'string', 'max:120'],
            'report_branding.accent'      => ['sometimes', 'string', 'max:32'],
            'report_branding.footer_text' => ['sometimes', 'string', 'max:200'],
            // LLM provider choice (D-016) — which driver serves narrative + chat.
            // Stored here so the agency picks in the UI; env is the fallback.
            'llm_provider' => ['sometimes', Rule::in(['anthropic', 'openai'])],
        ]);

        // Filter out empty/null so we never overwrite real values with blanks
        // from a half-filled form.
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        try {
            $changes = [];
            DB::transaction(function () use ($data, $request, &$changes): void {
                foreach ($data as $key => $value) {
                    $previous = WorkspaceSetting::getValue($key);
                    if ($previous !== $value) {
                        WorkspaceSetting::setValue($key, $value);
                        $changes[$key] = ['from' => $previous, 'to' => $value];
                    }
                }

                if (! empty($changes) && Schema::hasTable('audit_logs')) {
                    AuditLog::create([
                        'actor_user_id' => Auth::id(),
                        'action'        => 'workspace_setting.updated',
                        'target_type'   => 'workspace_setting',
                        'target_id'     => null,
                        'metadata'      => $changes,
                        'ip'            => $request->ip(),
                        'user_agent'    => $request->userAgent(),
                    ]);
                }
            });

            return response()->json([
                'settings' => WorkspaceSetting::allAsMap(),
                'changed'  => array_keys($changes),
            ]);
        } catch (Throwable $e) {
            Log::error('workspace_settings.update failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);

            // In production keep the response generic; in dev surface the
            // actual exception so the SPA toast tells you exactly what broke.
            return response()->json([
                'message' => app()->isLocal()
                    ? 'Save failed: ' . $e->getMessage()
                    : 'Save failed.',
            ], 500);
        }
    }

    /**
     * Fast-fail with a clear hint if either of the tables this controller
     * touches doesn't exist (i.e. a missing migration).
     */
    private function assertTablesExist(): void
    {
        $missing = [];
        if (! Schema::hasTable('workspace_settings')) {
            $missing[] = 'workspace_settings';
        }
        if (count($missing) > 0) {
            abort(500, 'Missing tables: ' . implode(', ', $missing)
                . '. Run: php artisan migrate');
        }
    }
}
