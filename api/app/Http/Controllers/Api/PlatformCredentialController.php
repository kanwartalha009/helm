<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RevealCredentialRequest;
use App\Http\Requests\StorePlatformCredentialRequest;
use App\Http\Resources\PlatformCredentialResource;
use App\Models\AuditLog;
use App\Models\PlatformCredential;
use App\Services\PlatformCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Master admin only. EnsureRole middleware enforces this at route level;
 * PlatformCredentialPolicy duplicates the check inside each action.
 *
 * All endpoints serialize via PlatformCredentialResource which NEVER
 * includes the decrypted `value` — only the masked preview.
 *
 * The single exception is POST /api/platform-credentials/{id}/reveal which
 * returns the decrypted value once after the user re-enters their password.
 */
class PlatformCredentialController extends Controller
{
    public function __construct(private readonly PlatformCredentialService $service) {}

    /** GET /api/platform-credentials */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlatformCredential::class);

        $rows = PlatformCredential::query()
            ->orderBy('platform')
            ->orderBy('key')
            ->orderByDesc('created_at')
            ->get();

        return PlatformCredentialResource::collection($rows);
    }

    /** GET /api/platform-credentials/schema */
    public function schema(): JsonResponse
    {
        $this->authorize('viewAny', PlatformCredential::class);

        return response()->json($this->service->schema());
    }

    /** POST /api/platform-credentials */
    public function store(StorePlatformCredentialRequest $request): JsonResponse
    {
        $data = $request->validated();

        $credential = $this->service->set(
            $data['platform'],
            $data['key'],
            $data['value'],
            $data['label'] ?? null,
            $data['metadata'] ?? null,
        );

        return (new PlatformCredentialResource($credential))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/platform-credentials/{credential}/reveal
     * Body: { password: <current user's password> }
     * Returns: { value: <decrypted> } — once. Writes audit entry.
     */
    public function reveal(RevealCredentialRequest $request, PlatformCredential $credential): JsonResponse
    {
        $request->validated();

        AuditLog::create([
            'actor_user_id' => $request->user()?->id,
            'action'        => 'credential.revealed',
            'target_type'   => 'platform_credential',
            'target_id'     => $credential->id,
            'metadata'      => ['platform' => $credential->platform, 'key' => $credential->key],
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return response()->json([
            'value' => $credential->value,
        ]);
    }

    /** DELETE /api/platform-credentials/{credential} */
    public function destroy(PlatformCredential $credential): JsonResponse
    {
        $this->authorize('delete', $credential);

        $this->service->revoke($credential->id);

        return response()->json(null, 204);
    }

    /**
     * POST /api/platform-credentials/{platform}/test
     * Runs a live health check against the adapter using the current credentials.
     * Returns { ok: bool, message: string, testedAt: ISO8601 }.
     */
    public function test(Request $request, string $platform): JsonResponse
    {
        $this->authorize('viewAny', PlatformCredential::class);

        $result = $this->service->test($platform);

        AuditLog::create([
            'actor_user_id' => $request->user()?->id,
            'action'        => 'credential.tested',
            'target_type'   => 'platform_credential',
            'target_id'     => null,
            'metadata'      => ['platform' => $platform, 'ok' => $result['ok']],
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return response()->json([
            'ok'       => $result['ok'],
            'message'  => $result['message'],
            'testedAt' => now()->toIso8601String(),
        ]);
    }
}
