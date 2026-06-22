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
