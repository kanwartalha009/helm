# 01 — Architecture

Three independent layers connected by HTTP and Redis. None needs to know the internals of the others.

## Contents

- [Layers](#layers)
- [Read path — browser to data](#read-path)
- [Write path — external APIs to data](#write-path)
- [Why a single SyncBrandDayJob](#why-one-job)
- [Platform adapter contract](./platform-adapter.md) — required reading before any code

## Layers

| Layer | Stack | Role |
|-------|-------|------|
| Web | React 18 + Vite SPA, served as static bundle from the same VPS | Rendering, formatting, filter state. No business logic. |
| API | Laravel 11, stateless | Auth, dashboard, brands, connections, sync status, analytics, users, tickets. All persistence in Postgres. |
| Workers | Laravel Horizon | Consume jobs from Redis, run `PlatformAdapter::fetchDay()`, write `daily_metrics`, update `sync_logs`. Never serve HTTP. |

The SPA bundle is served by Nginx at root. API routes are reverse-proxied to PHP-FPM at `/api/*`, `/horizon`, and `/connections/*/callback`.

## Read path

```
Browser  →  GET /api/dashboard?date_range=last_7&currency=usd
         →  Laravel route → DashboardController
         →  Query daily_metrics (filtered by user's accessible brands)
         →  Group by brand, compute deltas, return JSON
         →  React renders TanStack Table
```

Authorization is enforced by a global scope on `Brand`. Every query that touches brands automatically filters by `Auth::user()->accessibleBrandIds()`. See [08-rbac](../08-rbac/README.md).

## Write path

```
Cron at 13:00 UTC   →  RunDailySyncCommand
                    →  For each active brand × active connection,
                        dispatch SyncBrandDayJob(brand, date, platform)
                    →  Horizon picks job from platform-specific queue
                    →  Worker resolves the right PlatformAdapter
                    →  Adapter calls external API (Shopify/Meta/Google/TikTok)
                    →  Worker upserts MetricSnapshot into daily_metrics
                    →  Worker writes sync_logs row
```

Failure cases:
- Job throws → `sync_logs.status = 'failed'`, connection marked `errored`, exception reported to Sentry, Horizon retries up to 3 times with exponential backoff.
- Partial fetch → adapter must throw, not silently store zeroes. `daily_metrics.is_complete` only flips to `true` on a clean fetch.

## Why one job

`SyncBrandDayJob` is platform-agnostic. It accepts `(brand_id, date, platform)` and resolves the right adapter at runtime via `PlatformRegistry::for($platform)`. Adding TikTok Phase-2 metrics, or Pinterest next year, requires only a new adapter file. No new jobs, no new tables, no new controllers.

This is the single most important reason the codebase will still be small at Phase 3 and still extensible at Phase 4. See [06-sync](../06-sync/README.md) for the job's outline and [platform-adapter.md](./platform-adapter.md) for the contract.
