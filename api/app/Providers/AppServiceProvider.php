<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // FxProvider binding removed in Phase 1 (currency conversion is no
        // longer part of sync). Restore the singleton if/when USD
        // aggregation comes back.
    }

    public function boot(): void
    {
        // Return single resources as flat objects, not `{ data: { ... } }`.
        // The SPA expects the user payload directly — wrapping was making
        // AuthGate see `onboardingComplete` as undefined on every response
        // from a resource endpoint, which loops onboarding back on itself.
        JsonResource::withoutWrapping();

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
