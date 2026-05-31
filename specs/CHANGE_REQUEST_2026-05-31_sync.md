# Change request — sync surfaces (2026-05-31)

Author: Kanwar
Status: approved, awaiting docs update
Affects: docs/06-sync, docs/04-api (rate limits)

## What changes

Three additions to the sync model in docs/06-sync. The "one SyncBrandDayJob, platform-agnostic" non-negotiable from the original spec stays in force. Schedule and surface count expand.

### 1. Twice-daily Shopify auto-sync

A new schedule entry runs every 12h to keep Shopify revenue current without operator clicks.

| Time (UTC) | Job | Scope | Window |
|---|---|---|---|
| 01:00 + 13:00 daily | `sync:shopify-rolling` | every active brand × every active Shopify connection | today + yesterday in brand timezone |

Rationale. The 13:00 daily already exists for the 7-day rolling backfill (every platform). The new run at 01:00 picks up the half-day that closes between cron windows in every active timezone. Per-day (not history) jobs because history is a paginated all-time scan — appropriate for first-install only.

Why 01:00 + 13:00 specifically. 13:00 UTC is late enough that yesterday is closed in every active timezone (Madrid ends 22:00 UTC, US east coast ends ~05:00 UTC next day). 01:00 UTC is twelve hours later and falls inside the quietest Shopify API window globally.

Implementation: new `SyncShopifyRollingCommand` artisan command, scheduled in `app/Console/Kernel.php`. Stagger 15s per brand.

### 2. Manual sync — keep both surfaces

Two manual surfaces stay, both already wired:

| Surface | Endpoint | Role gate | Scope |
|---|---|---|---|
| Per-brand "Sync now" on `/brands/:slug` | `POST /api/brands/{brand}/sync` | brand policy update | that brand × all its connections × 7-day rolling (ads) or full history (Shopify) |
| Master "Sync now" on `/` dashboard | `POST /api/sync/all` | `role:master_admin,manager` | every active brand × every active connection, 30s stagger between brands |

Both are kept because the operator needs to be able to (a) force a single brand to refresh after fixing a connection, and (b) refresh the entire portfolio on demand without waiting for the next 12h tick.

### 3. Queued sync_log visibility

`sync_logs` rows are written at dispatch time, not at handler-start. New row contract:

| Status | Written by | When |
|---|---|---|
| `queued` | controller / artisan command | at `Job::dispatch()` |
| `running` | job `handle()` | first line of `handle()` (updates the queued row, doesn't insert) |
| `success` / `failed` | job `handle()` | end of try / catch |

This makes Sync health's "Queued: N" tile non-fictional. Schema already supports it (`sync_logs.status` enum already includes `queued`); only the write path changes.

Side effect: rate-limit visibility. If a fan-out enqueues 300 jobs and the worker is processing at 8 concurrency on `shopify-sync`, the operator sees the queue depth drain in real time.

## What stays the same

- One `SyncBrandDayJob`, platform-agnostic. `PlatformRegistry` still resolves the adapter at runtime.
- Spec rule: jobs throw on failure, Horizon owns retry (3 attempts, exponential backoff).
- Spec rule: missing data ≠ zero. `daily_metrics.is_complete` still gates the UI's number/amber decision.
- 13:00 UTC `RunDailySyncCommand` (7-day rolling, all platforms) stays as the canonical late-refund settle window.
- Hourly hot-brands `RunHourlySyncCommand` stays for top-20 by spend.

## What this does not authorize

- Does not authorize the existing `SyncBrandHistoryJob` as a permanent second job. Phase 1 punch-list item: fold it into a `mode=history` branch of `SyncBrandDayJob` (preferred), or document the two-job pattern in docs/06 with an explicit "history scan is a different beast" rationale. Decide before Phase 2.
- Does not change the failure → connection.status policy. The "permanent connection" agency policy override (sync failure leaves `status=active`, stamps `last_error`) is a separate change request — not in this one.
- Does not change Postgres → MySQL, Forge → Cloudways, or the `fx_rate_to_usd` removal. Those are open in `audits/AUDIT_2026-05-31.md` section 7 and need their own decisions.

## Docs touched

- `docs/06-sync/README.md` — schedules table gets the 01:00 + 13:00 row; manual operations section gets `POST /api/sync/all`; new "Queue lifecycle" subsection documents the queued→running→terminal contract.
- `docs/04-api/README.md` — endpoint reference adds `POST /api/sync/all` with rate limit `12,5` and role `master_admin,manager`; updates `POST /api/brands/{brand}/sync` rate limit to `30,1` (currently documented as `5,1`).

## Verification after merge

- `php artisan schedule:list` shows `sync:shopify-rolling` at 01:00 and 13:00 UTC.
- Master "Sync now" dispatches one queued row per `(active brand × active connection)` plus seven queued rows per ads connection. Sync health "Queued" tile equals that total at t+1s.
- Per-brand "Sync now" dispatches the same per-connection pattern scoped to one brand.
- `sync_logs.status` transitions visible: queued → running → success/failed.
