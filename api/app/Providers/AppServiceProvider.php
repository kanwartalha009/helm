<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Currency\FxProvider;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // FX provider — single HTTP-backed provider used by FxService for
        // on-demand rate lookups and by the nightly FetchDailyCurrencyRatesJob.
        // URL is config-driven (frankfurter.app by default; ECB reference
        // rates, no API key). USD aggregation is part of Phase 1 per the
        // docs/12 acceptance criteria.
        $this->app->singleton(FxProvider::class, function ($app) {
            return new FxProvider(
                $app->make(HttpClientFactory::class),
                (string) config('sync.fx.provider_url', 'https://api.frankfurter.app'),
            );
        });
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
