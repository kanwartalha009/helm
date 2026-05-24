<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * @property int    $id
 * @property string $name
 * @property string $slug
 * @property string $timezone     IANA tz — daily_metrics.date is in this tz
 * @property string $base_currency
 * @property ?string $group_tag
 * @property string $status       active | paused | archived
 */
class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'timezone', 'base_currency', 'group_tag', 'status',
        'shopify_app',
    ];

    protected $casts = [
        'timezone'      => 'string',
        'base_currency' => 'string',
        // Per-brand Shopify Partner app credentials. Encrypted at the
        // application layer — never logged, never serialized via BrandResource.
        'shopify_app'   => 'encrypted:array',
    ];

    // Belt-and-suspenders: never accidentally serialize the encrypted blob.
    protected $hidden = ['shopify_app'];

    /**
     * Route model binding uses slug, not id. The SPA URLs are /brands/{slug}
     * and the API mirrors that.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function connections(): HasMany
    {
        return $this->hasMany(PlatformConnection::class);
    }

    /**
     * True iff the brand has its own Partner-app credentials AND we can still
     * decrypt them with the current APP_KEY. If APP_KEY rotated, the
     * `shopify_app` cast throws DecryptException — we swallow it here so the
     * brands list keeps working, and the UI will simply offer to re-enter
     * the credentials.
     */
    public function hasShopifyApp(): bool
    {
        try {
            $app = $this->shopify_app;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return false;
        }
        return is_array($app)
            && ! empty($app['client_id'])
            && ! empty($app['client_secret']);
    }

    /**
     * True iff the brand has Shopify Partner-app credentials stored but they
     * can't be decrypted with the current APP_KEY. Drives an "unreadable —
     * re-enter required" warning in the UI without breaking the list.
     */
    public function shopifyAppCorrupted(): bool
    {
        if (empty($this->attributes['shopify_app'])) {
            return false;
        }
        try {
            $this->shopify_app;
            return false;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return true;
        }
    }

    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(DailyMetric::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    /**
     * Global access scope — spec §13.2 / RBAC.
     * master_admin and manager bypass entirely; everyone else is filtered to
     * their accessibleBrandIds(). The dashboard never has to call this manually.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('access', function (Builder $query): void {
            $user = Auth::user();
            if (! $user) {
                return;
            }
            if (in_array($user->role, ['master_admin', 'manager'], true)) {
                return;
            }
            $query->whereIn('id', $user->accessibleBrandIds());
        });
    }
}
