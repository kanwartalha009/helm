<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserCanAccessBrand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:     __DIR__ . '/../routes/web.php',
        api:     __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health:  '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'         => EnsureRole::class,
            'access.brand' => EnsureUserCanAccessBrand::class,
        ]);

        // Pure bearer-token auth. The SPA stores the Sanctum token in
        // localStorage and sends it as `Authorization: Bearer <token>`.
        // We intentionally don't call $middleware->statefulApi() — that
        // wraps /api/* in the web stack and enforces CSRF, which causes
        // "CSRF token mismatch" 419s on every request from the SPA.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
