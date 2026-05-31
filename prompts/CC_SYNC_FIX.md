# Claude Code prompt — sync now visibility + 12h auto-sync

Paste this into Claude Code at the repo root. It fixes three things at once:

1. The "Sync now" button on a brand and the master "Sync now" on the dashboard return success but nothing shows in Sync health.
2. Queued jobs are invisible — Sync health only ever shows rows for jobs already running or finished.
3. Brands aren't auto-synced every 12 hours.

---

## Root cause (read before changing anything)

`SyncStatusController::trigger()` and `triggerAll()` only call `SyncBrandHistoryJob::dispatch()` / `SyncBrandDayJob::dispatch()`. The `sync_logs` row is created **inside `handle()`** on the worker (`api/app/Jobs/SyncBrandDayJob.php:59`, `api/app/Jobs/SyncBrandHistoryJob.php:71`). So:

- If Horizon isn't supervised on Cloudways → jobs sit in Redis, **zero rows ever land in `sync_logs`**, Sync health stays empty.
- Even with Horizon running, there is **no `queued` row written at dispatch time**, even though the schema (`2026_01_01_000005_create_sync_logs_table.php`) and the `SyncStatusController::index()` counts aggregation (`'queued' => $counts['queued'] ?? 0`) both already support it.

There is no twice-daily auto-sync. Current schedules in `app/Console/Kernel.php`: daily 13:00 UTC (7-day rolling, all platforms) and hourly 06–22 UTC (top-20 hot brands, today only). Spec §06-sync confirms — nothing in between for "every brand, every 12h, Shopify".

---

## What to change

### 1. Write a `queued` sync_log at dispatch time

Make sync_log creation the controller's responsibility, not the job's. Pass the log id to the job and have the job **update** (not create) it.

**File:** `api/app/Http/Controllers/Api/SyncStatusController.php`

Replace the body of `trigger(Brand $brand)` so that for **every** dispatch (Shopify history fan-out AND per-day ads fan-out), a `SyncLog` row is created first with `status = 'queued'`, `started_at = null`, and the resulting `id` is passed into the job constructor.

Same treatment for `triggerAll()`. Preserve the existing 30s stagger across brands.

Same treatment for `retryLog()` — it currently re-dispatches `SyncBrandDayJob` with no log row, so retries are also silent on Sync health until the worker picks them up.

The Shopify history case (`SyncBrandHistoryJob`) writes one queued row per `(brand × shopify connection)`. Use `target_date = today in brand timezone` to match the existing convention.

### 2. Update the jobs to consume the prewritten log id

**Files:** `api/app/Jobs/SyncBrandDayJob.php`, `api/app/Jobs/SyncBrandHistoryJob.php`

Add an optional `?int $logId = null` constructor parameter on both jobs. In `handle()`:

- If `$logId !== null`: load that `SyncLog`, transition it `queued → running` (set `started_at = now()`). Do not create a new row.
- If `$logId === null` (cron path, tests, console commands): keep current behavior — create the row in `handle()`. This preserves backward compatibility with `RunDailySyncCommand`, `RunHourlySyncCommand`, `BackfillBrandRangeJob` until they're updated in step 4.

All terminal updates (`success`, `failed`) stay as they are — they always operate on `$log->update([...])`, so the only change is how `$log` is acquired.

### 3. Add the twice-daily Shopify auto-sync

**File:** `api/app/Console/Commands/SyncShopifyRollingCommand.php` (new)

`php artisan make:command SyncShopifyRollingCommand` with signature `sync:shopify-rolling`.

For every active brand × active Shopify connection, dispatch `SyncBrandDayJob` for **today and yesterday** in the brand's timezone. Use the same controller-side pattern — write a `queued` sync_log first, pass the id into the job constructor. Stagger by 15s per brand (less than the manual fan-out because cron is unattended; we want it to complete inside ~25 min for 100 brands).

Why today + yesterday rather than just today: the 12h cycle straddles midnight in every timezone. Re-upserting yesterday is free (composite key on `brand_id, platform, date`) and catches late-attribution refunds inside the same day Shopify settles them.

