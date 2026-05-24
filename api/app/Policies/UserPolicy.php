<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['master_admin', 'manager'], true);
    }

    public function view(User $user, User $target): bool
    {
        return $user->id === $target->id
            || in_array($user->role, ['master_admin', 'manager'], true);
    }

    public function invite(User $user): bool
    {
        return in_array($user->role, ['master_admin', 'manager'], true);
    }

    public function update(User $user, User $target): bool
    {
        // Managers cannot modify admins.
        if ($user->role === 'manager' && $target->role === 'master_admin') {
            return false;
        }
        return in_array($user->role, ['master_admin', 'manager'], true);
    }

    public function disable(User $user, User $target): bool
    {
        // Only master_admin can disable users. They cannot disable themselves.
        return $user->role === 'master_admin' && $user->id !== $target->id;
    }
}
