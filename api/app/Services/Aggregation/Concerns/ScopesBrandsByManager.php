<?php

declare(strict_types=1);

namespace App\Services\Aggregation\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The "Brand manager" filter, shared by the dashboard performance + audience
 * queries so the two never drift. Spec §08 keeps limited roles hard-scoped via
 * the Brand global access scope; this adds the admin/manager soft default + the
 * filter on top:
 *
 *   manager = 'me' (default) → the signed-in user's assigned brands
 *   manager = 'all'          → every brand (privileged only; limited roles stay
 *                              confined by the global access scope)
 *   manager = 'unassigned'   → brands with NO user/manager assigned at all
 *   manager = <user id>      → that user's assigned brands
 */
trait ScopesBrandsByManager
{
    protected function applyManagerScope(Builder $query, array $params): void
    {
        $me           = Auth::user();
        $isPrivileged = $me !== null && in_array($me->role, ['master_admin', 'manager'], true);

        $manager = (string) ($params['manager'] ?? 'me');
        if ($manager === '') {
            $manager = 'me';
        }
        if ($manager === 'all') {
            return;
        }

        if ($manager === 'unassigned') {
            // Brands nobody is assigned to — no brand_user_access rows at all.
            $query->whereDoesntHave('users');

            return;
        }

        $scopeUserId = $manager === 'me'
            ? $me?->id
            : (ctype_digit($manager) ? (int) $manager : null);
        if ($scopeUserId === null) {
            return; // unknown value → treat as 'all' (limited roles still globally scoped)
        }

        $scopeUser   = ($me && $scopeUserId === $me->id) ? $me : User::find($scopeUserId);
        $assignedIds = $scopeUser?->accessibleBrandIds() ?? [];

        if ($assignedIds === [] && $manager === 'me' && $isPrivileged) {
            return; // soft default, no assignments → show all
        }

        // Specific manager (or a limited user) with no brands → honest empty board.
        $query->whereIn('id', $assignedIds !== [] ? $assignedIds : [0]);
    }
}
