<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return true;   // every authenticated user sees the brand list scoped to access
    }

    public function view(User $user, Brand $brand): bool
    {
        if (in_array($user->role, ['master_admin', 'manager'], true)) {
            return true;
        }
        return in_array($brand->id, $user->accessibleBrandIds(), true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['master_admin', 'manager'], true);
    }

    public function update(User $user, Brand $brand): bool
    {
        return in_array($user->role, ['master_admin', 'manager'], true);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $user->role === 'master_admin';
    }
}
