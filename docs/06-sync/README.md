# 06 — Sync

How data flows from external APIs into `daily_metrics`. One job, one schedule, four adapters.

## Schedules

All times UTC. Defined in `app/Console/Kernel.php`.

| Time | Job | What it does |
|------|-----|--------------|
| 01:00 | `SyncShopifyRollingCommand` | For every active brand × active Shopify connection, dispatch `SyncBrandDayJob` for today and yesterday in the brand timezone. Twice-daily auto-sync — partner to the 13:00 run. |
| 13:00 | `SyncShopifyRollingCommand` | Same as 01:00 — twice-daily Shopify auto-sync. |
| 13:00 | `RunDailySyncCommand` | For every active brand × active connection (all platforms), dispatch `SyncBrandDayJob` for yesterday and 6 prior days. Rolling 7-day backfill catches late refunds. |
| 13:30 | `FetchDailyCurrencyRatesJob` | Pull yesterday's FX rates from exchangerate.host (free, no key) for every base currency in use. Removed in Phase 1; restore when USD aggregation comes back. |
| Every 60 min, 06:00–22:00 | `RunHourlySyncCommand` | For "hot" brands (top-20 by spend), dispatch `SyncBrandDayJob` for today only. |
| 02:00 Sunday | Sync log cleanup | Delete `sync_logs` older than 90 days. Keep `audit_logs` forever; sync_logs are noise after 90 days. |

13:00 UTC is late enough that "yesterday" is closed in every active timezone (Madrid ends 22:00 UTC, US east coast ends ~05:00 UTC the next day). 01:00 UTC is twelve hours later and falls inside the quietest Shopify API window globally — the twice-daily Shopify cadence keeps revenue dashboards current without operator clicks.

Rationale for the twice-daily Shopify cadence is in `specs/CHANGE_REQUEST_2026-05-31_sync.md`.

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

Platform-agnostic. Accepts `(brand, platform_connection, date, ?logId)` and resolves the adapter at runtime via `PlatformRegistry`.

The optional `$logId` parameter is the id of a `sync_logs` row prewritten by the caller in `queued` state. When present, the job transitions that row to `running` instead of creating a fresh one. When absent, the job creates the row inline — back-compat for older callers and tests.

