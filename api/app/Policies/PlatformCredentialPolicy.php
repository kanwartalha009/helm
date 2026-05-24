<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformCredential;
use App\Models\User;

/**
 * Every platform credential operation is master_admin-only. Manager and below
 * never see the Platform keys tab.
 */
class PlatformCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'master_admin';
    }

    public function view(User $user, PlatformCredential $credential): bool
    {
        return $user->role === 'master_admin';
    }

    public function reveal(User $user, PlatformCredential $credential): bool
    {
        return $user->role === 'master_admin';
    }

    public function create(User $user): bool
    {
        return $user->role === 'master_admin';
    }

    public function delete(User $user, PlatformCredential $credential): bool
    {
        return $user->role === 'master_admin';
    }
}
