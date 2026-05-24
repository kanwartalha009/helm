# Platform adapter contract

This is the core technical contract of the system. Every platform — current and future — implements one PHP interface. The rest of the codebase depends only on this interface, never on platform-specific classes.

## The interface

```php
namespace App\Platforms\Contracts;

use App\Models\Brand;
use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;

interface PlatformAdapter
{
    public function key(): string;        // 'shopify', 'meta', 'google', 'tiktok'
    public function label(): string;      // 'Shopify', 'Meta Ads', etc.

    // OAuth / connection setup
    public function authUrl(Brand $brand): string;
    public function handleCallback(Brand $brand, array $payload): PlatformConnection;

    // Account discovery (Meta/Google/TikTok return many accounts under one token)
    public function listAvailableAccounts(PlatformConnection $conn): array;
    public function attachAccount(PlatformConnection $conn, string $externalId): void;

    // Sync
    public function fetchDay(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot;
    public function healthCheck(PlatformConnection $conn): bool;
}
```

## The MetricSnapshot DTO

```php
namespace App\Platforms\Contracts;

final class MetricSnapshot
{
    public function __construct(
        public readonly int $brandId,
        public readonly string $platform,
        public readonly CarbonImmutable $date,
        public readonly ?float $revenue = null,
        public readonly ?float $revenueNet = null,
        public readonly ?int $orders = null,
        public readonly ?float $spend = null,
        public readonly ?int $impressions = null,
        public readonly ?int $clicks = null,
        public readonly ?int $conversions = null,
        public readonly ?float $conversionValue = null,
        public readonly string $currency,
        public readonly ?array $metadata = null,
    ) {}
}
```

Shopify fills the revenue / orders / refund fields and leaves spend null. Ad platforms fill spend / impressions / clicks / conversions / conversionValue and leave revenue null. Both populate `currency` and `metadata` (e.g. attribution window for Meta).

## The registry

A simple class registered as a singleton in the service container. Adapters self-register via `config/platforms.php`.

```php
// config/platforms.php
return [
    'shopify' => \App\Platforms\Shopify\ShopifyAdapter::class,
    'meta'    => \App\Platforms\Meta\MetaAdapter::class,
    'google'  => \App\Platforms\Google\GoogleAdsAdapter::class,
    'tiktok'  => \App\Platforms\TikTok\TikTokAdapter::class,
];

// App\Platforms\PlatformRegistry
class PlatformRegistry {
    public function for(string $key): PlatformAdapter { /* resolve from container */ }
    public function all(): array { /* return every registered adapter */ }
}
```

## Adding a future platform

To add Pinterest Ads (example):

1. Create `app/Platforms/Pinterest/PinterestAdapter.php` implementing `PlatformAdapter`.
2. Create `app/Platforms/Pinterest/PinterestClient.php` for HTTP calls, retry, rate limit.
3. Register the adapter in `config/platforms.php`.

The UI automatically detects it. The job automatically dispatches for it. No schema changes.

## Rules for adapter implementations

- **No direct Guzzle calls outside `Platforms/`.** Every outbound HTTP request lives behind a platform `Client` class.
- **Throw, don't return null.** A failed fetch must throw so `SyncBrandDayJob` marks the connection errored and `is_complete` stays false.
- **Currency is native.** The adapter returns the platform's native currency. Conversion happens at write time using the daily `currency_rates` snapshot, not in the adapter.
- **Date is in brand timezone.** The adapter is given a `CarbonImmutable` already resolved to the brand's tz. It must not call `date()` or `now()` directly.
- **Metadata is JSONB.** Anything platform-specific (Meta attribution window, Google customer ID hierarchy) goes into `metadata`, not new columns.
