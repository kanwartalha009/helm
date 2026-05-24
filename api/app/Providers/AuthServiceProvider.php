<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Brand;
use App\Models\PlatformCredential;
use App\Models\User;
use App\Policies\BrandPolicy;
use App\Policies\PlatformCredentialPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        Brand::class              => BrandPolicy::class,
        User::class               => UserPolicy::class,
        PlatformCredential::class => PlatformCredentialPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
