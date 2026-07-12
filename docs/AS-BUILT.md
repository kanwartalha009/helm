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

## GO-1.1 — Klaviyo email revenue (data engine; 2026-07-11)

- **`email_daily_metrics`** — Klaviyo-attributed revenue per (brand, date, source
  ∈ flow|campaign, source_id). Native money + `fx_rate_to_usd` snapshot; `workspace_id`
  seam (D-022); missing ≠ 0. Email revenue is its OWN channel and is **never summed**
  into store or ad revenue (master-plan §0.1 honesty law) — the read surfaces (pending,
  GO-1.1d) render it beside a mandatory Klaviyo attribution honesty box (`config/klaviyo.php`).
- **Adapter** `app/Platforms/Klaviyo/` — `KlaviyoClient` (per-brand key, `revision`
  header, 429→`PlatformRateLimitedException` on Retry-After; key never logged) +
  `RevenueFetcher` (metric-aggregates on the Placed Order metric, grouped by
  `$attributed_flow` then `$attributed_message`; brand-tz windows; raw store currency).
- **`KlaviyoSync`** — fx-snapshot upsert on (brand,date,source,source_id). Wired into
  `SyncBrandDayJob` on the **shopify** connection (once per brand-day), self-guarded so it
  can never re-queue/fail the main sync; re-pulls the day (Klaviyo attribution is
  retroactive). `klaviyo:backfill {brand} --since=` (month-chunked, clamped to the
  2023-06-01 data floor, sleeps on rate-limit).
- **Credentials** — `platform_credentials` is now brand-scopable (ADR **D-024**): the
  Klaviyo private key is per brand. CRUD via `GET/PUT/DELETE brands/{brand}/klaviyo`
  (+`/test`), admin/manager-gated, write-only (returns a connected flag + test result).
- **Surfaces** — brand-Settings `KlaviyoKeyCard` (write-only key, live test on save,
  immutable-scopes hint); data-coverage `email` dataset (only for brands with a key) whose
  Backfill button runs `klaviyo:backfill`; **Weekly report** `email` block + **Monthly report**
  `sections.email` (revenue, orders, vs-store ratio, top flows/campaigns).
- **The honesty law, enforced:** every Klaviyo number renders with the attribution honesty box
  (`config/klaviyo.honesty_box`) and email revenue is **its own channel** — never added to store
  revenue, ad revenue or the channel mix. `shareOfStore` is a ratio of two measured numbers, not
  an additive split. A regression test asserts total revenue stays 1000 (not 1300) when 300 of
  email revenue exists. No Klaviyo rows → null / `needs_source`, never €0.
- **Not built (deliberate):** the dashboard email column — the dashboard has two engines behind
  the `helm:dashboard-parity` gate, so it lands as its own step once a brand has real email data.

## GO-1.2 — Product costs → contribution margin (2026-07-12)

- **Shopify cost** — `CatalogFetcher` now pulls `ProductVariant.inventoryItem.unitCost` (verified
  2026-07-12 on shopify.dev: nullable `MoneyV2`, needs `read_inventory`/`read_products`; Shopify can
  withhold it behind the "View product costs" permission). Product-level cost = **mean of the
  non-null variant costs**; stored on `product_catalog.unit_cost` + `unit_cost_currency`.
- **Manual costs** — `product_costs` is **effective-dated** (row per change): the cost in force on a
  window's date is the one used, so a price rise in March cannot rewrite January's margin. Set/cleared
  via `PUT`/`DELETE brands/{brand}/product-costs` (admin/manager) or inline on the Products table.
- **`CostResolver`** is the SINGLE place margin math lives. Precedence: **manual → Shopify →
  brand `gross_margin_pct` → unknown**. Contribution margin = revenue − COGS − *mapped* ad spend.
- **Missing ≠ zero, enforced:** an unknown cost yields `null` everywhere (`cogs`,
  `contributionMargin`, `costSource`), never a 0 COGS — which would manufacture a 100% margin. A
  brand-margin estimate is tagged `brand_margin` and is never displayed as a per-unit cost.
- **Shipping / payment fees** are declared in `config/costs.php` and default **null = not modelled**
  (§4.2: never invent them). The formula caption on the Products page says so out loud.
- ⚠️ **Open, Kanwar's call:** `ShopifyClient::API_VERSION = '2025-01'` appears to be outside Shopify's
  supported window (oldest currently listed: 2025-10). Not changed as a side-effect of this work.

## GO-1.3 — Data-quality score (2026-07-12)

- **`App\Services\Rules\DataQuality`** — a per-brand 0–100 score from FIVE measured components
  (`config/quality.php` weights): connections (20), sync freshness (25), history depth vs the
  12-month target (20), ad grain — campaign/ad-set/creative rows (20), and product-cost coverage (15).
  Nothing is estimated: every component maps to a countable fact and reports its exact gap plus the
  `fix` dataset key that closes it.
