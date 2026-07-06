<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Brand
 */
class BrandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'slug'         => $this->slug,
            'timezone'     => $this->timezone,
            'baseCurrency' => $this->base_currency,
            'groupTag'     => $this->group_tag,
            'status'       => $this->status,
            'initials'     => $this->computeInitials(),
            // group_tag stands in for region until we add a real column.
            'region'       => $this->group_tag ?? '—',
            // Shop domain comes from the active Shopify connection. If the
            // brand isn't connected yet this is null and the UI renders an
            // empty state pointing the user to the connect flow.
            'shopDomain'   => $this->resolveShopDomain(),
            // Boolean — true if the brand has its own Partner app credentials
            // stored and they can still be decrypted with the current APP_KEY.
            // Reads through Brand::hasShopifyApp() so a DecryptException (MAC
            // invalid after key rotation) degrades to `false` instead of
            // 500ing the whole index endpoint. The values themselves are
            // never serialized.
            'hasShopifyApp' => $this->resource->hasShopifyApp(),
            // True when ciphertext exists in the column but can't be
            // decrypted — usually APP_KEY drift. UI surfaces this as
            // "credentials unreadable — re-enter required".
            'shopifyAppCorrupted' => $this->resource->shopifyAppCorrupted(),

            // List-view extras — present only when the relations are eager-loaded
            // (BrandController::index), so store/update stay lean and there's no N+1.
            'platforms'       => $this->when($this->relationLoaded('connections'), fn () => $this->activePlatforms()),
            'connectionCount' => $this->when($this->relationLoaded('connections'), fn () => count($this->activePlatforms())),
            'lastSyncAt'      => $this->when($this->relationLoaded('connections'), fn () => $this->latestSyncAt()),
            'assignedUsers'   => $this->when($this->relationLoaded('users'), fn () => $this->assignedTeam()),
        ];
    }

    /** @return array<int, string> active platform names, e.g. ['shopify','meta']. */
    private function activePlatforms(): array
    {
        return $this->connections
            ->filter(fn ($c) => $c->status === 'active')
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();
    }

    /** ISO-8601 of the freshest connection sync across the brand, or null if never synced. */
    private function latestSyncAt(): ?string
    {
        $latest = $this->connections->pluck('last_sync_at')->filter()->max();

        return $latest ? \Carbon\CarbonImmutable::parse($latest)->toIso8601String() : null;
    }

    /** @return array<int, array{id:int,name:string,initials:string}> assigned team. */
    private function assignedTeam(): array
    {
        return $this->users->map(fn ($u) => [
            'id'       => $u->id,
            'name'     => $u->name,
            'initials' => $this->initialsFor((string) $u->name),
        ])->values()->all();
    }

    private function initialsFor(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }

    private function resolveShopDomain(): ?string
    {
        // Avoid an extra query if the relation has already been eager-loaded
        // — BrandController::show() does that.
        $connections = $this->relationLoaded('connections')
            ? $this->connections
            : $this->connections()->where('platform', 'shopify')->get();

        $shopify = $connections->first(fn ($c) => $c->platform === 'shopify' && $c->status === 'active');
        return $shopify?->external_id;
    }

    /**
     * Two-letter avatar initials derived from the brand name.
     * "Nova Threads" → "NT", "Meller" → "ME".
     */
    private function computeInitials(): string
    {
        $name = trim((string) $this->name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name) ?: [$name];
        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }
        return mb_strtoupper(mb_substr($name, 0, 2));
    }
}
