# 06 — Sync

How data flows from external APIs into `daily_metrics`. One job, one schedule, four adapters.

## Schedules

All times UTC. Defined in `app/Console/Kernel.php`.

| Time | Job | What it does |
|------|-----|--------------|
| 13:00 | `RunDailySyncCommand` | For every active brand × active connection, dispatch `SyncBrandDayJob` for yesterday and 6 prior days. Rolling 7-day backfill catches late refunds. |
| 13:30 | `FetchDailyCurrencyRatesJob` | Pull yesterday's FX rates from exchangerate.host (free, no key) for every base currency in use. |
| Every 60 min, 06:00–22:00 | `RunHourlySyncCommand` | For "hot" brands (top-20 by spend), dispatch `SyncBrandDayJob` for today only. |
| 02:00 Sunday | Sync log cleanup | Delete `sync_logs` older than 90 days. Keep `audit_logs` forever; sync_logs are noise after 90 days. |

13:00 UTC is late enough that "yesterday" is closed in every active timezone (Madrid ends 22:00 UTC, US east coast ends ~05:00 UTC the next day).

## Queue configuration

Defined in `config/horizon.php`.

```
Queues:
  default:        4 workers   # light operations, callbacks
  shopify-sync:   8 workers   # Shopify GraphQL tolerates higher concurrency
  ads-sync:       4 workers   # Meta / Google / TikTok share this queue
  aggregation:    2 workers   # Phase 2 ad_performance and product_performance jobs

Job timeouts:    10 minutes max per job
Retries:         3 attempts with exponential backoff (1m, 5m, 15m)
Memory limit:    256 MB per worker
```

## SyncBrandDayJob

Platform-agnostic. Accepts `(brand_id, date, platform_connection)` and resolves the adapter at runtime via `PlatformRegistry`.

```php
public function handle(PlatformRegistry $registry): void
{
    $log = SyncLog::create([
        'brand_id'    => $this->brand->id,
        'platform'    => $this->connection->platform,
        'target_date' => $this->date,
        'status'      => 'running',
        'started_at'  => now(),
    ]);

    try {
        $adapter  = $registry->for($this->connection->platform);
        $snapshot = $adapter->fetchDay($this->connection, $this->date);

        DailyMetric::upsert(
            $snapshot->toRow(),
            ['brand_id', 'platform', 'date'],
            $snapshot->updateableFields()
        );

        $log->update([
            'status'            => 'success',
            'completed_at'      => now(),
            'records_processed' => 1,
        ]);
    } catch (Throwable $e) {
        $log->update([
            'status'        => 'failed',
            'completed_at'  => now(),
            'error_message' => $e->getMessage(),
        ]);
        $this->connection->update([
            'status'     => 'errored',
            'last_error' => $e->getMessage(),
        ]);
        report($e);
        throw $e;   // let Horizon handle retry
    }
}
```

Notes:
- The job **must throw** on failure. Silent zeroes are the worst-case bug — see [10-edge-cases / missing data ≠ zero](../10-edge-cases/README.md#missing-data--zero).
- `daily_metrics.is_complete` only flips to `true` when the upsert lands cleanly. The UI reads this column to decide between rendering a number or an amber warning.

## Rate limit handling per platform

| Platform | Strategy |
|----------|----------|
| Shopify GraphQL | Cost-based throttling. Check `extensions.cost.throttleStatus` on every response. Sleep when `currentlyAvailable < 200` cost units. Honor `restoreRate`. |
| Meta Marketing API | Watch `X-Business-Use-Case-Usage` header. On error code 17 or 4, back off exponentially. System User tokens have higher quotas than user tokens. |
| Google Ads | Handle `QuotaError` and exponential backoff. The official SDK does most of this; configure max retries to 3. |
| TikTok Marketing API | Respect rate limit headers. On error code 40100, back off. Documented limit: 10 QPS per advertiser. |

Each platform's HTTP client (`app/Platforms/{Platform}/{Platform}Client.php`) owns its own retry and backoff logic. The job is unaware of platform specifics.

## Manual operations

- `php artisan brand:backfill {brand} --from=YYYY-MM-DD --to=YYYY-MM-DD` — dispatches `BackfillBrandRangeJob`. Used during onboarding and after fixing a broken connection.
- `php artisan brand:sync {brand}` — dispatches today's sync for one brand. Rate-limited per user via the API endpoint `POST /api/brands/{id}/sync`.
- `php artisan horizon` — runs Horizon supervisor. Supervised by `supervisor` on the box; restarted automatically on deploy.

## Health visibility

The Sync health page (`/sync-health` in the SPA, `GET /api/sync/status` in the API) lists every connection with its last successful sync time, last error, and a manual retry button. Master admins receive an email summary if more than 5% of brands failed yesterday's sync.
