<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gate a route to one or more roles.
 *
 *   Route::get(...)->middleware('role:master_admin');
 *   Route::get(...)->middleware('role:master_admin,manager');
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user) {
            throw new AccessDeniedHttpException('Not authenticated.');
        }

        if (! in_array($user->role, $roles, true)) {
            throw new AccessDeniedHttpException(
                'Your role is not permitted to access this resource.'
            );
        }

        return $next($request);
    }
}
