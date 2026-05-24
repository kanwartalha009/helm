<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platforms\PlatformRegistry;
use App\Services\PlatformCredentialService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the PlatformRegistry singleton from config/platforms.php and
 * registers PlatformCredentialService so adapters can constructor-inject it.
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformRegistry::class, function (Container $app) {
            /** @var array<string, class-string<\App\Platforms\Contracts\PlatformAdapter>> $map */
            $map = (array) config('platforms', []);
            return new PlatformRegistry($app, $map);
        });

        $this->app->singleton(PlatformCredentialService::class);
    }

    public function boot(): void
    {
        //
    }

    /** @return string[] */
    public function provides(): array
    {
        return [
            PlatformRegistry::class,
            PlatformCredentialService::class,
        ];
    }
}
