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

    /**
     * Add / edit report commentary + its To-Do (Kanwar, 2026-07-20 — "team
     * member A adds a comment for May, team B and C can view and edit"). Broader
     * than update(): ANY user with access to the brand can collaborate on the
     * shared, DB-backed section notes (same access test as view()), while
     * brand settings themselves stay master_admin/manager-only. Comments are
     * keyed by brand+month+section, never per-user, so one team's note is
     * always visible and editable by the next.
     */
    public function comment(User $user, Brand $brand): bool
    {
        return $this->view($user, $brand);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $user->role === 'master_admin';
    }
}
