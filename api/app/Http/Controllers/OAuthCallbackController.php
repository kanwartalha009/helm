<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Platforms\PlatformRegistry;
use App\Platforms\Shopify\ShopifyAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Web-route controller (not /api) for OAuth callbacks. Each platform's
 * adapter knows how to exchange the callback payload for a stored
 * PlatformConnection row.
 *
 * Routes:
 *   GET  /connections/{platform}/callback
 *   GET  /connections/shopify/install
 */
class OAuthCallbackController extends Controller
{
    public function callback(Request $request, PlatformRegistry $registry, string $platform): RedirectResponse
    {
        if ($platform !== 'shopify') {
            // Meta/Google/TikTok don't go through per-brand OAuth in Phase 1.
            return $this->redirectToSpa("/onboarding/connect/{$platform}?ok=0&reason=not_implemented");
        }

        $adapter = $registry->for('shopify');
        if (! $adapter instanceof ShopifyAdapter) {
            return $this->redirectToSpa('/onboarding/connect/shopify?ok=0&reason=adapter_missing');
        }

        $state = (string) $request->query('state', '');
        $brandId = $state !== '' ? $adapter->resolveState($state) : null;
        if ($brandId === null) {
            return $this->redirectToSpa('/onboarding/connect/shopify?ok=0&reason=invalid_state');
        }

        $brand = Brand::withoutGlobalScopes()->find($brandId);
        if (! $brand) {
            return $this->redirectToSpa('/onboarding/connect/shopify?ok=0&reason=brand_not_found');
        }

        try {
            $adapter->handleCallback($brand, $request->query());
        } catch (Throwable $e) {
            Log::error('Shopify OAuth callback failed', [
                'brand_id' => $brand->id,
                'error'    => $e->getMessage(),
            ]);
            return $this->redirectToSpa(
                "/brands/{$brand->slug}?connected=shopify&ok=0&reason="
                . urlencode($e->getMessage())
            );
        }

        return $this->redirectToSpa("/brands/{$brand->slug}?connected=shopify&ok=1");
    }

    public function shopifyInstall(Request $request): RedirectResponse
    {
        return $this->redirectToSpa('/onboarding/connect/shopify?install=1');
    }

    private function redirectToSpa(string $path): RedirectResponse
    {
        $base = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        return redirect($base . $path);
    }
}
