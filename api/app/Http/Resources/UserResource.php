<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Models\WorkspaceSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'name'                   => $this->name,
            'email'                  => $this->email,
            'role'                   => $this->role,
            'status'                 => $this->status,
            'displayInitials'        => $this->display_initials ?: mb_substr($this->name ?: '?', 0, 1),
            'timezone'               => $this->timezone ?? 'UTC',
            'mfaEnabled'             => (bool) $this->mfa_secret,
            'accessibleBrandIds'     => $this->accessibleBrandIds(),
            'notificationPrefs'      => array_merge(
                User::DEFAULT_NOTIFICATION_PREFS,
                (array) ($this->notification_prefs ?? [])
            ),
            'avatarUrl'              => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'onboardingCompletedAt'  => $this->onboarding_completed_at?->toIso8601String(),
            'onboardingComplete'     => (bool) $this->onboarding_completed_at,
            // Has the founding admin already named the workspace? Lets the
            // onboarding wizard skip the workspace step for invited users.
            'workspaceConfigured'    => self::workspaceConfigured(),
            'lastLoginAt'            => $this->last_login_at?->toIso8601String(),
        ];
    }

    /** Memoised per request so the team list doesn't N+1 this tiny lookup. */
    private static ?bool $workspaceConfigured = null;

    private static function workspaceConfigured(): bool
    {
        return self::$workspaceConfigured ??= WorkspaceSetting::query()
            ->where('key', 'workspace_name')
            ->exists();
    }
}