- **It is a GATE, not a badge.** `meetsGate()` (threshold `config('quality.threshold')`, default 70)
  is what GO-3/GO-4 recommendations will require: below it, Helm declines to advise and says what's
  missing. Advising confidently on holey data is the generic-advice failure mode that cost the
  incumbents their credibility.
- **Inapplicable ≠ zero.** A component that cannot apply to a brand (ad grain with no ad platform) is
  EXCLUDED from the denominator, never scored 0 — the applicable weights are re-normalised to 100.
- **Surfaces:** `GET brands/{brand}/data-quality` (computed fresh, so the score visibly moves the
  moment a backfill lands) → `DataQualityCard` + "What's missing?" drawer on brand detail;
  `GET brands-quality` (all accessible brands, 15-min cached) → `QualityDot` on both dashboard tables.
- **Why a separate endpoint:** the dashboard runs two engines behind the `helm:dashboard-parity` gate.
  The chip is merged client-side by brand id, so quality never enters that blast radius — **no
  dashboard-engine file was touched.**

## GO-1.4 — MER spine + bias annotations (2026-07-12) — completes GO-1

- **`config/truth.php`** — the doctrine in config. Every bias claim is SOURCED in comments (Haus, 640
  incrementality experiments: Meta Advantage+ **over-credits ≈ +12pp** vs manual, while Meta on strict
  7-day-click **UNDER-reports DTC** ≈ $115 real per $100 reported; Google/TikTok = "platform-attributed,
  unverified"). Strings are edited here, never hardcoded in a component.
- **`App\Reports\Support\TruthSpine`** — **MER** (store revenue ÷ total ad spend, USD ratio math, D-005
  revenue basis) is the SPINE: the one figure that doesn't depend on a platform grading its own homework.
  Each connected platform's OWN reported ROAS sits beside it carrying its bias annotation.
- **Three invariants, enforced in code and tests:** (1) platform-reported revenue is returned as a **LIST
  and NEVER summed** — two platforms routinely claim the same order, so a "total attributed revenue" is a
  fiction, and a test asserts no such key exists; (2) no spend → **null ROAS, never 0**; (3) an unconnected
  platform is **absent, not a zero row**.
- **Surfaces:** `GET brands/{brand}/truth` → `TruthPanel` on brand detail; a **Truth** section in the
  overall-performance report; and the dashboard's blended-ROAS header relabelled **"(MER)"** with a
  store-truth tooltip. Labels: MER = `Verified — store truth`; platforms = `Platform-reported — unverified`.
- **Neither dashboard engine was touched** (confirmed via porcelain) — the dashboard change is
  presentation-only, so the `helm:dashboard-parity` gate is unaffected.

**GO-1 (Truth completion) is COMPLETE:** Klaviyo email revenue · product costs → contribution margin ·
data-quality score (the recommendation gate) · MER spine + bias annotations.

## GO-2.1 — Monthly targets + pacing (2026-07-12)

- **`brand_targets`** — one row per (brand, month). `month` is a **'Y-m' string**, not a date: a target
  belongs to a calendar month in the BRAND's timezone, and a date column invites drift at the month
  boundary. Every target (revenue, spend cap, ROAS, MER) is independently **nullable** — unset is unset,
  never 0.
- **`App\Services\Rules\Pacing`** — `expected-by-now = target × (COMPLETE days ÷ days in month)`.
- **The invariant that defines this feature: pacing counts only days that have FINISHED *and* SYNCED.**
  If it counted today, every brand on the dashboard would read "behind" every single morning — an
  artefact of the clock, not of performance. Elapsed days and actuals are drawn from the same
  complete-day set, so they agree by construction. Zero complete days → status `unknown`, never
  "behind". No target → **null, and no chip**: Helm does not invent a goal so it can grade you against it.
- **Surfaces:** `GET brands/{brand}/targets` (+ `PUT`/`DELETE`, admin/manager) → `TargetsCard` on brand
  detail (editor, pace rows, and a "Day N of M — complete days only" caption so the maths can be checked
  by hand); `GET brands-pacing` → `PacingChip` on both dashboard tables.
- **Neither dashboard engine was touched** (porcelain-verified) — the chip is merged client-side, so the
  `helm:dashboard-parity` gate is unaffected. Third feature shipped on this pattern.

## Brand goals — Bosco cut (2026-07-12, D-025)

Extends GO-2.1. **Master plan §5.1 is now partially done**; what remains of it is the per-month *override*
picker (schema + API already support it) and MER/spend-cap surfaces.

- **`brand_targets.month` is now NULLABLE — null = the brand's STANDING goal**, in force for every month
  with no explicit override. The Settings UI writes only this one; a month picker would ask the operator to
  retype the same number twelve times a year.
- **Uniqueness is enforced on a generated `month_key = COALESCE(month,'__default')`, not on `month`.**
  The spec asked for `unique (brand_id, month)` with a nullable `month` — but **on MySQL, NULLs are DISTINCT
  in a unique index**, so that key would have permitted a brand to accumulate several standing goals, with
  pacing picking one at random. A brand could have silently carried two conflicting revenue targets. Same
  trap already hit on `budget_plans.country` and `anomalies.subject`; same fix as D-024.
- **Pacing ROAS is computed in USD from the fx snapshots** — `Σ(revenue × fx) ÷ Σ(spend × fx)`, never
  native ÷ native. A brand booking revenue and spend in different currencies would otherwise be shown the
  ratio of two incomparable numbers. This is the dashboard's and the truth spine's existing definition;
  Helm does not carry a second one.
- **Surfaces:** `GoalsSection` in the brand **Settings** tab (revenue / ROAS / optional spend cap);
  `PacingCards` on the brand **Overview** (revenue bar + `day N/M · needs €X/day to hit goal`; ROAS vs
  target with `✓ goal hit`). `TargetsCard` deleted — superseded by the two.
- **No goals → no cards at all.** Not an empty state and not a 0% bar: a 0% bar reads as failure, and a brand
  with no goal is not a brand failing its goal. Zero complete days → "—" + amber, and **no bar is drawn**.
  No ad spend → ROAS "—", never 0×.
- **RBAC hole closed:** target writes authorized `view`, meaning a team member attached to a brand could edit
  the goal their own performance is graded against. Now `BrandPolicy::update` (master_admin|manager).

## Inventory: "Meta spend" → "Ad spend" (2026-07-12, D-027)

Inventory Intelligence is a **Shopify page with an ads column**, not an ads page: stock, units,
revenue and sessions are all store truth; ad spend is the cost side, one input.

`InventoryQuery` has always summed `ad_product_daily` with **no platform filter**, and D-021
widened that table to meta + google + tiktok. The UI nonetheless said "Meta spend" and captioned
"Spend & ROAS are **Meta only**" — so any brand running Google or TikTok saw that spend under a
Meta label. The number was right; the label was false.

Kept the all-platform sum and fixed the label: product ROAS is all-channel Shopify revenue ÷
product ad spend, so a **Meta-only denominator would overstate ROAS** on every brand also running
Google or TikTok. Filtering to Meta would have made the caption true and the ROAS permanently
flattering — the worse trade.

- `summary.metaSpend` → **`summary.adSpend`** (same number, honest name).
- New **`spendPlatforms`** — the platforms actually contributing, biggest first. The UI names them
  (`"Meta + Google"`) instead of asserting Meta; with no ad rows it says "ad platforms" rather
  than inventing one.
- Column header → **Ad spend**; `no_spend` action → "No ad spend"; freshness segment → "Ad product
  spend through …".
- The dashboard's own `metaSpend` is a genuinely Meta-only figure and is untouched.

## Sessions by traffic type — Bosco item B (2026-07-12, D-026)

Per-product sessions, split Paid / Direct / Organic / Unknown, on Inventory Intelligence.
Source: ShopifyQL `FROM sessions GROUP BY landing_page_path, traffic_type` — the two dimensions
combine (probe, 2026-07-12, Flabelus), so the full feature was buildable.

- **`session_traffic_daily`** — keyed on (brand, date, **entity_type, entity_key**, traffic_type).
  The landing path is resolved to a product handle / collection handle / `'store-wide'` at SYNC
  time, **not** stored raw. Raw paths have unbounded cardinality: a brand-day holds 2,501–5,000
  distinct rows and the tail is one-off `/checkouts/cn/<token>` URLs. Storing them would write
  ~100M rows/year across the live brands to keep single-session noise.
- **`App\Support\LandingPathMapper`** — the ONE product-handle regex.
  `AdProductFetcher::productHandle()` delegates to it, so a Meta ad's landing URL and a Shopify
  session on the same product can't disagree about the handle and split its numbers in two.
  `/es/products/jay`, `/fr/products/jay` and `/collections/x/products/jay` all resolve to `jay`.
- **`SessionTrafficFetcher` pages, it does not cap.** The spec proposed a top-200-paths-per-day
  cap; measured, a 300-row cap kept only 74.3% of product-page sessions and lost 100% of the
  rest from the TAIL — i.e. exactly the low-traffic products this feature exists to find.
  `OFFSET` works, so it pages until a short page and takes the whole day.
- **Every day reconciles or it is marked incomplete.** The paged sum is compared to a second,
  cheap ShopifyQL call (`GROUP BY traffic_type`, 4 rows = Shopify's own store total). Mismatch →
  `is_complete = false`. **A window with any incomplete or missing day renders "—" for every
  product.** A short row set that looks complete is the failure mode that matters: it would
  under-report a product silently and the table would be sorted by the wrong number.
- **Backfill is day-by-day** (`shopify:backfill-session-traffic`), unlike `backfill-funnel`'s
  month chunks: ShopifyQL's `LIMIT` applies to the whole result set, not per day, so a
  month-ranged limited query returns the busiest days and omits the quiet ones — and each
  omission would read as "no traffic".
- **Writes are an atomic delete+insert per brand-day**, not an upsert: a re-sync can legitimately
  produce fewer rows, and a stale row left standing would keep reporting sessions Shopify no
  longer reports. (`pulled_at` is second-precision, so "delete what I didn't touch" is racy.)
- **The store-wide row is shown, not hidden.** ~51% of a real store's sessions land on the
  homepage, a collection, search or checkout — never on a product page. Sessions are attributed
  by LANDING page, and the UI says so rather than letting the operator assume otherwise.
- **Five traffic types**: paid, direct, organic, unknown, **unattributed**. A 30-day probe
  returned only four and I wrongly concluded `unattributed` didn't exist. Over a FULL YEAR of a
  real store it does: paid 3,117,263 · direct 2,599,142 · unknown 757,967 · organic 457,105 ·
  **unattributed 7** — summing exactly to the 6,931,484 store total. It is 0.0001% of traffic,
  which is why a short sample misses it. **Rare is not absent**: the first cut dropped those rows
  *after* `pagedTotal` was summed, so reconciliation still passed while the stored rows quietly
  summed to less than the store total — the exact silent-loss failure this class exists to
  prevent. A sixth, unrecognised type now marks the day incomplete rather than vanishing.

## GO-2.2 — Budget planner (2026-07-12)

- **`budget_plans`** — one planned spend per (brand, month, platform, country). `country` is **NOT NULL,
  default `''`** meaning "all countries": a nullable column would silently break the unique key, because
  MySQL treats NULLs as distinct and two "all countries" rows could both insert. v1 plans at platform
  level; the country column ships now so GO-4 per-market planning needs no migration on a live table.
- **`App\Services\Rules\BudgetPlanner`** — trailing-90d actual spend → monthly run-rate → the operator's
  plan → the delta. New **Planning** tab (`/brands/:slug/planning`), which is also where GO-3.2's
  Stop/Scale/Fix board will live.
- **DOCTRINE — it is a plan document, not a control surface.** The planner imports **no platform client**
  and has no HTTP path to Meta/Google/TikTok (grep-verified in the proof step). Helm never writes budgets
  to ad platforms; humans execute. The payload carries an `executionNote` rendered on **every** view so no
  operator can assume otherwise.
- **Run-rate honesty:** the rate is computed from the days that actually HAVE data, not from the calendar.
  Ten days of spend is divided by ten, not by ninety — that bug would understate the run-rate 9× and every
  plan built on it would be wrong. A test pins the exact figure (1000 over 10 days → 3100 for a 31-day
  month, not 344).
- ROAS in this grid is **platform-reported** and captioned as such — MER (GO-1.4) remains the honest figure.
  Missing ≠ 0 throughout: no history → null run-rate; an unconnected platform is absent, not a zero row.

## GO-2.3 — Forecast baseline (2026-07-12)

- **Method:** seasonal-naive + drift — `forecast(d) = revenue(d − 1 year) × trend`, where
  `trend = trailing-28d ÷ the same 28 days a year earlier`. Both are named as legitimate benchmark
  methods in Hyndman & Athanasopoulos, fpp3 §5.2. **Zero new dependencies** — pure SQL and arithmetic
  (grep-verified; `composer.json` untouched).
- **THE REFUSAL IS THE FEATURE.** `App\Services\Rules\Forecast` returns `status='insufficient_history'`
  and **no numbers whatsoever** when the brand has fewer than 90 complete days, or when last year covers
  less than 70% of the horizon. An invented forecast looks exactly as confident as a real one — that is
  precisely what makes it dangerous. The card renders the reason and the fix, and nothing else.
- **Both terms are exposed** so any figure can be taken apart: `seasonal` (what the brand actually did on
  that date last year) and a single `trend` multiplier. An absurd trend (near-zero base → 500×) is
  **clamped to 2.0× and disclosed as clamped**, never shipped silently.
- **Gaps in last year are missing, not zero** — they contribute nothing to the total and are surfaced as
  `coverage.missingDays`, so the total is honest about what it omits.
- Every number carries the **`Modeled — baseline forecast (seasonal-naive + trend)`** label (§0 law 1:
  Verified / Proxy / Modeled, never mixed silently). `monthEndProjection()` (actual complete days +
  modelled remainder) feeds pacing and will feed GO-4 plan sizing. Surfaced as `ForecastCard` on the
  Planning tab; thresholds live in `config/forecast.php`.

## GO-2.4 — Anomaly feed (2026-07-12)

- **`anomalies`** — one row per (brand, date, kind, subject). `subject` is NOT NULL, default `''`
  (a product handle or platform; `''` = brand-level), because a nullable column would break the unique
  key under MySQL's distinct-NULLs rule. Indexes on (brand, created_at) and (brand, resolved_at).
- **`App\Services\Rules\AnomalyScanner`** — seven deterministic rules: `cpm_spike`, `cpa_spike`,
  `roas_drop`, `spend_spike`, `zero_delivery`, `stockout_on_ads`, `mer_divergence` (tracking health).
  Thresholds and an `enabled` flag per rule live in `config/anomalies.php` — a noisy rule can be silenced
  without a deploy. **Zero LLM** (grep-verified in the proof step).
- **The six design laws:**
  1. **Median, not mean.** One Black Friday would drag a mean far enough to suppress real alerts for weeks.
  2. **No baseline → no alert.** Below `min_days` (14) complete days, every rule stays *silent*. A confident
     alert computed from three days of noise is a wrong number.
  3. **Evidence always ships** — actual, 28-day median, delta %, and the threshold that fired it — so any
     alert can be re-derived by hand. An alert you cannot verify is one you eventually learn to ignore.
  4. **Idempotent.** Re-scanning a day refreshes the evidence, never duplicates. A feed that repeats itself
     is a feed people stop reading.
  5. **Zero-delivery is critical.** A platform that spent every day and suddenly spent nothing is usually a
     paused campaign, a billing failure, or a broken connection — the one signal you cannot afford to miss.
  6. **Dismissal REQUIRES a reason** (422 without one, enforced in validation, not just the form). That
     reason is the honesty record the GO-3 ledger will score Helm's own suggestions against — without it,
     "dismissed" becomes an unfalsifiable way for the engine to bury its misses.
- **Surfaces:** `anomalies:scan` scheduled **15:30 UTC** (after the 15:00 rolling sync and the 14:10 catalog
  refresh, so the scanned day is as complete as it will get); `AnomalyStrip` on brand detail (evidence inline,
  dismiss-with-reason); `AnomalyBell` in the topbar, which **renders nothing when there is nothing wrong** —
  a permanently-lit bell is a bell nobody looks at. Feed served by a side endpoint; no dashboard engine touched.

## GO-2.5 — THE LEDGER (2026-07-12) — completes GO-2

- **`recommendations`** — every recommendation Helm makes: what was suggested, the evidence cited, whether
  the operator accepted it, and (from GO-3.3) the measured outcome 14/30 days later. This is the
  compounding moat (U2): no third-party tool logs its own advice and scores itself. It ships **SILENT** —
  writers populate it, nothing renders it — because a track record can only be computed from history that
  already exists. **Every night `ledger:record` runs, the moat gets one day deeper.**
- **INSERT-ONLY, enforced by thrown exceptions rather than by comments.** `Recommendation::updating`
  rejects any change outside `MUTABLE` (the status region + the outcome region); `deleting` throws
  unconditionally; an outcome already written cannot be re-graded. `source`, `kind`, `subject`, `title`,
  `evidence`, `confidence` and `baseline_value` are **frozen at insert**. Corrections are a **NEW row**
  pointing at the old one via `supersedes_id`.
  *Why so strict:* a ledger you can quietly tidy is marketing, not evidence. A bad call from March has to
  still be there in June, or the win-rate it produces means nothing.
- **`Services\Ledger\Ledger` is the only write surface** — `record()` (idempotent while advice is still
  open, so nightly re-logging can't dilute the acceptance rate), `transition()` (open → accepted |
  dismissed | expired; **terminal is terminal**; dismissal **requires a reason**), `measure()` (written
  once, by the job, never by hand), `supersede()`.
- **Evidence is mandatory** — an unevidenced recommendation can't be scored, defended or trusted, so
  `record()` throws without it. **`unmeasurable` is an honest outcome** and stays in the denominator;
  silently dropping vanished subjects would flatter the win-rate.
- **`LedgerRecorder`** wires the engines Helm already has (AdAudit actions, AdSetFlags, ProductFlags, open
  anomalies) — a scheduled recorder, *not* a hook inside the engines, because AdAudit runs on every page
  view and would otherwise log the same advice dozens of times a day. Scheduled **15:45 UTC**, right after
  `anomalies:scan`. Anomaly evidence is carried through **verbatim** — same numbers, same median, same
  threshold, nothing re-derived.

**GO-2 (Analyse & plan) is COMPLETE:** targets + pacing · budget planner · forecast baseline · anomaly feed ·
THE LEDGER (silent).

## GO-3.1 — Seasonal-stale creative detector (2026-07-12)

- **The flagship rule:** an ad **still spending money** on copy for a season that is over — Christmas
  creative live in February, `soldes d'hiver` in April, Black Friday hooks in January.
- **`config/seasons.php`** — 10 seasons × **6 languages** (EN/ES/FR/IT/DE/NL), each with a recurring MM-DD
  window. Year-wrap is handled: Christmas runs Nov 15 → **Jan 6**, because Three Kings / Epiphany is the
  primary gift moment in ES and IT, so Christmas creative legitimately runs into early January there.
  `grace_days` (7) and `live_window_days` (7) are config.
- **`App\Services\Rules\SeasonalStale`** — accent- and case-insensitive matching (so `Noël` matches `noel`)
  over `ad_name` + `body_text`, restricted to ads that **actually spent** in the live window. An ad with no
  spend costs nothing and is never flagged.
- **THE INVARIANT: the trigger is a keyword+date RULE, never an LLM** (D-016, master plan §6.1).
  Grep-verified with comments stripped: **zero model references in executable code**. A model may later
  enrich the *prose* of an explanation; it can never be the reason an alert exists. A system that can invent
  a reason to spend a client's attention will eventually invent one.
- **Precision over recall, on purpose.** Ambiguous terms ("sale") are omitted from the keyword lists. A
  missed stale ad costs less than a false alarm that teaches the operator to ignore the badge. Every row
  names the **exact matched words** and the season's end date, so the claim is checkable on sight.
- **Surfaces:** `StaleCreativeCard` on the ads hub (renders nothing when nothing is stale), an `ads` finding
  on the store-audit card, and a `creative_refresh` recommendation in the **ledger**
  (`source: seasonal_stale`, `outcome_metric: spend_waste`) — so from day one the engine is on the hook for
  whether retiring the creative actually saved money.

## GO-3.2 — Stop/Scale/Fix board (2026-07-12)

- **The ledger becomes operable.** `RecommendationBoard` on the **Planning** tab lists open recommendations
  grouped by kind — ordered **money-bleeding-first** (pause → fix → budget_shift → creative_refresh → scale →
  launch → investigate) — with the **evidence expanded on every card**. The operator agrees, or refuses, on
  numbers they can check; never on Helm's authority.
- **ACCEPT RECORDS INTENT AND EXECUTES NOTHING** (doctrine §2). Helm does not pause a campaign, move a budget,
  or contact an ad platform. Two independent guarantees: the controller references **zero platform clients**
  (grep-verified with comments stripped), and a tripwire test does `Http::fake()` + **`Http::assertNothingSent()`**
  on accept — if anyone ever wires that button to the Meta API, the suite goes red.
- **The checklist is the honest part.** Accepting returns the human steps ("pause the campaign yourself in Ads
  Manager, check nothing downstream depends on it, come back and mark it done"), and the board repeats the
  execution note on every render — because the worst possible failure here is an operator *believing* a campaign
  got paused when it didn't.
- **Why accept matters even though it changes nothing:** it timestamps **intent**, and intent is what GO-3.3
  measures the outcome against. "We advised a pause, you agreed, and 30 days later the waste was gone" is a claim
  the ledger can defend. Without the accept step there is nothing to score and the track record cannot exist.
- **Guards:** every transition runs through `Ledger`, so the state machine (**terminal is terminal** — a decided
  recommendation cannot be re-decided once the result is known) and the **required dismissal reason** are enforced
  in one place. Deciding on advice for a client's account is a decision, not a view → **admin/manager only**; an
  attached team_member can read the board but gets a 403 on accept/dismiss.
- Checklists, kind labels and board order live in **`config/ledger.php`** — operational knowledge the people doing
  the work will tune, so it does not belong buried in a component.

## GO-3.3 — Track record, visible (2026-07-12) — U2 becomes real

- **Helm grades itself.** `OutcomeMeasurer` (daily, `ledger:measure`, 16:00 UTC after `ledger:record`) scores
  accepted/dismissed recommendations at **14 and 30 days** against their FROZEN baselines, and expires advice
  nobody ever decided on. `TrackRecord` produces the headline: *"N made · X% accepted · Y% improved the metric."*
- **IT MUST BE ABLE TO SAY HELM WAS WRONG.** Every rule has a real path to `worsened` and to `flat`. A scoring
  function where everything lands in "improved" produces a win-rate that means nothing — a lie with a decimal
  point. Two pieces of honesty carry the whole thing:
  1. **An accepted `pause` that was never actually paused is NOT a win.** If spend kept flowing after the
     operator agreed, the waste was not avoided, and Helm does not get to book a win for advice nobody carried
     out (`pause_spend_drop_pct`; tested → `worsened`). This is the difference between a track record and a
     marketing number.
  2. **`unmeasurable` stays in the denominator.** Campaign deleted, product delisted, subject vanished — those
     are the rows where we could not prove we helped. Recording them honestly costs win-rate; dropping them
     would inflate it.
- **Computed LIVE from ledger rows, never cached** (grep-verified: zero `Cache::` in the calculator or
  controller). A cached number is one someone can freeze on a good week. `improvedPct` is **null, not 0%**,
  before anything is measured — *"no data yet"* and *"0% success"* are very different claims. Expired advice
  stays in the total, because pretending a recommendation was never made would flatter the acceptance rate.
- **The ledger table shows outcomes including the losses.** A track record that only displays its wins is an
  advertisement. Surfaced as `TrackRecordCard` + a filterable full-ledger table on the **Planning** tab;
  `GET brands/{brand}/track-record` and a workspace-wide `GET track-record`.
- A small band (`material_change_pct` 10%) means small moves are recorded as **flat** — noise dressed up as
  skill is how these numbers become worthless.

## GO-3.4 — Competitor gap map (2026-07-12)

- **The join no other tool can make.** `App\Services\AdsLibrary\GapMap` puts **Proxy** competitor presence
  (`ad_library_ads` for the brand's niche → market via `countries`) beside **Verified** own spend
  (`meta_breakdown_daily[breakdown_type=country]`, trailing 30d) and names the gaps: *"3 rivals, 3 live
  concepts in FR — we spend nothing there."* Surfaced on the **Planning** tab; feeds GO-4.
- **The two sides are never mixed** (§0 law 1). The competitor side is **presence only** — concept counts,
  page counts, formats — and **no competitor-spend field exists anywhere in the payload** (grep-verified).
  The EU Ad Library publishes no spend for commercial ads, and Helm will not estimate one. Our side is real
  money, labelled Verified. Both labels ride on every row.
- **Three honest refusals:**
  - **no niche** → `no_niche`. Guessing who a brand's competitors are from its name is precisely the kind of
    invention this product doesn't do.
  - **empty corpus** → `no_corpus`, with the fix (track competitor pages), not a blank map.
  - **no country breakdown** → **`unknown`, not `absent`**. Reporting ignorance as absence would invent a gap
    that may not exist. `ownSpendUsd` is `null`, never `0`.
- **Variant spam is collapsed to concepts.** One rival running four variants of one idea counts as **1**, not
  4 — counting raw ads would overstate how busy a market is (the same failure the Market feed avoids).
- **The caveat renders every time:** a gap is a **question**, not proof. Competitors being in a market means
  they *chose* to be there — which is not the same as it paying off. GO-4 sizes the answer; this only asks.
- ⛔ Live data is gated on the Meta Ad Library ToS/token (Kanwar). Built and tested against a seeded corpus.

## GO-3.5 — Weekly digest (2026-07-12) — completes GO-3

- **In-app is the feature; Slack is optional delivery.** `WeeklyDigest` composes four sections — new
  recommendations, open anomalies, the **track-record delta**, and competitor movement (Proxy-labelled) —
  served at `GET /digest` and rendered as `DigestCard` on the dashboard. `digest:weekly` runs **Monday 08:00
  UTC** and posts to Slack *if* a webhook exists.
- **An honest empty is a feature.** A quiet week renders as a single line — *"quiet week — nothing
  actionable"* — and stops. No padding, no vanity metrics to look busy. A digest that always has something to
  say is one people stop opening, and then the week it actually matters, nobody reads it.
- **It reports what Helm got WRONG.** The track-record section carries `worsenedThisWeek` right beside
  `improvedThisWeek`. An engine that only reports its wins in its own weekly email is running a marketing
  campaign, not a feedback loop.
- **Failure tolerance is deliberate:** **no webhook is not an error** (exit 0 — the Slack install is Kanwar's
  to do, and a missing nice-to-have must never look like a broken cron). Slack down, 500, revoked, or
  rate-limited all log and return `ok:false` **without failing the scheduled run**. A chat integration does not
  get to break the scheduler.
- **`app/Platforms/Slack/SlackClient`** is the only place Slack HTTP lives (adapter guardrail, grep-verified).
  The webhook URL is a secret (Slack revokes leaked ones) → stored in `platform_credentials`, **encrypted at
  rest**, never logged or returned. Settings → Platform keys → Slack has a **Test** button that posts a
  harmless message, so the channel is confirmed *before* a real digest goes out.
- ⛔ **Kanwar gate:** create the Slack app / incoming webhook and paste it into Settings. Until then the digest
  lives in-app only — by design, not by breakage.

**GO-3 (Strategist brain) is COMPLETE:** seasonal-stale detector · Stop/Scale/Fix board · track record (visible) ·
competitor gap map · weekly digest.

## GO-4.1 — EU market calendar (2026-07-12)

- **`market_moments`** — one row per (market, moment, year) across **8 markets** (FR/ES/IT/DE/AT/BE/NL/PL),
  seeded by `calendar:seed {year}`. `kind` separates **`legal_sale`** (fixed by statute) from `gift` and
  `event` (no legal force), because planning around a legal constraint that doesn't exist is its own kind of
  wrong number.
- **Dates are COMPUTED, never typed.** They move: French soldes are fixed **by law** to the 2nd Wednesday of
  January — 2026 → Jan 14, but **2027 → Jan 13**, so a hardcoded date is already wrong one year later. Black
  Friday is the day after the 4th Thursday of November. Mother's Day is a different Sunday in every market.
  `Services\Calendar\MarketCalendar` is pure computation (Easter via the anonymous Gregorian algorithm, no
  new deps) so the maths is directly testable.
- **The traps it encodes — each one costs real money if missed:**
  - **ES/IT: Three Kings (Jan 6) is the *primary* gift moment.** A Spanish campaign that stops on Dec 26 quits
    before the biggest gifting day of the year.
  - **NL: Sinterklaas (Dec 5) competes with — and often beats — Christmas** for gifting.
  - **FR: Mother's Day is LATE MAY** (2026: May 31), not the US/ES date — and it moves to the first Sunday of
    June when it collides with Pentecost (2023 → Jun 4).
  - **DE/AT have NO legally fixed sale periods** (deregulated 2004). SSV/WSV are seeded as `event` with a
    `[HELM DEFAULT]` source, never `legal_sale` — saying otherwise would be false.
- **Every row carries its `source`** (EVZ for legal periods; Trusted Shops for gift dates; Triple Whale for the
  peak window; `[HELM DEFAULT]` where no published standard exists). A calendar entry a human cannot check is
  one they should not plan a client's quarter around.
- **`calendar:seed` is deliberately NOT scheduled.** Dates and laws shift; a silent yearly re-seed would
  propagate a stale assumption into every client plan. It is a yearly action with a human reviewing the output
  (Kanwar-owed, master plan §11.6).
- Every computed date was **independently re-derived before being trusted** — which caught a wrong assertion in
  my own test (I had claimed 2021 as a Pentecost-collision year; it wasn't — the real one is 2023). The test now
  pins both the positive and the negative case.

## GO-4.2 — Playbook physics (2026-07-12)

- **`config/playbooks.php`** holds the nine constants that make a seasonal plan a strategist's plan rather than
  a horoscope: pre-heat starts **T-8 weeks**, creative **locked by T-4** (so nothing sits in learning at peak
  CPMs), campaigns built **≥72h** ahead of launch, **≥5-day** judgment window, **21-day** post-event returning-
  customer phase, **2–4×** budget ramp, CPM stress scenarios at **+0/10/20%**, **≥7** event-ready creatives, and
  email typically carrying **30–40%** of event revenue.
- **Provenance is DATA, not a comment.** Each constant is a `{value, unit, label, source}` structure, because
  GO-4.3 must footnote every number it puts into a client plan — and a source that lives only in a code comment
  cannot be rendered beside the figure it justifies. `PlaybookPhysics::cite()` returns the number *with* its
  citation; that string **is** the plan-block footnote.
- **No number leaves without a source — enforced, not documented.** `get()` **throws** on an empty source, so a
  future edit that adds an unsourced figure turns the suite red instead of quietly shipping an unattributable
  claim into someone's Black Friday deck. An unfootnoted number in a client plan is indistinguishable from an
  invented one, and that is precisely the "generic advice" failure that cost every incumbent its credibility.
- **Two honesty details:** `[HELM DEFAULT]` is stated in plain words where no published standard exists (the
  21-day post-event phase) — pretending to a citation we don't have would be worse than admitting we're guessing.
  And the CPM scenarios explicitly disclose themselves as a **floor, not a forecast**: observed BFCM CPMs run
  **+50–150%**, so modelling +0/10/20% is a *stress test of the margin*. If the CAC ceiling breaks at +20%, it
  will shatter at +100%.

## GO-4.3 — Seasonal plan generator (2026-07-12) — ⛔ awaiting Kanwar's plan review

- **The crown jewel.** `campaign_plans` + `PlanGenerator` produce a per-market, per-moment plan in five blocks —
  **timeline · budget & CAC ceiling · channels · creative · measurement** — assembled from the brand's own
  history, the market calendar (GO-4.1) and the sourced physics (GO-4.2). **Every entry carries its `basis`**
  (Verified / Proxy / Modeled / Source) **and its citation**, so a client can point at any number and ask "where
  is that from?" and get a real answer.
- **Every number is rule-assembled. The LLM never produces one** — grep-verified: zero model references in the
  generator. `PlanNarrator` is the only file that touches a model, it only ever **rewrites**, and the prose is
  stored in a **separate column** from the figures, so a hallucinated number cannot overwrite a real one.
- **The mandatory allowlist audit (§7.3) passes.** The model sees ONLY `label/value/basis/detail`. It is a
  **whitelist**, so adding a field to a plan cannot silently widen what leaves the building — the test injects a
  `customer_email`, a `brand_id` and a `secret` into the blocks and asserts all three are stripped.
- **Two refusals, both tested:**
  1. **Below the data-quality gate → no plan at all** (422, nothing persisted). Confidently planning a client's
     biggest quarter on holey data is the generic-advice failure that killed trust in every incumbent.
  2. **No gross margin → no budget block** (blocked, with the reason, and *zero* fabricated numbers). CAC ceilings
     derive from margin; a guessed ceiling is how an agency talks a client into spending money they never make back.
- **Ledger:** one `playbook` row per **actionable** block (a blocked block is not advice and is not logged as
  such) — so GO-3.3 can eventually answer the question nobody else can: *did the plans we wrote actually work?*
- **Every asserted figure was independently re-derived before being trusted** — which caught an off-by-one-day bug
  in my own fixture (the last-year window is Nov 27–30, not Nov 28–Dec 1). Left unfixed, the "hand-computed" test
  would have passed against quietly wrong numbers.
- ⛔ **KANWAR GATE (§7.3):** *"Kanwar reviews one plan personally before this phase is called done."* Run
  `php artisan migrate && php artisan calendar:seed 2026`, generate a real BFCM plan, and confirm every number
  traces to a table row or a config constant.