Why per-day jobs rather than `SyncBrandHistoryJob`: history is a paginated all-time scan; it's correct for a one-shot "first install" but absurdly expensive twice a day. The day-job hits a single Shopify query for the date window.

**File:** `api/app/Console/Kernel.php`

Add the schedule. Use UTC anchors of 01:00 and 13:00 so the run that follows the 13:00 daily by 12h is the one that catches up partial days.

```php
$schedule->command('sync:shopify-rolling')
    ->twiceDailyAt(1, 13, 0)
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
```

If `twiceDailyAt` isn't on this Laravel version (11.x has it as `twiceDaily`), use two separate `dailyAt('01:00')` and `dailyAt('13:00')` calls.

### 4. Sweep the other dispatch sites for the same bug

For consistency (and so backfills / cron also surface as queued):

- `api/app/Console/Commands/RunDailySyncCommand.php` line 49: wrap the `SyncBrandDayJob::dispatch` in the controller-side pattern (create queued log, pass id).
- `api/app/Console/Commands/RunHourlySyncCommand.php` line 67: same.
- `api/app/Jobs/BackfillBrandRangeJob.php`: same wherever it fans out per-day jobs.

These aren't blocking the user-visible bug today, but if you don't do them the cron runs will keep landing rows in `running`/`success`/`failed` only, never `queued`, which makes the Sync health "Queued: N" tile lie about cron pressure.

### 5. Cloudways Horizon supervisor (operational, not code)

The above is moot if no worker is consuming the queue. On Cloudways:

```
Application Settings → Cron Job Management → Add SUPERVISOR
Type: Supervisor
Command: php /home/master/applications/<app-name>/public_html/api/artisan horizon
Auto-restart: yes
```

Then verify with `php artisan horizon:status` (should return "running") and watch a manual Sync now land in `sync_logs` within 2–5 seconds.

If Horizon supervisor setup is blocked, the emergency fallback is `QUEUE_CONNECTION=sync` in `.env` — jobs run inline during the HTTP request. This makes the per-brand Sync now freeze the tab for the duration of the Shopify scan and makes the 12h cron block the scheduler. Acceptable for a one-day demo, not for production.

---

## Tests to add

- `tests/Feature/Api/SyncTriggerTest.php`: assert that POSTing `/api/brands/{brand}/sync` writes one `sync_logs` row per connection with `status = 'queued'` and `started_at = null` **before** any worker runs (use `Queue::fake()` and assert the count after the request).
- Same for `POST /api/sync/all`.
- `tests/Feature/Console/SyncShopifyRollingTest.php`: assert `sync:shopify-rolling` dispatches one job per `(active brand × shopify connection × 2 days)` and writes a `queued` row per job.
- `tests/Unit/Jobs/SyncBrandDayJobTest.php`: assert that when `$logId` is passed, the job updates that row and does not create a second one.

---

## Acceptance check

After deploy, in this order:

1. `php artisan horizon:status` → running.
2. Click Sync now on any brand → within 1s, Sync health shows that brand's connections with `Queued` status; the queued tile increments.
3. Worker picks them up → rows transition to `running` then `success`. The `Queued` tile decrements live (5s poll interval on the Sync health page).
4. Click master Sync now on dashboard → all active brands enqueue together, staggered 30s apart; `Queued` tile jumps to the total dispatched count, drains over the next several minutes.
5. Run `php artisan schedule:list` → confirm `sync:shopify-rolling` appears with two runs/day at 01:00 and 13:00 UTC.
6. Manually run `php artisan sync:shopify-rolling` → every Shopify-connected active brand gets two queued rows (today + yesterday). They drain.

---

## Do NOT

- Don't create the new sync_log inside the job when `$logId` is provided (would double-write).
- Don't change the `sync_logs` schema — it already supports the four statuses.
- Don't use `dispatchSync()` anywhere on the controller path. Manual Sync now must be async or PHP's `max_execution_time` kills the request for any store with >a few hundred orders (this was the regression fixed when SyncBrandHistoryJob was moved off the inline path).
- Don't add a new queue. `shopify-sync` and `ads-sync` (defined in `config/horizon.php`) are already correct.
- Don't switch `QUEUE_CONNECTION` to `sync` in committed config. It's an emergency `.env` override only.
