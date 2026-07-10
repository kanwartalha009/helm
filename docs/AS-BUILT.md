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

## 2026-07-10 ads 10/10 pass + onboarding backfill + LLM key CRUD

- **Ads audit** vs current Meta/Google/TikTok APIs: `audits/ADS_AUDIT_2026-07-10.md`.
  All FREE/CHEAP gaps implemented same day (3 additive migrations); new fields
  populate on the next sync/backfill and render em-dashes until then.
- **Onboarding backfill**: GET `brands/{brand}/data-coverage` + POST
  `brands/{brand}/backfill-dataset` (admin/manager). Coverage card on the
  brand page + ads views renders ONLY where 12-month history is missing;
  history rides the existing per-day fan-out (Sync health), campaign/creative/
  commerce runs tracked in `backfill_runs`. Reruns resume (idempotent upserts).
- **AI/LLM key CRUD** in Settings → Platform keys (add/rotate/reveal/delete/
  test) + provider picker (workspace setting `llm_provider` wins over env).

## 2026-07-10 ad-set middle layer + product-level ROAS (spec feature-specs/product-audit-adset-underperformers.md)

- **`ad_set_daily_metrics`** (additive migration) — the middle layer Helm was
  blind to: Meta ad sets, Google ad groups + PMax **asset groups**
  (`entity_kind`), TikTok ad groups. One row per (brand, platform, date,
  ad_set_id). Reach/frequency are Meta-only (null elsewhere, never 0);
  impression-share is Google-only. Budget/learning/status are point-in-time
  snapshots ("as of last sync"). Written by **`AdSetSync`** (wired into
  `SyncBrandDayJob`, same fx-snapshot pipeline as campaigns) + **`ads:backfill-
  adsets`** (chunked); both ride the `campaigns` backfill dataset, so one
  "Backfill" click fills campaign **and** ad-set grain. Fetchers live in
  `app/Platforms/{Meta,Google,TikTok}/` (AdSetFetcher / AdGroupFetcher).
- **Data-coverage** now requires BOTH grains for the `campaigns` card to read
  "covered" — the ~80 live brands with backfilled campaigns but an empty
  ad-set table are correctly re-prompted to backfill.
- **`AdSetFlags`** engine (rules-only, `config/rules.adset`) — no_purchase_kill
  (critical), below_breakeven, high_frequency (Meta), low_ctr, learning_limited
  (Meta `LEARNING_LIMITED`), budget_starved (Google impression-share / TikTok
  status / Meta full-budget days), account-level fragmentation. Verdict gate:
  no performance flag under `min_evidence_usd`; status-based flags exempt.
  Window frequency is a **blended proxy** (Σimpressions ÷ Σreach — conservative;
  true deduped frequency needs a windowed pull), captioned as such.
- **Endpoint** `GET brands/{brand}/ads/campaigns/{campaign}/adsets?period=&platform=`
  → ad sets + flags + `asOf`; rendered in the campaign drawer (`AdsCampaignDrawer`)
  as an "Ad sets" table (budget/spend/ROAS/CPA/freq/flags, PMax asset-group note).
  The audit page consumes `AdSetFlags::forBrand` as `ads`-area rollup cards.
- **Product-level ROAS** — `ad_product_daily` widened with a `platform` column
  (default 'meta'; unique key swapped to include platform, index swap moves no
  data). Google (`ad_group_ad` final URLs) + TikTok (`ad/get` landing URL)
  attribute spend by the same landing-URL→handle regex as Meta; Shopping/PMax
  feed spend stays UNMAPPED. Products page shows **Ad spend + ROAS** (mapped
  only) with an always-on footer stating the mapped %, and a `losing_on_ads`
  flag (≥$100 mapped spend below breakeven/1.0). Product ROAS is scoped to the
  products page (not the audit rollup). `CampaignNameParser` ships as an
  **OFF-by-default seam** (workspace setting `ad_product_name_rules`), not yet
  wired into the fetchers. See ADR **D-021**.
