# 08 — RBAC (Phase 1.5)

Ships **before** Phase 2. Retrofitting authorization into a working multi-feature product is multiple times harder than building it in front of one feature.

## Roles

| Role | Sees brands | Capabilities |
|------|-------------|--------------|
| `master_admin` | All | Everything. Manage users, billing (if added), impersonation. Sole role allowed to disable other admins. |
| `manager` | All | View all brands. Manage non-admin users. Assign tickets. View audit log. **Cannot** invite or modify admins. |
| `team_member` | Assigned only | View assigned brands' analytics. Comment on tickets in assigned brands. Cannot manage users. |
| `brand_user` | Own brand only | View own brand's analytics. Raise tickets. Comment on own tickets. Cannot see internal notes. |

## Permission enforcement

### Global scope on `Brand`

Every query on the `Brand` model automatically filters by the authenticated user's accessible brands. Applied in `App\Models\Brand::booted()`.

```php
protected static function booted(): void
{
    static::addGlobalScope('access', function (Builder $q) {
        $user = Auth::user();
        if (! $user) return;
        if (in_array($user->role, ['master_admin', 'manager'])) return;

        $q->whereIn('id', $user->accessibleBrandIds());
    });
}
```

This is the load-bearing defense. The dashboard query never explicitly filters by user — the scope guarantees it.

### Policies for actions

Every controller action calls `$this->authorize()`. Examples:

- `BrandPolicy::view` — role-aware, falls through to `brand_user_access` table for limited roles.
- `BrandPolicy::update` — `master_admin` or `manager` only.
- `UserPolicy::invite` — `master_admin` or `manager` only. Managers cannot invite admins.
- `UserPolicy::disable` — `master_admin` only.
- `TicketPolicy::viewInternalNotes` — not `brand_user`.
- `TicketPolicy::create` — any role can raise a ticket on a brand they can access.

### Middleware

- `EnsureUserCanAccessBrand` — runs on every route with a `{brand}` parameter. Belt-and-suspenders to the global scope.
- `EnsureRole` — used on admin-only routes (user management, audit log).

## Frontend permission helpers

`/api/auth/me` returns `{ user, role, accessibleBrandIds }`. Frontend uses `lib/permissions.ts` to gate UI elements. **Never trust frontend gating alone** — the API enforces the same rules.

```ts
// lib/permissions.ts
export const can = {
  inviteUsers: (u: User) => ['master_admin', 'manager'].includes(u.role),
  manageBrand: (u: User) => ['master_admin', 'manager'].includes(u.role),
  seeInternalNotes: (u: User) => u.role !== 'brand_user',
  accessBrand: (u: User, brandId: number) =>
    ['master_admin', 'manager'].includes(u.role) ||
    u.accessibleBrandIds.includes(brandId),
};
```

## MFA

- TOTP only. No SMS.
- Compatible with Google Authenticator, Authy, 1Password, Bitwarden.
- **Mandatory for `master_admin`** on next login after Phase 1.5 ships.
- **Optional for all other roles.**
- Secret stored encrypted in `users.mfa_secret`.

## Audit log

Append-only `audit_logs` table. Never deleted, never truncated. Records the actor, action, target, IP, and user-agent for every sensitive operation.

| Action | When written |
|--------|--------------|
| `user.invited` | Invitation created |
| `user.accepted` | Invitation accepted |
| `user.role_changed` | Role changed via PATCH /api/users/{id} |
| `user.disabled` | User disabled |
| `brand_access.granted` | Brand access granted to a user |
| `brand_access.revoked` | Brand access revoked |
| `mfa.enabled` | MFA enabled |
| `mfa.disabled` | MFA disabled |
| `impersonation.started` | Admin started impersonating a user |
| `impersonation.ended` | Impersonation session ended |
| `connection.attached` | Platform connection attached to a brand |
| `connection.deleted` | Platform connection removed |

## Impersonation

`master_admin` can impersonate any other user for support purposes. Rules:

- Impersonation start and every impersonated action writes to `audit_logs` with `actor_user_id = admin_id` and a `metadata.impersonating_user_id` field.
- A persistent banner is visible at all times during impersonation.
- The original admin token is preserved server-side so impersonation can be exited without re-login.
- `master_admin` themselves **cannot be impersonated**.
