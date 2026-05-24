<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runs on every route that has a {brand} parameter. Belt-and-suspenders
 * to the global scope on the Brand model — see spec §13.2.
 *
 * master_admin and manager always pass. Everyone else must have the brand
 * in their accessibleBrandIds().
 */
class EnsureUserCanAccessBrand
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            throw new AccessDeniedHttpException('Not authenticated.');
        }

        $brandParam = $request->route('brand');
        if ($brandParam === null) {
            // No brand on this route — nothing to enforce.
            return $next($request);
        }

        // Route model binding may have hydrated a Brand model; otherwise it's a scalar.
        // property_exists() returns false for Eloquent attributes (they're accessed
        // via __get), so check the model instance explicitly and use getKey().
        $brandId = $brandParam instanceof Model
            ? (int) $brandParam->getKey()
            : (int) $brandParam;

        if (in_array($user->role, ['master_admin', 'manager'], true)) {
            return $next($request);
        }

        if (! in_array($brandId, $user->accessibleBrandIds(), true)) {
            throw new NotFoundHttpException('Brand not found.');
        }

        return $next($request);
    }
}
