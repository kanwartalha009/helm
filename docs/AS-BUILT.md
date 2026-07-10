# Helm — as-built (current reality vs spec)

The numbered spec (`docs/00–13`) is the contracted vision and stays intact; we
build toward it. This page is the **current-state overlay** — what is actually
true in production today — so no one reads a spec section and assumes it
describes the running system. Every delta points to its ratified decision in
[`decisions/`](./decisions/README.md).

## Status

- **Live in production since 2026-06-01.** ~80 real brands syncing; the agency
  owner uses the dashboard daily. (The spec README's "build not yet started" line
  is historical.)
- **Phase 1** (dashboard) and **Phase 1.5** (RBAC — team/brand users, per-brand
  scoping, audit log, MFA scaffolding) have shipped.

## Stack deltas (vs §02)

| Spec | As-built | Decision |
|------|----------|----------|
| PostgreSQL 16 | MySQL | D-001 |
| Hetzner CCX22 + Forge | Cloudways (`scripts/deploy.sh`) | D-002 |
| Laravel 11, PHP 8.3, Redis 7, Horizon, Sanctum, React 18 / Vite / TS / Tailwind / TanStack / Recharts | as spec | — |

## Behaviour deltas

| Area | Spec | As-built | Decision |
|------|------|----------|----------|
| Default revenue | gross / net, order-based, toggle | Total revenue = Shopify `total_sales` (ShopifyQL); net sales hidden | D-005, D-006 |
| Blended ROAS | `revenue_net / spend` | follows the selected metric | D-007 |
| Sync schedule | 13:00 UTC daily | 12h `sync:daily` + manual (per-brand & master) | D-003 |
| Dashboard scope | all brands | soft-defaults to assigned brands + manager filter | D-008 |
| UI product name | Helm | Roasdriven (client-facing); internals stay Helm | D-012 |

## Shipped beyond the Phase-1 spec

- Year-over-year comparison — revenue, ad spend, and ROAS vs the same dates last
  year (D-010).
- Historical backfills — `shopify:backfill-sales` (revenue) and
  `ads:backfill-spend` (Meta + Google spend) (D-010, D-011).
- All three ad platforms built — Meta live, Google live, TikTok built (BC token
  pending).

## Delivery model (D-013)

We ship **one feature at a time, validated by the client (Bosco), prioritised by
the pain points of running a marketing agency for ecommerce clients** — not the
fixed 5-phase / 22-week cadence the spec describes. The phases remain the
architectural target; the sequence and pace are now driven by client feedback.
"Phase 2" is therefore a prioritised pain-point backlog (deep analytics,
reporting, alerting), released incrementally, not a single block.

## What still matches the spec exactly

The architecture is intact. Platform-adapter pattern, polymorphic `daily_metrics`,
one `SyncBrandDayJob`, manager-level auth (Meta System User / Google MCC / TikTok
BC / per-store Shopify), native currency + `fx_rate_to_usd` snapshot at sync
time, brand-timezone dates, missing-data-is-not-zero. The deltas above are host,
data-model, and product choices — not structural ones.

## 2026-07-10 ambiguity sweep (audit follow-up)

- **"Today" data now auto-refreshes**: `sync:shopify-rolling` is scheduled
  twice daily at 03:00/15:00 UTC (D-018). `sync:hourly` is retired.
- **Stub endpoints removed** (`/api/dashboard/summary`, `/brands/{id}/trend`)
  — D-020.
- **Spec §12 range picker is superseded**, not pending — D-019.
- **Sweden/EUR exclusion is DORMANT**: the rule (docs/README non-negotiable)
  and the config comment (`config/sync.php`) stand, but no aggregation
  consumes a EUR currency grouping today, so there is nothing for the
  exclusion to act on. It must be honored the day a currency-group view
  ships; nothing to fix until then.
- **MFA enforcement (D-009)**: owner = Kanwar. Path: Cloudways support ticket
  to NTP-sync the server clock, then `HELM_REQUIRE_ADMIN_MFA=true` +
  `php artisan config:cache`. Fallback if Cloudways won't fix the clock:
  widen the TOTP verification window by one period (code change, ~30 min).
- **Open server fact**: whether Horizon runs under Supervisord (deploy doc
  Path A) or the `queue:work` cron fallback (Path B) — decides whether the
  8/4/4/2 worker concurrency is real. Check:
  `ps aux | grep -E 'horizon|queue:work' | grep -v grep` on the Cloudways box.

## 2026-07-10 LLM layer + deep-analytics pages (slice 2.3 + 2.4 pages)

- **D-016 ratified and built**: report narrative (4 editable blocks:
  observations / actionable outputs / action plan / new ideas) + per-brand
  "Ask the data" chat. Provider-agnostic (`HELM_LLM_PROVIDER`:
  anthropic | openai), keys in Settings → Platform keys → AI / LLM (or env).
  Proof step: `php artisan llm:diagnose`. See `docs/LLM_SETUP.md`.
- **Privacy boundary**: `api/app/Services/Llm/BrandDataScope.php` is the ONLY
  payload builder for both surfaces — aggregates, names and rule verdicts
  only; its key surface is locked by a CI test (LlmLayerTest).
- **Cost stance**: generation is operator-triggered (button / chat message),
  never automatic, and gated to master_admin/manager. Drafts are cached per
  brand × report × period in `report_narratives` (additive migration).
- **Product performance page is real** (commerce_daily_metrics aggregates,
  prior-window deltas; honest backfill hint when a brand has no commerce
  rows — `shopify:backfill-commerce`).
- **Store audit page is real** and rules-only (campaign verdicts, dead
  stock, freshness — spec §4.3: badges are deterministic, never LLM).
