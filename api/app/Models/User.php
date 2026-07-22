<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role     master_admin | manager | team_member | brand_user
 * @property string $status   active | invited | disabled
 * @property ?string $mfa_secret
 * @property ?\Illuminate\Support\Carbon $last_login_at
 * @property ?string $last_login_ip
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'status',
        'mfa_secret', 'mfa_recovery_codes', 'last_login_at', 'last_login_ip',
        'display_initials', 'timezone', 'notification_prefs',
        'onboarding_completed_at', 'avatar_path',
    ];

    protected $hidden = [
        'password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes',
    ];

    protected $casts = [
        'mfa_secret'              => 'encrypted',
        // An array of BCRYPT HASHES of the recovery codes, encrypted at rest.
        'mfa_recovery_codes'      => 'encrypted:array',
        'last_login_at'           => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'password'                => 'hashed',
        'notification_prefs'      => 'array',
    ];

    /** Default notification prefs when the column is null. */
    public const DEFAULT_NOTIFICATION_PREFS = [
        'daily_sync_digest'  => true,
        'connection_errored' => true,
        'ticket_assigned'    => false,
        'weekly_summary'     => false,
    ];

    public function platformCredentialsCreated(): HasMany
    {
        return $this->hasMany(PlatformCredential::class, 'created_by_user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    /** Browsers this user has trusted so 2FA is skipped there for a fixed window. */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(MfaTrustedDevice::class);
    }

    /**
     * Many-to-many to brands, scoped by the brand_user_access pivot. Used by
     * the UserResource + EnsureUserCanAccessBrand middleware to enforce that
     * team_member / brand_user roles only see brands they've been granted.
     */
    public function accessibleBrands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_user_access')
            ->withTimestamps();
    }

    /**
     * Returns the list of brand IDs this user can access.
     *
     * master_admin and manager bypass the Brand global scope entirely, so for
     * those two roles the check downstream (EnsureUserCanAccessBrand) doesn't
     * use this list. We still return the real pivot set so UserResource can
     * surface it accurately on the team page.
     *
     * Defensive: if the pivot table doesn't exist (operator skipped running
     * the brand_user_access migration), return [] instead of taking the whole
     * request down. Middleware that consults this falls back to the global
     * Brand scope, which for admins/managers means "see everything".
     *
     * @return int[]
     */
    public function accessibleBrandIds(): array
    {
        try {
            // Bypass the Brand 'access' global scope here. That scope calls THIS
            // method to build its whereIn, so querying accessibleBrands() with the
            // scope still attached recurses infinitely (caught by RbacAccessTest;
            // would 500 every team_member / brand_user request in prod).
            return $this->accessibleBrands()
                ->withoutGlobalScope('access')
                ->pluck('brands.id')
                ->all();
        } catch (\Illuminate\Database\QueryException $e) {
            // 42P01 = undefined_table (Postgres). Anything else, surface.
            if (in_array($e->getCode(), ['42P01', '42S02'], true)) {
                \Illuminate\Support\Facades\Log::warning(
                    'brand_user_access table missing — run `php artisan migrate`. '
                    . 'Falling back to empty access list.'
                );
                return [];
            }
            throw $e;
        }
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
