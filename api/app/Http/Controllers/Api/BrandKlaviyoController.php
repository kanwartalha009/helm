<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\PlatformCredential;
use App\Platforms\Klaviyo\KlaviyoClient;
use App\Services\PlatformCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-brand Klaviyo private key CRUD (GO-1.1). The key is brand-scoped in
 * platform_credentials (platform='klaviyo', key='private_key', brand_id). Route is
 * admin/manager-gated; the key is write-only (never returned — only a connected
 * flag + a masked test result). Storing a key auto-runs the connection test so the
 * operator gets immediate feedback.
 *
 * The private key's scopes (campaigns:read flows:read metrics:read) are set at
 * creation in Klaviyo and are IMMUTABLE — the UI hint says so.
 */
class BrandKlaviyoController extends Controller
{
    public function __construct(
        private readonly PlatformCredentialService $credentials,
        private readonly KlaviyoClient $client,
    ) {}

    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json([
            'connected' => $this->credentials->has('klaviyo', 'private_key', (int) $brand->id),
        ]);
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $this->credentials->set(
            'klaviyo',
            'private_key',
            trim($data['api_key']),
            label: 'Klaviyo private key',
            brandId: (int) $brand->id,
        );

        return response()->json([
            'connected' => true,
            'test'      => $this->client->test((int) $brand->id),
        ]);
    }

    public function test(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json($this->client->test((int) $brand->id));
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $row = PlatformCredential::query()
            ->where('platform', 'klaviyo')
            ->where('key', 'private_key')
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->first();

        if ($row) {
            $this->credentials->revoke($row->id);
        }

        return response()->json(['connected' => false]);
    }
}
