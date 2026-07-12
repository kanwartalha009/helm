<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Models\WorkspaceSetting;
use App\Services\Llm\LlmManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
            // Spec §08: MFA is mandatory for master_admin. AuthGate forces
            // enrollment when this is true (post-onboarding). Gated by a config
            // kill-switch (HELM_REQUIRE_ADMIN_MFA) so enforcement can be released
            // if enrollment ever can't be completed — anti-lockout.
            'mfaRequired'            => (bool) config('helm.require_admin_mfa', true)
                && $this->role === 'master_admin'
                && ! $this->mfa_secret,
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
            // White-label + capability signals for the SPA shell — workspace-level,
            // so the same for every user and served here (not the master-admin-only
            // settings endpoint) so all roles can read them:
            //  - agencyName drives the shell wordmark (falls back to the build-time
            //    VITE_APP_NAME on the client when null).
            //  - llmEnabled hides the "Ask the data" AI surfaces when no LLM key is
            //    configured, so a workspace without the AI add-on sees no dead tabs.
            'agencyName'             => self::agencyName(),
            'llmEnabled'             => self::llmEnabled(),
        ];
    }

    /** Memoised per request so the team list doesn't N+1 these tiny workspace lookups. */
    private static ?bool $workspaceConfigured = null;
    private static ?string $agencyName = null;
    private static ?bool $llmEnabled = null;

    private static function workspaceConfigured(): bool
    {
        return self::$workspaceConfigured ??= WorkspaceSetting::query()
            ->where('key', 'workspace_name')
            ->exists();
    }

    private static function agencyName(): ?string
    {
        if (self::$agencyName !== null) {
            return self::$agencyName ?: null;
        }
        $branding = (array) WorkspaceSetting::getValue('report_branding', []);
        $name = trim((string) ($branding['agency_name'] ?? ''));

        return (self::$agencyName = $name) ?: null;
    }

    private static function llmEnabled(): bool
    {
        if (self::$llmEnabled !== null) {
            return self::$llmEnabled;
        }
        try {
            return self::$llmEnabled = app(LlmManager::class)->enabled();
        } catch (Throwable) {
            return self::$llmEnabled = false;
        }
    }
}