```php
public function handle(PlatformRegistry $registry): void
{
    $log = $this->logId !== null
        ? SyncLog::find($this->logId)?->fresh()
        : null;

    if ($log) {
        $log->update(['status' => 'running', 'started_at' => now()]);
    } else {
        $log = SyncLog::create([
            'brand_id'    => $this->brand->id,
            'platform'    => $this->platformConnection->platform,
            'target_date' => $this->date,
            'status'      => 'running',
            'started_at'  => now(),
        ]);
    }

    try {
        $adapter  = $registry->for($this->platformConnection->platform);
        $snapshot = $adapter->fetchDay($this->platformConnection, $this->date);

        DailyMetric::upsert(
            $snapshot->toRow(1.0),
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
        $this->platformConnection->update([
            // Agency policy: connection stays active on transient failures.
            // Token rotation happens in-band via ShopifyClient::onUnauthorized.
            'status'     => 'active',
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
- A second job — `SyncBrandHistoryJob` — handles the one-shot full-history paginated scan that the per-brand manual Sync now triggers on Shopify. Folding the two into a single `mode=history` branch of `SyncBrandDayJob` is a Phase 1 cleanup item; the two-job pattern stays for now.

## Queue lifecycle

`sync_logs` row states (column: `status`):

| Status | Set by | When |
|---|---|---|
| `queued` | controller / artisan command | at `Job::dispatch()` — written before the row leaves the request thread, so the Sync health "Queued" tile is non-fictional |
| `running` | job `handle()` first line | when Horizon picks up the job — updates the queued row, doesn't insert |
| `success` | job `handle()` end of try | after the `daily_metrics` upsert lands cleanly |
| `failed` | job `handle()` catch block | on any throwable; Horizon retries (1m / 5m / 15m backoff, 3 attempts) |

Every dispatch site writes a `queued` row before calling `Job::dispatch()` — controller (`SyncStatusController::trigger`, `triggerAll`, `retryLog`), `RunDailySyncCommand`, `RunHourlySyncCommand`, `SyncShopifyRollingCommand`, `BackfillBrandRangeJob`. If a queued row is older than 30 minutes and still `queued` or `running`, treat it as orphaned (Horizon crashed, queue stuck) and a fresh dispatch is allowed.

## Idempotency

Manual and cron dispatch are both guarded against double-queueing for the same brand. The rule:

> If any `sync_logs` row with `status IN ('queued', 'running')` exists for this brand within the last 30 minutes, do not queue another sync for it.

Behavior per surface:

| Surface | When idempotency hits |
|---|---|
| `POST /api/brands/{brand}/sync` | Returns 409 with `reason: already_in_progress` and the in-flight log ids. Frontend renders an info toast. |
| `POST /api/sync/all` | Skips the brand, counts it under `brandsAlreadyRunning` in the response. No 409. |
| `sync:shopify-rolling` | Skips the brand silently, prints the skip count to stdout. |
| `sync:daily` | Same — skips, prints count. |
| `sync:hourly` | Same — skips, prints count. |
| `POST /api/sync-logs/{log}/retry` | Not guarded. The operator clicking Retry is explicit and the failed-row context is already on screen. |

The 30-minute window is sized to cover the worst-case wall time of a single Shopify history scan (15m × 2 attempts) plus a few minutes of Horizon backoff slack. Past 30 minutes a queued/running row is treated as orphaned and a fresh dispatch is allowed.

## Rate limit handling per platform

| Platform | Strategy |
|----------|----------|
| Shopify GraphQL | Cost-based throttling. Check `extensions.cost.throttleStatus` on every response. Sleep when `currentlyAvailable < 200` cost units. Honor `restoreRate`. |
| Meta Marketing API | Watch `X-Business-Use-Case-Usage` header. On error code 17 or 4, back off exponentially. System User tokens have higher quotas than user tokens. |
| Google Ads | Handle `QuotaError` and exponential backoff. The official SDK does most of this; configure max retries to 3. |
| TikTok Marketing API | Respect rate limit headers. On error code 40100, back off. Documented limit: 10 QPS per advertiser. |

Each platform's HTTP client (`app/Platforms/{Platform}/{Platform}Client.php`) owns its own retry and backoff logic. The job is unaware of platform specifics.

## Manual operations

Three sync control surfaces, all backed by `SyncBrandDayJob` / `SyncBrandHistoryJob`:

| Surface | What it does | Authorization |
|---|---|---|
| `POST /api/brands/{brand}/sync` — per-brand "Sync now" button | Shopify: full history scan via `SyncBrandHistoryJob`. Ads: 7-day rolling fan-out via `SyncBrandDayJob`. Throttled `30,1`. | `BrandPolicy::update` |
| `POST /api/sync/all` — master "Sync now" on dashboard | Same per-brand fan-out applied to every active brand, 30s stagger between brands. Throttled `12,5`. | role `master_admin` or `manager` |
| `POST /api/sync-logs/{log}/retry` — per-row Retry on Sync health | Re-dispatches a single `SyncBrandDayJob` for the (brand, platform, date) of the failed log. Throttled `30,1`. | authenticated |

Console equivalents:

- `php artisan sync:shopify-rolling` — runs the twice-daily Shopify cadence on demand. `--brand={slug}` scopes to one brand.
- `php artisan sync:daily` — runs the 7-day rolling backfill on demand. `--brand={slug}` scopes to one brand.
- `php artisan sync:hourly` — runs the hot-brands today-sync on demand.
- `php artisan brand:backfill {brand} --from=YYYY-MM-DD --to=YYYY-MM-DD` — dispatches `BackfillBrandRangeJob`. Used during onboarding and after fixing a broken connection.
- `php artisan horizon` — runs Horizon supervisor. Supervised by Cloudways Supervisor on the box; restarted automatically on deploy.

## Health visibility

The Sync health page (`/sync-health` in the SPA, `GET /api/sync/status` in the API) lists every connection with its last successful sync time, last error, and a manual retry button. Master admins receive an email summary if more than 5% of brands failed yesterday's sync.
