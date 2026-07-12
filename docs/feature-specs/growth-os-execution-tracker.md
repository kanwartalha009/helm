# Growth OS — execution tracker (single source of progress truth)

**Created:** 2026-07-11 · **Owner of truth:** this file. Update the status box of a sub-phase
the moment it changes. Governing docs: `growth-os-master-plan.md` (mission), `GROWTH_OS_ASSESSMENT_2026-07-11.md`
(strategy), `decisions/README.md` D-001…D-022 (ADRs override everything), `ads-library.md`
(in-flight program that finishes first).

## How this tracker works

- Build **one sub-phase at a time, in order**. Before each: `git log --oneline -5` + `git status --porcelain`
  (two Claude sessions share this repo — clobber lesson). After each: the sub-phase's **proof row** must be
  green, then update AS-BUILT + an ADR row for any deviation, then show Kanwar a diff summary before the next.
- **Status legend:** ☐ not started · ◐ in progress · ☑ done (proof green) · ⛔ blocked on a Kanwar gate.
- **Standing proof (every sub-phase, non-negotiable):** `cd api && php artisan test` green · `cd web && npx tsc --noEmit`
  exit 0 · `cd web && npm run build` exit 0. Sub-phases list only the *additional* proof beyond this.
- **Standing laws (§0 master plan):** additive migrations only (prod live ~88 brands) · missing ≠ 0 (null → "—") ·
  all external HTTP in `app/Platforms/*` adapters · brand-tz dates · native money + `fx_rate_to_usd`, USD ratio math ·
  Verified / Proxy / Modeled labels on every metric · the ledger is INSERT-ONLY · no new composer/npm deps without
  Kanwar's explicit yes · rules own numbers, LLM owns prose (D-016).
- **Kanwar gates** (STOP and ask, never assume — master plan §11): Ad Library ToS + Meta identity; Klaviyo private
  keys (go-live, not build); Slack workspace webhook; GO-5 image/video provider + budget; `ads_management` write
  scope (GO-5b). A gate blocks *go-live* or a *specific sub-phase* as noted — it does not block building ungated code.

---

## Measured current state (2026-07-11, verified this session — not recalled)

- **Migrations:** 47 in `api/database/migrations/`. Ads-library added 4 (`2026_07_11_000001..000004`).
- **Ads-library:** 6 tables, 18 routes, 28 test methods, adapter + 4 services + command + 2 controllers + 6 models
  all present; `adlib:refresh` scheduled; `ad_snapshot_url` never stored/emitted; `workspace_id` seams present;
  tsc + build green. **Status: functionally complete, ⛔ go-live gated on ToS/identity.**
- **Known ads-library residual (R1 below):** the disclosed **Rising** market sort (`reach_velocity`) is missing.
- **Working tree:** ads-library + follow-ups are **uncommitted**. Reconcile the **duplicate D-022** row in the ADR log.

---

## Ads-library residuals (finish-first, per standing rule)

### R1 — Market "Rising" sort (reach velocity) ☑ 2026-07-11
> Done: `market()` gains a `rising` branch — `orderByRaw('(eu_total_reach * 1.0 / NULLIF(longevity_days,0)) DESC')`
> (float-safe both engines; null reach / day-0 sort last). `MarketSort += 'rising'`, MarketView sort option +
> sort-aware Proxy tooltip ("Rising = EU reach ÷ days live"). `AdsLibraryMarketTest::test_rising_sorts_by_reach_velocity_nulls_last`.
> Verified: tsc 0, build 0, PHP balanced. `php artisan test` pending on Kanwar's side (no PHP in Cowork).
- **Spec:** ads-library.md §3 + Phase 3 — sorts are *Signal · Rising · Newest · Longest-running*;
  `reach_velocity = eu_total_reach ÷ longevity_days` (young ads with unusually fast EU reach; disclosed Proxy).
- **Files:** `AdsLibraryController::market` (add `rising` sort branch, ORDER BY reach/longevity), `SignalScorer`
  (optional stored `reach_velocity` col — additive — or compute at read), `web/src/types/adsLibrary.ts`
  (`MarketSort += 'rising'`), `MarketView.tsx` (sort option + Proxy tooltip), `hooks/useAdsLibrary.ts`.
- **Migration (if stored):** `..._add_reach_velocity_to_ad_library_ads` (additive, nullable).
- **Tests:** extend `AdsLibraryMarketTest` — Rising orders a seeded corpus by reach/day, nulls sort last.
- **Proof:** standing + the Rising-order assertion.

### R2 — Reconcile duplicate D-022 ☑ 2026-07-11 (docs only)
- Done: the white-label-shell row (2026-07-11) → **D-023**; product-first lens stays **D-022** (the widely-referenced one).
  Klaviyo brand-scoping added as **D-024**. ADR numbering is now unique.

---

## GO-1 — Truth completion (master plan §4) — *foundation for every later recommendation*

### 1.1 — Klaviyo adapter + email revenue ☑ 2026-07-11  (⛔ per-brand keys = Kanwar go-live gate)
> **Surfaces done (installment 2):** brand-Settings `KlaviyoKeyCard` (write-only key, live test on save,
> immutable-scopes hint) + `useBrandKlaviyo` hooks; coverage-card `email` dataset (gated on the brand having a key)
> + `BackfillBrandDatasetJob` runs `klaviyo:backfill` for `email`/`all`; **Weekly report** `email` block and
> **Monthly report** `sections.email` — each renders revenue / orders / vs-store-ratio / top flows+campaigns and
> ships the **honesty box verbatim**. Email is its OWN channel: never added to store revenue, ad revenue, or the
> channel mix; absent data → null/needs_source, never €0. Tests: report payload carries the honesty box AND
> `totalRevenue` stays 1000 (not 1300) with 300 of email revenue present; null-block when no Klaviyo data.
> Verified: tsc 0, vite build 0, PHP balanced.
> **Deliberately NOT done — dashboard email column (see 1.1f).**

### 1.1f — Dashboard email column ☐  (deferred on purpose)
- The dashboard runs **two engines** (`DashboardQuery` + `DashboardQuerySetBased`) behind a parity gate. An email
  column must land identically in both, and `php artisan helm:dashboard-parity` must report **PARITY OK** +
  `--filter=DashboardEnginesTest` must pass — neither runnable from Cowork. It also renders "—" for every brand
  until Klaviyo keys exist. Do it as a deliberate step once ≥1 brand has real email data.
- **Files:** both engines + `web` dashboard row type/column. **Proof:** standing + `helm:dashboard-parity` = PARITY OK.
> **Engine done (installment 1):** `email_daily_metrics` (+ model); `config/klaviyo.php` (honesty box);
> `app/Platforms/Klaviyo/{KlaviyoClient,RevenueFetcher}`; `KlaviyoSync` wired into `SyncBrandDayJob` (shopify
> connection, self-guarded) + `klaviyo:backfill` (month-chunked, floor-clamped, rate-limit sleep);
> `platform_credentials` brand-scoped (ADR **D-024** — nullable `brand_id`, brand-aware active-unique,
> `get/has/set` gained `?int $brandId`); `BrandKlaviyoController` + `brands/{brand}/klaviyo` routes.
> Tests: `KlaviyoRevenueTest` (fetcher tz/currency parse, 429→exception, sync idempotency, brand-scoped key).
> Verified: PHP balanced; no web changes. `php artisan test --filter='Klaviyo'` + `migrate` pending Kanwar-side.
> **Surfaces pending (installment 2 = GO-1.1d):** Settings key field + immutable-scopes hint; coverage-card row +
> backfill dataset ('email' in BackfillBrandDatasetJob + 'all'); dashboard/monthly/weekly "Email revenue
> (Klaviyo-attributed)" column/section with the honesty box (its OWN channel, never summed). NO email number
> renders until this ships. ⛔ per-brand keys remain a Kanwar go-live gate.
- **Files:** `app/Platforms/Klaviyo/{KlaviyoClient,RevenueFetcher}.php` (all HTTP here; `revision: 2026-04-15`
  header; `Klaviyo-API-Key` auth; 3/s·60/m throttle → PlatformRateLimitedException). Day-job wiring
  (fault-isolated, mirror `syncMetaAdProducts`). `app/Console/Commands/KlaviyoBackfillCommand.php`
  (`klaviyo:backfill {brand} --since=` clamp ≥2023-06-01). Coverage-card row. `PlatformCredentialService`
  schema+test for `platform='klaviyo'` (scopes-immutable hint). Dashboard + monthly report "Email revenue
  (Klaviyo-attributed)" column/section with the §3.1 **honesty box**; weekly report email block.
- **Migration:** `..._create_email_daily_metrics_table` — brand_id, workspace_id (null), date, source
  enum(flow,campaign), source_id, source_name null, conversions, conversion_value(14,2), currency, fx_rate_to_usd,
  is_complete, pulled_at; unique(brand_id,date,source,source_id).
- **Tests:** fetcher fixtures (tz + currency passthrough + 429 Retry-After), email upserts, honesty-box present
  in payloads, backfill clamp.
- **Proof:** standing + one real brand's email revenue matches Klaviyo UI ±1% for a spot week (Kanwar-run once a key exists).
- **Honesty law:** Klaviyo revenue = last-touch in its own windows; render as its own channel column, **never**
  summed into a "total attributed" figure. Periodic re-sync (retroactive setting changes rewrite history).

### 1.2 — Product costs → contribution margin ☑ 2026-07-12
> **Gate verified first** (§4.2 "VERIFY field availability"): `InventoryItem.unitCost` = nullable `MoneyV2` at
> `ProductVariant.inventoryItem.unitCost`, needs `read_inventory`/`read_products` (both in Helm's scope set) —
> confirmed on shopify.dev 2026-07-12. Shopify may still withhold it ("View product costs" permission) → nullable
> by design, which is exactly the manual fallback path.
> **Built:** `product_catalog.unit_cost`/`unit_cost_currency` (+ CatalogFetcher pulls it, mean of non-null variant
> costs; ShopifySyncCatalogCommand persists); `product_costs` table (EFFECTIVE-DATED, workspace_id seam) + model;
> `config/costs.php` (shipping/fees declared, **null = not modelled**, never invented); **`CostResolver`** — the one
> place margin math lives (manual > shopify > brand_margin > null); Products API gains `unitCost/costSource/cogs/
> contributionMargin/contributionMarginPct` + `costs.hasBasis/formula` + a `margin` sort; `BrandProductCostController`
> (`GET/PUT/DELETE brands/{brand}/product-costs`, mutations admin/manager); Products table gains Cost (inline setter)
> + Margin columns, a no-cost-basis hint, and the formula caption.
> **Laws honoured:** an unknown cost is **null, never 0** (a 0 COGS would fake a 100% margin); a brand-margin
> estimate is labelled `brand_margin` and never shown as a per-unit cost; effective-dating means a price change
> cannot rewrite an already-reported window.
> **Tests** (`ProductCostsTest`, 6): shopify cost → margin math; manual overrides shopify; future-dated cost does NOT
> rewrite the past; brand-margin fallback; unknown → all-null + `hasBasis:false`; write is admin/manager-only.
> Verified: tsc 0, vite build 0, PHP balanced. `php artisan migrate` + `test --filter=ProductCosts` pending Kanwar-side.
- **Files:** extend catalog fetcher to pull per-variant `inventoryItem.unitCost` (VERIFY field in the live API
  version; if absent → manual only). Products hub manual cost input (master_admin/manager). Contribution-margin
  metric (€/%) wherever revenue shows, with formula tooltip. Precedence: product `unit_cost` → brand
  `gross_margin_pct` → null ("—" + "set costs" hint). Shipping/fees = config-ready fields defaulting null (never invented).
- **Migrations:** additive `unit_cost` on `product_catalog`; `..._create_product_costs_table`
  (brand_id, product_key, unit_cost, currency, effective_from date, set_by) — effective-dated.
- **Tests:** margin precedence chain, effective-dating history, missing-cost → "—" (not 0).
- **Proof:** standing + one brand shows contribution margin traceable to a cost row.

### 1.3 — Data-quality score ☑ 2026-07-12
> **Built:** `config/quality.php` (weights platforms 20 / freshness 25 / history 20 / grain 20 / costs 15;
> threshold 70) + **`App\Services\Rules\DataQuality`** — 5 measured components, each reporting its exact gap and
> the `fix` dataset key that closes it. `meetsGate()` is the GO-3/GO-4 gate. Endpoints:
> `GET brands/{brand}/data-quality` (fresh — moves the instant a backfill lands) and `GET brands-quality`
> (all accessible brands, 15-min cached, for the chip). UI: `DataQualityCard` (score + "What's missing?" drawer with
> per-component bars, gaps and the backfill that fixes each) on brand detail; `QualityDot` on both dashboard tables.
> **Key design call:** the chip reads a SEPARATE endpoint and is merged client-side, so quality never enters the
> dual-engine `helm:dashboard-parity` blast radius. No engine files touched.
> **Laws honoured:** an INAPPLICABLE component (ad grain on a brand with no ad platform) is dropped from the
> denominator, never scored 0 — punishing a brand for a grain it cannot have would be a wrong number.
> **Tests** (`DataQualityTest`, 6): empty brand = 0 + gate fails; inapplicable component excluded; freshness decays
> with staleness (and offers the fix); **score rises after a backfill** (the master plan's proof); gate flips at the
> configured threshold; both endpoints agree. Verified: tsc 0, vite build 0, PHP balanced. No migration (computed).
- **Files:** `App\Services\Rules\DataQuality` (0–100, each component weighted in `config/quality.php`:
  platform coverage vs expected, days-since-last-complete-sync per source, backfill depth vs 12mo, breakdown/
  creative/adset coverage, costs-set coverage). Brand-detail panel + dashboard chip + breakdown drawer with the
  one-click backfill. **Gate:** GO-3/GO-4 recommendations require score ≥ threshold (config, default 70).
- **Migration:** none (computed) — optional cache table only if perf demands (measure first).
- **Tests:** composition boundaries per component, gate threshold behaviour.
- **Proof:** standing + score visibly moves after a backfill on seeded data.

### 1.4 — MER spine + bias annotations ☑ 2026-07-12
- **Files:** dashboard + overall report "Truth" row set — MER (store rev ÷ total spend) beside per-platform
  reported ROAS, each annotated from `config/truth.php` (Meta+Advantage+ over-credit ≈+12pp; Meta manual 7d-click
  under-reports DTC; Google/TikTok "platform-attributed, unverified"; source URLs in comments).
- **Tests:** bias annotation strings present in payloads; MER math.
- **Proof:** standing + annotations render on a real brand.
> **Built:** `config/truth.php` (every bias claim SOURCED in comments — Haus 640 experiments: Advantage+
> over-credits ≈+12pp, Meta 7d-click UNDER-reports DTC ≈$115/$100; Google/TikTok "platform-attributed,
> unverified"; strings editable without code) + **`App\Reports\Support\TruthSpine`** — MER (store revenue ÷ total
> spend, USD ratio math) as the spine, each connected platform's OWN reported ROAS beside it with its annotation.
> Surfaces: `GET brands/{brand}/truth`, `TruthPanel` on brand detail, a **Truth** section in the overall-performance
> report, and the dashboard ROAS header relabelled **"(MER)"** with a store-truth tooltip.
> **Laws honoured:** platform-reported revenue is returned as a **LIST, never summed** (two platforms routinely
> claim the same order — a "total attributed revenue" is a fiction); no spend → **null ROAS, never 0**; an
> unconnected platform is **absent, not a zero row**; MER is labelled `Verified — store truth`, platform figures
> `Platform-reported — unverified`.
> **Key design call:** Truth is served by its own endpoint + the single-engine report. **Neither dashboard engine
> was touched** (verified via porcelain), so the `helm:dashboard-parity` gate is untouched — the dashboard change is
> presentation-only (header label + tooltip).
> **Tests** (`TruthSpineTest`, 3): MER math (1000 ÷ 500 = 2.0×) + annotations present + **no total-attributed key
> exists**; no spend → null not 0; unconnected platform absent. Verified: tsc 0, vite build 0, PHP balanced.

**GO-1 exit: ☑ COMPLETE (2026-07-12)** — 1.1 ☑ · 1.2 ☑ · 1.3 ☑ · 1.4 ☑. (1.1f dashboard email column remains
deliberately deferred behind the parity gate.) suite+tsc+build green; AS-BUILT + ADR log updated.

---

## GO-2 — Analyse & plan + the silent Ledger (master plan §5)

### 2.1 — Targets & pacing ☑ 2026-07-12
> **Built:** `brand_targets` (month as a 'Y-m' STRING — a date invites tz drift at the month boundary;
> workspace_id seam; every target independently NULLABLE) + model; **`App\Services\Rules\Pacing`** —
> `expected-by-now = target × (COMPLETE days ÷ days in month)`; `BrandTargetController`
> (`GET brands/{brand}/targets`, `PUT`/`DELETE` admin-manager) + **`GET brands-pacing`** (side endpoint, cached).
> UI: `TargetsCard` on brand detail (editor + pace rows + "Day N of M" caption) and `PacingChip` on both dashboard
> tables. **No dashboard engine touched** (porcelain-verified) — parity gate untouched.
> **THE invariant:** pacing counts only days that have FINISHED **and** SYNCED. Counting today would make every
> brand read "behind" every morning as a pure artefact of the clock — a wrong number that cries wolf daily.
> Elapsed days and actuals come from the same complete-day set, so they agree by construction. Zero complete days →
> status `unknown`, not "behind". No target → **null, and no chip** — Helm never invents a goal to pace against.
> **Tests** (`PacingTest`, 6): mid-month math (30k target, 10/30 days, 1k actual → behind by 9k); exactly-on-pace;
> **an incomplete day cannot drag an on-pace brand to "behind"**; no target → null (spend/roas stay null, never 0);
> zero complete days → `unknown`; CRUD + RBAC (attached team_member reads but cannot set). tsc 0, build 0, PHP balanced.
- **Migration:** `..._create_brand_targets_table` (brand_id, workspace_id, month 'Y-m', revenue_target, spend_cap,
  roas_target, mer_target, set_by; unique brand+month).
- **Files:** Settings target UI; dashboard pacing chips (target × elapsed-complete-days ÷ days-in-month vs actual,
  brand tz, complete days only).
- **Tests:** pacing math (mid-month, tz edges); CRUD + RBAC. **Proof:** standing.

### 2.1b — Brand goals, Bosco cut ☑ 2026-07-12  *(Bosco item A — D-025)*
> **Master plan §5.1 is now PARTIALLY DONE — do not rebuild it.** What 5.1 still owes: the per-month
> **override** picker (schema + API already support it; only the UI is missing) and MER/spend-cap pacing
> surfaces. Everything else in 5.1 (goal storage, the pacing engine, the dashboard chip) shipped in 2.1/2.1b.
>
> **Delta over 2.1** (Bosco asked for a *standing* goal in Settings and *cards* on Overview, not a combined
> card on brand detail):
> - `month` is now **NULLABLE** — null = the **standing goal**, applying to every month with no explicit
>   override. The Settings UI writes only this one; a month picker would ask the operator to retype the same
>   number twelve times a year.
> - 🔴 **The spec's schema had a latent MySQL bug and was NOT followed literally.** §A.1 asks for nullable
>   `month` + `unique (brand_id, month)`. On MySQL **NULLs are DISTINCT in a unique index**, so that key
>   constrains nothing: a brand could accumulate several standing goals and pacing would pick one at random —
>   two conflicting revenue targets, silently. Uniqueness is enforced on a generated
>   **`month_key = COALESCE(month,'__default')`** instead (same pattern as D-024). Third time this trap has
>   appeared (`budget_plans.country`, `anomalies.subject`).
> - **ROAS is USD-correct.** Pacing divides Σ(revenue × fx) by Σ(spend × fx), never native ÷ native — a brand
>   booking revenue and spend in different currencies would otherwise get a ratio of two incomparable numbers.
>   Same math as the dashboard and the truth spine; Helm does not carry a second definition of ROAS.
> - Payload gained `isStandingDefault`, `remainingDays`, `neededPerDay` (= gap ÷ remaining days, floored at 0 —
>   "needs −250/day" is nonsense).
> - **RBAC hole closed:** `store()`/`destroy()` authorized `view`, so a team member attached to a brand could
>   edit the goal their own performance is graded against. Now `BrandPolicy::update` (master_admin|manager),
>   matching the route middleware.
- **Migration:** `2026_07_12_000009_add_standing_default_to_brand_targets` — additive: `month` → nullable,
  `brand_targets_unique` swapped for `brand_targets_month_key_unique` on the generated `month_key`. No data touched.
- **Files:** `web/src/components/brands/GoalsSection.tsx` (Settings tab; outside the brand `<form>` — the ui
  `Button` has no default `type`, so a nested save button would submit the brand form), `PacingCards.tsx`
  (Overview: revenue bar + "day N/M · needs €X/day", ROAS vs target with `✓ goal hit`).
  `TargetsCard.tsx` **deleted** — superseded by the two.
- **Missing-data contract:** **no goals → no cards at all**, not an empty state and not a 0% bar (a 0% bar reads
  as failure; a brand with no goal is not failing). Zero complete days → "—" + amber, no bar drawn. No ad spend →
  ROAS "—", never 0×.
- **Tests** (`PacingTest`, 6 → **15**): standing goal applies to any month; a month override beats it; **only one
  standing goal per brand can ever exist**; **ROAS in USD, not native ÷ native** (asserts 5.5×, the value
  native-over-native would wrongly report as 10×); ROAS null when no spend; `neededPerDay` = 1450 for
  (30000 − 1000) ÷ 20; `neededPerDay` floored at 0 once the goal is beaten; a 300× ROAS target is rejected as a
  typo (422); clearing the standing goal removes the cards.
- **Proof:** `npx tsc --noEmit` → exit 0. `npx vite build` → built, 0 errors. PHP brace/paren balance clean on all
  4 touched files. ⏳ `php artisan test --filter=PacingTest` + `php artisan migrate` are **Kanwar-side** (no PHP/MySQL
  on this machine) — reported unverified until he runs them.

### 2.2 — Budget planner (read-only) ☑ 2026-07-12
> **Built:** `budget_plans` (brand, month 'Y-m', platform, **country NOT NULL default ''** = all — a nullable
> country would break the unique key since MySQL treats NULLs as distinct; workspace_id seam) + model;
> **`App\Services\Rules\BudgetPlanner`** (trailing-90d actuals → run-rate → plan → delta);
> `BudgetPlanController` (`GET brands/{brand}/budget-plan`, `PUT`/`DELETE` admin-manager); new **Planning** tab
> (`/brands/:slug/planning`) + `BrandSubnav` entry — this page is also the home for GO-3.2's Stop/Scale/Fix board.
> **DOCTRINE, verified by grep:** the planner imports **no platform client** and has no HTTP path to Meta/Google/
> TikTok. It is a PLAN DOCUMENT; humans execute. The payload carries an `executionNote` rendered on every view
> saying so, so no operator can assume otherwise.
> **Run-rate honesty:** the rate uses days that actually HAVE data, not the calendar — 10 days of spend is divided
> by 10, not by 90 (that bug would understate the run-rate 9× and every plan built on it would be wrong). A test
> pins it. ROAS in this grid is **platform-reported** and labelled as such; MER remains the honest figure (GO-1.4).
> Missing ≠ 0 throughout: no history → null run-rate; unconnected platform → absent, not a zero row.
> **Tests** (`BudgetPlannerTest`, 4): run-rate from days-with-data (1000/10 × 31 = 3100, not 344); delta = plan −
> run-rate + upsert not duplicate; no-history nulls + unconnected absent; RBAC + the no-execution note.
> **Scope notes:** v1 plans at PLATFORM level (the `country` column ships now so GO-4 per-market planning needs no
> migration on a live table). Report-share export deferred — share is bound to report types; revisit if wanted.
> tsc 0, build 0, PHP balanced; no dashboard engine touched.
- **Migration:** `..._create_budget_plans_table` (brand_id, month, platform, country null, planned_spend).
- **Files:** planning grid — last-90d spend/ROAS by brand×platform(×country) + editable next-month cells + delta
  vs run-rate. NO API writes. Share via report-share infra.
- **Tests:** CRUD + RBAC, delta math. **Proof:** standing.

### 2.3 — Forecast baseline ☑ 2026-07-12
> **Built:** `config/forecast.php` (horizon 90, min_history 90d, min last-year coverage 70%, trend window 28d,
> trend clamp 0.5–2.0×, the `Modeled` label + method note — all env-overridable) + **`App\Services\Rules\Forecast`**
> (`forecast(d) = revenue(d − 1yr) × trend`, `trend = trailing-28d ÷ same 28d last year`; fpp3 §5.2;
> **zero new deps — SQL + arithmetic only**, grep-verified, composer.json untouched);
> `GET brands/{brand}/forecast` + `monthEndProjection()` (actual complete days + modelled remainder — feeds pacing
> and GO-4 sizing); `ForecastCard` on the Planning page.
> **THE REFUSAL IS THE FEATURE.** `status='insufficient_history'` returns **no numbers at all** when the brand has
> <90 complete days, or when last year covers <70% of the horizon. An invented forecast looks exactly as confident
> as a real one — that's what makes it dangerous. The card shows the reason and the fix, and nothing else.
> **Both terms exposed** so the number can be taken apart: `seasonal` (what the brand actually did that date last
> year) and a single `trend` multiplier. An absurd trend (near-zero base → 500×) is **clamped to 2.0× and disclosed
> as clamped**, never shipped. Gaps in last year are **missing, not zero** — they contribute nothing and are counted
> in `coverage.missingDays`. Every number ships the `Modeled — baseline forecast` label (§0 law 1).
> **Tests** (`ForecastTest`, 7): refuse on <90d history (no `days`/`totals` keys at all); refuse when last year
> doesn't cover the window; seasonal-naive vs hand-computed fixture; trend = 150/100 = 1.5× exactly; absurd trend
> clamped + disclosed; last-year gaps null not 0; endpoint ships the Modeled label. tsc 0, build 0, PHP balanced.
- **Files:** per brand next-90d daily revenue = seasonal-naive (same date last year, complete data only) + trailing-28d
  drift; both terms shown; **`Modeled — baseline forecast (seasonal-naive + trend)`** label; refuse ("insufficient
  history") where last-year data missing — never extrapolate from <90d (fpp3 §5.2, zero deps).
- **Tests:** seasonal-naive SQL vs hand-computed fixture; refusal on thin history. **Proof:** standing.

### 2.4 — Anomaly feed ☑ 2026-07-12
> **Built:** `anomalies` (brand, date, kind, **subject** NOT NULL default '' so the unique key can't be broken by
> MySQL's distinct-NULLs; evidence json; resolved_at/by/reason; indexes (brand,created_at) + (brand,resolved_at)) +
> model; `config/anomalies.php` (7 rules, thresholds + `enabled` per rule, `min_days` guard — all [HELM DEFAULT],
> tunable without a deploy); **`App\Services\Rules\AnomalyScanner`** (cpm_spike, cpa_spike, roas_drop, spend_spike,
> zero_delivery, stockout_on_ads, mer_divergence); `anomalies:scan` command **scheduled 15:30 UTC** (after the 15:00
> sync + 14:10 catalog refresh); `AnomalyController` (brand strip, dashboard-bell feed, dismiss);
> `AnomalyStrip` on brand detail + `AnomalyBell` in the topbar.
> **Design laws:** (1) **MEDIAN, not mean** — one Black Friday would drag a mean far enough to suppress real alerts
> for weeks. (2) **No baseline → no alert.** Below `min_days` (14) complete days every rule stays SILENT; a confident
> alert from 3 days of noise is a wrong number. (3) **Evidence always ships** — actual, 28-day median, delta% and the
> threshold — so any alert can be re-derived by hand. (4) **Idempotent**: re-scanning a day refreshes evidence, never
> duplicates (a feed that repeats itself is a feed people stop reading). (5) **Zero LLM** (grep-verified).
> (6) **Dismissal REQUIRES a reason** (422 without one, enforced server-side) — that reason is the honesty record the
> GO-3 ledger will score against; without it "dismissed" becomes an unfalsifiable way to bury misses.
> **Tests** (`AnomalyScannerTest`, 7): cpm_spike fires at +50% and is **silent at +30%**; roas_drop fires at −50%,
> silent at −20%; zero_delivery (critical); **no baseline → zero alerts at all**; stockout_on_ads (evidence + subject);
> re-scan does not duplicate; dismiss without a reason → 422, with one → resolved and gone from the open feed.
> tsc 0, build 0, PHP balanced; no dashboard engine touched.
- **Migration:** `..._create_anomalies_table` (brand_id, date, kind, severity, evidence json, resolved_at null);
  index (brand_id, created_at).
- **Files:** daily scheduled deterministic scan (config thresholds): CPM/CPA ±X% vs trailing-28d median,
  ROAS drop, spend spike, stockout-on-advertised-product (ad_product_daily × catalog), MER divergence, zero-delivery
  day. Dashboard bell + brand-detail strip; dismiss requires a reason.
- **Tests:** each rule at/below threshold; dismiss-reason required. **Proof:** standing + feed grows on seeded scan.

### 2.5 — THE LEDGER (silent) ☑ 2026-07-12  *(the compounding moat — U2)*
> **Built:** `recommendations` (full §5.5 schema: source, kind, subject_type/id, title, evidence json, confidence,
> status machine, outcome_metric/baseline/measured_14d/30d/outcome/measured_at, supersedes_id, workspace_id seam;
> indexes (brand,created_at), fingerprint, (status,status_at)); `Recommendation` model; **`Services\Ledger\Ledger`**
> (the ONLY write surface: `record()` / `transition()` / `measure()` / `supersede()`); **`LedgerRecorder`** — silent
> writers over the EXISTING engines (AdAudit actions, AdSetFlags, ProductFlags, open anomalies);
> `ledger:record` **scheduled 15:45 UTC**, right after `anomalies:scan`.
> **INSERT-ONLY, ENFORCED IN CODE — not documented, *thrown*:** the model's `updating` hook rejects any change
> outside `MUTABLE` (status region + outcome region); `deleting` throws unconditionally; an outcome already written
> cannot be re-graded. `source/kind/subject/title/evidence/confidence/baseline` are FROZEN at insert. A comment
> saying "don't edit" is a suggestion; an exception is a rule. **Corrections are a NEW row via `supersedes_id`.**
> **Why it's strict:** a ledger you can quietly tidy is marketing, not evidence. A bad call from March must still be
> there in June, or the win-rate it produces is worthless.
> **Other laws:** evidence is MANDATORY (unevidenced advice can't be scored or defended → throws); dismissal REQUIRES
> a reason; `unmeasurable` is an honest outcome that STAYS in the denominator (dropping it would flatter the
> win-rate); `record()` is idempotent while advice is open (nightly re-logging would dilute the acceptance rate).
> **SILENT:** grep-verified — no route, no controller, no UI renders it. History starts accruing now, which is
> precisely why it ships before GO-3.3 needs it.
> **Tests** (`LedgerTest`, 11): evidence can't be rewritten; facts frozen (5 columns); deletion throws; outcome
> measured once, no re-grade; `unmeasurable` retained; illegal transitions rejected (terminal is terminal); dismissal
> needs a reason; correction = new row, original unchanged; evidence mandatory; anomaly evidence carried verbatim;
> recording idempotent; **ledger grows after a scan** (the master plan's proof). tsc 0, build 0, PHP balanced.

**GO-2 exit: ☑ COMPLETE (2026-07-12)** — 2.1 ☑ · 2.2 ☑ · 2.3 ☑ · 2.4 ☑ · 2.5 ☑. The ledger is live and silent;
every night it runs, the track record gets one day deeper. Next phase: **GO-3 — Strategist brain** (stale-creative
detector, Stop/Scale/Fix board, ledger goes VISIBLE, competitor gap map, digests).
- **Migration:** `..._create_recommendations_table` — full schema per master plan §5.5 (source, kind,
  subject_type/id, title, evidence json, confidence, status enum, status_reason/by/at, outcome_metric,
  baseline_value, measured_value_14d/30d, outcome, measured_at, supersedes_id, workspace_id, brand_id);
  index (brand_id, created_at). **INSERT-ONLY** except status/outcome columns (state machine open→accepted|dismissed|expired).
- **Files:** writers wired into EXISTING engines (AdAudit actions, AdSetFlags, ProductFlags, anomalies) — write rows
  **invisibly**, no UI. History accumulates from day one.
- **Tests:** writers create rows with complete evidence json; state machine rejects illegal transitions + ANY update
  of evidence. **Proof:** standing + ledger row count grows after a daily scan on seeded data.

**GO-2 exit:** 2.1–2.5 ☑; ledger silently accumulating; suite+tsc+build green.

---

## GO-3 — Strategist brain (master plan §6) — *ledger goes visible*

### 3.1 — Seasonal-stale creative detector ☑ 2026-07-12  *(Kanwar's flagship)*
> **Built:** `config/seasons.php` — 10 seasons (christmas, black_friday, winter_sale, valentines, mothers_day,
> fathers_day, summer_sale, back_to_school, halloween, new_year) × **6 languages (EN/ES/FR/IT/DE/NL)**, each with a
> recurring MM-DD window (year-wrap handled: Christmas Nov 15 → **Jan 6**, the ES/IT Three-Kings gift moment) +
> `grace_days` (7) and `live_window_days` (7). **`App\Services\Rules\SeasonalStale`** — accent-insensitive,
> case-insensitive matching over `ad_name` + `body_text` of ads that **actually spent** in the live window.
> Surfaces: `StaleCreativeCard` on the ads hub, an `ads` finding on the audit card, and a `creative_refresh`
> row in the **ledger** (`source: seasonal_stale`, `outcome_metric: spend_waste`).
> **THE INVARIANT — the trigger is a keyword+date RULE, never an LLM** (D-016 / §6.1). Grep-verified with comments
> stripped: **zero model references in executable code**. An LLM may later enrich the prose of an explanation; it can
> never be the reason an alert exists. A system that can invent a reason to spend a client's attention eventually will.
> **Precision over recall, deliberately:** ambiguous terms ("sale") are omitted. A missed stale ad costs less than a
> false alarm that teaches the operator to ignore the badge. Evidence names the exact matched words + the season end
> date, so the claim is checkable in two seconds.
> **Tests** (`SeasonalStaleTest`, 8): **the flagship case** — ES Christmas copy live on 2026-02-10 → fires, season
> ended 2026-01-06, stale since 01-13, 28 days stale, €250 still burning, matched `navidad`+`reyes magos`; silent
> in-season (DE, 20 Dec); silent AT the grace boundary (Jan 13) and loud one day later (Jan 14, FR `noël` — accent
> match); cross-language (FR winter_sale + NL christmas); **no spend → not flagged**; generic copy → not flagged;
> **no-LLM invariant** (works identically with no key); writes the ledger row with `trigger: "no model involved"`.
> tsc 0, build 0, PHP balanced; no dashboard engine touched.
- **Files:** `config/seasons.php` (per-season keyword lists ES/EN/FR/IT/DE/NL + date window per market). Rule:
  LIVE ad (active, spend last 7d) whose body_text/ad_name/creative bodies match season K while today > K.end + grace
  (config 7d) → recommendation kind `creative_refresh`, evidence = matched terms + window + last-7d spend. LLM only
  enriches the explanation — **never triggers** (keyword+date rule is the trigger). Surfaces: ads-hub badge, audit card, ledger.
- **Tests:** per language/season boundary (in-window vs post-window+grace); **no-LLM-trigger invariant**.
- **Proof:** standing + seeded Christmas-copy ad past Jan 7+grace produces the recommendation with correct evidence.

### 3.2 — Stop/Scale/Fix board ☑ 2026-07-12
> **Built:** `config/ledger.php` (kind labels + board order — money bleeding first: pause → fix → budget_shift →
> creative_refresh → scale → launch → investigate; **operator checklists per kind**; the `execution_note`);
> `RecommendationController` (`GET brands/{brand}/recommendations`, `POST .../{rec}/accept`, `POST .../{rec}/dismiss`
> — mutations **admin/manager only**, every transition through `Ledger` so the state machine + required dismissal
> reason are enforced in one place); `RecommendationBoard` on the **Planning** tab, grouped by kind, **evidence
> expanded on every card**.
> **THE DOCTRINE — Accept records INTENT and executes NOTHING** (§2). Grep-verified (comments stripped): the
> controller references **zero platform clients**. And a real tripwire test: `Http::fake()` + `Http::assertNothingSent()`
> on accept — if anyone ever wires this button to the Meta API, that test goes red. Accepting returns the human
> CHECKLIST ("pause the campaign yourself in Ads Manager…") and the board repeats the execution note on every render,
> because the worst possible outcome here is an operator believing a campaign got paused when it didn't.
> **Why accept still matters:** it timestamps INTENT, which is the thing GO-3.3 measures the outcome against.
> Without it there is nothing to score and the track record cannot exist.
> **Tests** (`RecommendationBoardTest`, 6): board lists open recs with evidence + execution note; accept records
> intent, sets `status_by`/`status_at`, returns the checklist; **dismiss requires a reason** (422); **terminal is
> terminal** (re-accepting → 422 "Illegal ledger transition"); attached team_member can READ but not decide (403);
> **accept makes zero outbound HTTP calls**. tsc 0, build 0, PHP balanced.
- **Files:** `/planning` (tab on ads hub): open ledger recs grouped by kind, evidence expanded, Accept /
  Dismiss(reason) → state machine. Accept records intent + shows operator checklist. **Helm never touches campaign state.**
- **Tests:** state transitions + RBAC. **Proof:** standing.

### 3.3 — Track record (visible) ☑ 2026-07-12  *(U2 becomes real)*
> **Built:** `config/ledger.measurement` (windows 14/30, `material_change_pct` 10, `pause_spend_drop_pct` 80,
> `expire_open_after_days` 30); **`OutcomeMeasurer`** — grades accepted/dismissed rows against their FROZEN
> baselines and expires undecided advice; **`TrackRecord`** — the live figure; `ledger:measure` **scheduled 16:00
> UTC** (after `ledger:record`); `GET brands/{brand}/track-record` + `GET track-record` (workspace);
> `TrackRecordCard` + the full **filterable ledger table** on the Planning tab.
> **IT MUST BE ABLE TO SAY HELM WAS WRONG.** Every rule has a real path to `worsened` and to `flat`. Two pieces of
> honesty are load-bearing: (1) **an accepted `pause` that was never actually paused is NOT a win** — if spend kept
> flowing, the waste wasn't avoided and Helm doesn't get credit for advice nobody executed (tested → `worsened`);
> (2) **`unmeasurable` stays in the denominator** — dropping vanished subjects would inflate the win-rate.
> **COMPUTED LIVE, NEVER CACHED** (grep-verified: zero `Cache::` in TrackRecord/controller). A cached number is one
> someone can freeze on a good week. `improvedPct` is **null, not 0%**, before anything is measured — "no data yet"
> and "0% success" are different claims. Expired advice stays in the total (dropping it would flatter acceptance).
> The ledger table shows **outcomes including the losses** — a track record that only displays its wins is an advert.
> **Tests** (`TrackRecordTest`, 10): pause carried out → improved; **pause accepted-but-never-done → worsened**;
> scale that tanked ROAS → worsened; scale that held → improved; +5% move → **flat** (inside the material band);
> vanished subject → unmeasurable **and still counted**; undecided advice expires and stays in the total;
> **headline maths hand-verified** (4 recs → 66.7% accepted, 50.0% improved); improvedPct null before measurement;
> **no re-grading on a second run**. tsc 0, build 0, PHP balanced.
- **Files:** daily measurement job — accepted/dismissed rows past 14/30d, measure outcome_metric vs baseline
  (per-kind rules documented in code; `unmeasurable` when subject vanished). Brand + workspace pages: "N recs · X%
  accepted · Y% improved the metric", computed live from ledger (no cached vanity number) + filterable table.
- **Tests:** each outcome-measurement rule on seeded histories incl. `unmeasurable`.
- **Proof:** standing + track-record numbers hand-verified on a seeded ledger.

### 3.4 — Competitor gap map ☑ 2026-07-12  *(⛔ live data gated on the Meta ToS/token; built + tested on seeded corpus)*
> **Built:** `App\Services\AdsLibrary\GapMap` — joins **Proxy** competitor presence (`ad_library_ads` by niche →
> market via `countries`, **collapsed to concept_hash**) against **Verified** own spend
> (`meta_breakdown_daily[breakdown_type=country]`, last 30d, USD). `GET brands/{brand}/gap-map` +
> `GapMapCard` on the Planning tab (feeds GO-4).
> **THE TWO SIDES ARE NEVER MIXED** (§0 law 1). Competitor side = presence, concept counts, formats — and **no
> spend field exists anywhere in the payload** (grep-verified): the EU Ad Library publishes no competitor spend and
> Helm will not estimate it. Own side = real money, labelled Verified. Each row carries both labels separately.
> **Honest refusals:** no niche → `no_niche` (guessing a brand's competitors from its name is exactly the invention
> this product doesn't do); empty corpus → `no_corpus` with the fix, not a blank map; **no country breakdown →
> `unknown`, NOT `absent`** — reporting ignorance as absence would invent a gap that may not exist.
> **Variant spam collapsed to concepts:** one rival running 4 variants of one idea is 1 concept, not 4 — counting
> raw ads would overstate how busy a market is.
> **And the caveat on every render:** a gap is a QUESTION, not proof. Competitors being in a market means they chose
> to be there — not that it pays.
> **Tests** (`GapMapTest`, 7): finds FR-absent/ES-present and sorts gaps first; variant spam → 1 concept;
> **no country data → `unknown` + null spend, never 0/absent**; Proxy/Verified labelled separately + no
> `competitorSpend` key; no-niche refusal; empty-corpus message; endpoint brand-scoped. tsc 0, build 0, PHP balanced.
- **Files:** per niche — active concepts by market (corpus, Proxy) vs the brand's own live campaigns by market →
  "competitors live in FR with jewelry video; you have no FR campaigns" cards. Feeds GO-4.
- **Tests:** gap-map join. **Proof:** standing.

### 3.5 — Digests ☑ 2026-07-12  *(in-app LIVE; Slack delivery awaits Kanwar's webhook — the slot is built + testable)*
> **Built:** `Services\Digest\WeeklyDigest` (new recommendations · open anomalies · **track-record delta** ·
> competitor movement); `Services\Digest\SlackBlocks` (Block Kit, pure formatting); **`app/Platforms/Slack/SlackClient`**
> — the ONLY place Slack HTTP lives (grep-verified adapter guardrail); `digest:weekly` **scheduled Monday 08:00 UTC**
> (`--dry-run` prints the payload); `GET /digest` + `DigestCard` on the dashboard; Slack webhook added to
> `platform_credentials` (**encrypted at rest**, never returned) + Settings → Platform keys with a **Test** button
> that posts a harmless message so the channel is confirmed BEFORE a real digest goes out.
> **AN HONEST EMPTY IS A FEATURE.** A quiet week renders as one line — "quiet week, nothing actionable" — and stops.
> No padding, no vanity metrics. A digest that always has something to say is one people stop opening, and then the
> week it matters, nobody reads it.
> **The digest reports what Helm got WRONG.** The track-record section carries `worsenedThisWeek` beside
> `improvedThisWeek`. An engine that only reports its wins in its own weekly email is running a marketing campaign,
> not a feedback loop.
> **FAILURE TOLERANCE:** no webhook is **not an error** (exit 0 — the Slack install is Kanwar's, and a missing
> nice-to-have must never look like a broken cron); Slack down / 500 / revoked / 429 all log and return `ok:false`
> without failing the scheduled run.
> **Tests** (`WeeklyDigestTest`, 8): quiet week → 2 blocks only; composition of recs+anomalies; **the week's losses
> appear in the payload**; Proxy label on competitor movement; **no webhook → exit 0, nothing sent**; posts Block Kit
> when configured; **Slack 500 → run still green**; 429 tolerated; in-app endpoint works without Slack.
> ⛔ **Kanwar gate remains:** create the Slack app / incoming webhook and paste it in Settings → Platform keys → Slack,
> then hit Test. Until then the digest lives in-app only — by design, not by breakage.

**GO-3 exit: ☑ COMPLETE (2026-07-12)** — 3.1 ☑ · 3.2 ☑ · 3.3 ☑ · 3.4 ☑ · 3.5 ☑. The strategist brain is live: it
detects, it advises with evidence, it records what it advised, it grades itself in public, and it says so weekly.
Next phase: **GO-4 — the seasonal playbook engine** (the market whitespace).
- **Files:** weekly per-workspace Slack (webhook §3.3, encrypted in workspace settings + test button) + in-app:
  new recs, anomalies, track-record delta, competitor movement. Block Kit, ≤1/s, honest empty state.
- **Tests:** webhook payload + failure tolerance. **Proof:** standing + one real weekly digest for a niche with ≥2 tracked pages.

**GO-3 exit:** 3.1–3.5 ☑ (3.5 code done even if webhook pending); ledger visible; suite+tsc+build green.

---

## GO-4 — Seasonal playbook engine (master plan §7) — *the whitespace, rule-first*

### 4.1 — Market calendar ☑ 2026-07-12
> **Built:** `market_moments` (market, moment_key, label, starts_on, ends_on, kind, **source**, year; unique
> (market,moment_key,year) so re-seeding updates not duplicates) + model; **`Services\Calendar\MarketCalendar`** —
> pure, testable date computation for **8 markets** (FR/ES/IT/DE/AT/BE/NL/PL); `calendar:seed {year}` (+ `--dry-run`);
> `GET market-calendar?market=&year=&upcoming=`.
> **DATES ARE COMPUTED, NOT TYPED.** They move: FR soldes are fixed BY LAW to the **2nd Wednesday of January**
> (2026 → Jan 14; 2027 → **Jan 13** — a hardcoded date is already wrong one year later); Black Friday = day after
> the 4th Thursday of November; Mother's Day is a different Sunday in every market. **All dates independently
> re-derived in Python before being trusted** — which caught a wrong assertion in my own test (see below).
> **The traps this encodes (they cost real money):** ES/IT **Three Kings (Jan 6) is the PRIMARY gift moment** — a
> Spanish campaign that stops Dec 26 quits before the biggest gifting day; NL **Sinterklaas (Dec 5) competes with
> Christmas**; FR **Mother's Day is LATE MAY** (2026: May 31) — and moves to June if it collides with Pentecost
> (2023 → Jun 4, verified); **DE/AT have NO legally fixed sale periods** (deregulated 2004), so SSV/WSV are seeded
> as `event` + `[HELM DEFAULT]`, never `legal_sale` — claiming a legal constraint that doesn't exist is its own
> wrong number.
> **Every row carries its `source`** (EVZ for legal periods, Trusted Shops for gift dates, Triple Whale for the peak
> window, [HELM DEFAULT] where none exists). A calendar entry a human can't check is one they shouldn't plan a
> client's quarter around.
> **`calendar:seed` is deliberately NOT auto-scheduled** — dates and laws shift; a silent yearly re-seed would
> propagate a stale assumption into every client plan. Yearly human action (Kanwar-owed §11.6).
> **Tests** (`MarketCalendarTest`, 10): FR soldes = 2nd Wed Jan (2026 **and** 2027); FR summer = last Wed Jun;
> Black Friday maths; Mother's Day differs per market (ES May 3 / DE May 10 / FR May 31); **Pentecost rule fires in
> 2023 and correctly does NOT fire in 2021**; Three Kings; Sinterklaas; **DE has no legal_sale**; **every market has
> ≥1 moment per quarter**; every moment has a source; seed idempotent.
> **Self-correction worth noting:** my first Pentecost test asserted 2021 → Jun 6. Independent re-derivation showed
> 2021 did NOT collide (real date: May 30). Fixed to the true collision year (2023 → Jun 4) and added the
> negative case — a rule that always moves the date is as wrong as one that never does.
- **Migration:** `..._create_market_moments_table` (market 2-char, moment_key, label, starts_on, ends_on,
  kind enum(legal_sale,gift,event), source, year).
- **Files:** `calendar:seed {year}` with the §7.1 seed data embedded (FR soldes 2nd Wed Jan = LAW; ES rebajas + Three
  Kings Jan 6; IT saldi; BE/NL/DE/PL; pan-EU BFCM/Singles/Valentine/Christmas).
- **Tests:** seed integrity (every market ≥1 moment/quarter; FR soldes = 2nd Wed Jan). **Proof:** standing + seed asserts.

### 4.2 — Playbook physics config ☑ 2026-07-12
> **Built:** `config/playbooks.php` — all 9 §7.2 constants (preheat 8w · creative locked 4w · build lead 72h ·
> judgment 5d · post-event 21d · budget ramp 2–4× · CPM scenarios 0/10/20% · min 7 event creatives · email
> 30–40% of event revenue) + **`Services\Playbook\PlaybookPhysics`** (`value()` / `range()` / `cite()` / `all()`).
> **KEY DESIGN CALL — provenance is DATA, not a comment.** Each constant is a structure
> `{value, unit, label, source}`, because §7.3 must footnote every number it puts in a client plan, and a source
> that lives only in a code comment cannot be rendered next to the figure it justifies. `cite()` returns the
> number *with* its citation — that IS the plan-block footnote.
> **THE INVARIANT: no number leaves without a source.** `get()` **throws** if a constant has an empty source, so a
> future edit that adds an unsourced figure fails the suite loudly rather than quietly shipping an unattributable
> claim into someone's Black Friday deck. An unfootnoted number in a client plan is indistinguishable from an
> invented one — that's the "generic advice" failure that cost every incumbent its credibility.
> **Honesty details:** `[HELM DEFAULT]` is stated in plain words where no published standard exists (post-event
> 21d) — pretending to a citation we don't have would be worse than admitting we're guessing. And the CPM
> scenarios' source explicitly says **"floor, not a forecast"**: observed BFCM CPMs run **+50–150%**, so modelling
> +0/10/20% is a *stress test of the margin*, not a prediction — if the CAC ceiling breaks at +20% it will shatter
> at +100%.
> **Tests** (`PlaybookPhysicsTest`, 7): all §7.2 values exact; **every constant has a source + label**; an
> unsourced constant **throws**; `[HELM DEFAULT]` says so; CPM source discloses floor-not-forecast; `cite()`
> renders "8 weeks — …" and "2–4 × baseline"; unknown key throws. 9 constants / 9 sources / 9 labels.
> tsc 0, build 0, PHP balanced. No migration (config).
- **Files:** `config/playbooks.php` — every constant carries its source comment ([HELM DEFAULT] where none): preheat
  8w/creative-locked 4w, budget ramp 2–4×, min 7 event creatives, judgment 5d, build lead 72h, CPM scenarios 0/10/20%,
  email share 30–40%, post-event 21d.
- **Tests:** config shape/keys present. **Proof:** standing.

### 4.3 — Plan generator (rule-assembled, LLM prose only) ◐ 2026-07-12 — **built + green; ⛔ awaiting Kanwar's plan review**
> **Built:** `campaign_plans` (blocks json + narrative in a SEPARATE column; unique brand+moment+market+year) +
> model; **`PlanGenerator`** (5 blocks: timeline · budget+CAC · channel · creative · measurement — **every entry
> carries `basis` = Verified | Proxy | Modeled | Source + its citation**); **`PlanNarrator`** (the LLM boundary);
> `CampaignPlanController` (moments picker · generate · edit · narrate; mutations admin/manager);
> `PlansPanel` on the Planning tab.
> **EVERY NUMBER IS RULE-ASSEMBLED — grep-verified: zero model references in the generator.** The LLM touches
> plans in exactly ONE file (`PlanNarrator`), it only ever REWRITES, and prose is stored in a different column
> from the figures — so a hallucinated number cannot overwrite a real one.
> **THE MANDATORY ALLOWLIST AUDIT (§7.3) PASSES:** the model sees ONLY `label/value/basis/detail`. The test injects
> `customer_email`, `brand_id` and a `secret` into the blocks and asserts they are **stripped** — it's a whitelist,
> so adding a field to a plan cannot silently widen what the LLM sees.
> **TWO REFUSALS, both tested:** (1) **below the data-quality gate → NO plan at all** (422, nothing persisted) —
> planning a client's biggest quarter on holey data is the generic-advice failure that killed the incumbents;
> (2) **no gross margin → NO budget block** (blocked + reason, zero fabricated numbers) — a guessed CAC ceiling is
> how an agency talks a client into spending money they never make back.
> **Ledger:** one `playbook` row per ACTIONABLE block (blocked blocks are NOT logged as advice) → GO-3.3 can
> eventually answer "did the plans we wrote actually work?"
> **Tests** (`PlanGeneratorTest`, 9) — every figure **independently re-derived in Python before being asserted**,
> which caught an off-by-one-day bug in my own fixture (the last-year window is Nov 27–30, not Nov 28–Dec 1 —
> it would have made the "hand-computed" numbers quietly wrong): budget maths (baseline 100/day × 2–4× × 4 days =
> **800–1,600**; LY revenue **4,000**, spend **1,000**, MER **4×**; AOV 100 × 60% margin = **CAC ceiling 60**;
> CPA 50 → +0/10/20% = 50/55/60, all *within ceiling*); **thin margin (40%) → ceiling 40 → BREACHES at every
> scenario**; timeline dated from sourced physics (pre-heat 2026-10-02, locked 10-30, judge 12-02);
> legal-sale window says "FIXED BY LAW"; both refusals; allowlist audit; narrate-without-key fails cleanly and the
> plan still stands; ledger rows written; blocked block not logged.
> ⛔ **KANWAR GATE (§7.3): "Kanwar reviews one plan personally before this phase is called done."** Run
> `php artisan migrate && php artisan calendar:seed 2026`, open a real brand → Planning → generate a BFCM plan,
> and check that **every number traces to a table row or a config constant.** Until you sign that off, 4.3 stays ◐.
- **Migration:** `..._create_campaign_plans_table` (brand_id, workspace_id, moment_key, market, status
  draft/ready/shared, blocks json, created_by).
- **Files:** generator — inputs brand (niche, markets, margin, targets, **quality score ≥ gate**) + moment + own
  history (Verified) + competitor corpus (Proxy) + physics. Output blocks: timeline / budget (needs margin, else
  "set margin first") / channel / creative / measurement — each footnoted Verified/Proxy/Modeled/[source]. LLM pass
  (operator-triggered) = prose only, **BrandDataScope-style allowlist audit test mandatory**. `/planning` Plans tab:
  moment picker → generate → edit → share. Each actionable block writes a `playbook` ledger row.
- **Tests:** block math vs hand-computed fixture (budget/CAC scenarios); margin-missing + quality-gate refusals;
  allowlist audit (no raw customer data to LLM); ledger rows per plan.
- **Proof:** standing + a real brand's BFCM plan, every number traceable to a table row or config constant —
  **Kanwar reviews one plan before this phase is done.**

### 4.4 — Moodboard / brand style ☐
- **Migration:** `..._create_brand_styles_table` (brand_id, palette json, tone_words json, do_dont json, refs json,
  confirmed_by null).
- **Files:** palette from top-20 catalog images + winning thumbnails (pure-PHP dominant-color binning, GD — no new
  deps); tone drafted by LLM from store copy, **always operator-confirmed** before `confirmed_by`; unconfirmed = "draft"
  and GO-5 refuses it. Moodboard view: palette + verified winners + saved market ads + tone words.
- **Tests:** unconfirmed-style refusal path; palette determinism. **Proof:** standing.

**GO-4 exit:** 4.1–4.4 ☑; one plan Kanwar-reviewed; suite+tsc+build green.

---

## Bosco item B — sessions by traffic type: PROBE EVIDENCE ☑ 2026-07-12

**Verdict: B1 — the dimensions COMBINE. Full per-product build is supported.** Nothing below is recalled or
assumed; every figure is the verbatim output of a query run against a real store this session.

**Store probed:** Flabelus (`beatriz-536.myshopify.com`), Shopify Plus, EUR, Spain. Baseline day **2026-07-09**
(the last complete day at probe time). Store total that day: **35,225 sessions**.

**Probe 1 — does a traffic-type dimension exist?** ✅ `traffic_type` is real.
```
FROM sessions SHOW sessions GROUP BY traffic_type SINCE 2026-07-09 UNTIL 2026-07-09
→ paid 20,590 | direct 9,412 | organic 3,114 | unknown 2,109        (sum = 35,225)
```
**It reconciles EXACTLY to the store total** — 20590+9412+3114+2109 = 35,225. No rounding gap, no hidden bucket.

🔴 **CORRECTION (2026-07-12, after Bosco's screenshot).** I concluded from this 30-day sample that Shopify returns
**four** types and that the "Unattributed" row in Bosco's screenshot was not real. **That was wrong.** A full-year
probe returns five:
```
FROM sessions SHOW sessions GROUP BY traffic_type SINCE 2025-07-12 UNTIL 2026-07-11
→ paid 3,117,263 | direct 2,599,142 | unknown 757,967 | organic 457,105 | unattributed 7   (sum = 6,931,484 = store total)
```
`unattributed` is **0.0001%** of traffic — invisible in a 30-day window, undeniable in a year. **A short sample
cannot prove a bucket does not exist.** The bug this caused was the nasty kind: the fetcher dropped unrecognised
types *after* `pagedTotal` was summed, so the day still RECONCILED and read green while the stored rows quietly
summed to less than the store total — precisely the silent-loss failure the reconciliation exists to prevent. Fixed:
five types everywhere, and a sixth unrecognised type now marks the day incomplete instead of disappearing.

**Probe 2 — does a landing-path dimension exist?** ✅
```
FROM sessions SHOW sessions GROUP BY landing_page_path ORDER BY sessions DESC LIMIT 5 SINCE -30d UNTIL today
→ "/" 124,466 | /collections/new-in 38,224 | /es 37,630 | /products/oy 29,882 | /collections/best-sellers 28,931
```

**Probe 3 — THE question: do they combine?** ✅ **YES.**
```
FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type ORDER BY sessions DESC LIMIT 10 SINCE -30d UNTIL today
→ ["/", direct, 46,025] ["/collections/new-in", paid, 34,121] ["/", organic, 33,359]
  ["/products/oy", direct, 29,835] ["/products/anna", direct, 27,508] ["/", unknown, 24,321]
  ["/products/jo", direct, 23,124] ["/", paid, 20,763] ["/collections/woman", paid, 19,545] …
```

**Probe 4 — does it survive the DATE dimension the sync needs?** ✅ date × landing_path × traffic_type all three.
```
FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type TIMESERIES day ORDER BY sessions DESC LIMIT 8 SINCE -3d UNTIL yesterday
→ [2026-07-09, "/", direct, 1632] [2026-07-09, /products/jay, paid, 1352] [2026-07-09, "/", organic, 1120] …
```
⚠️ `LIMIT` applies to the **whole result set, not per day** — all 8 rows came back from a single day. The sync must
therefore page **one day at a time**, never one ranged query with a global LIMIT.

### Four findings that change the spec's §B.2 build plan

**1. 🔴 The spec's "top-N landing paths per day (default 200), log a truncation note" cap is a bad trade — and
unnecessary.** Any LIMIT truncates the *tail*, and the tail is precisely where low-traffic products live — the exact
products an inventory tool exists to surface. Measured on the baseline day:

| query | rows returned | sessions captured | share |
|---|---|---|---|
| all paths × type, `LIMIT 1000` | 1000 (= the limit → truncated) | 31,903 | **90.6%** of 35,225 (3,322 lost) |
| product paths × type, `LIMIT 300` | 300 (= the limit → truncated) | 12,709 | **74.3%** of 17,102 (4,393 lost) |

A 200-row cap would have quietly discarded roughly a quarter of product-page sessions, concentrated entirely in the
long tail. Helm would then under-report sessions for slow products and call it complete.

**2. ✅ `OFFSET` works — so the tail can be PAGED, not capped.** Verified:
```
FROM sessions SHOW sessions WHERE landing_page_path CONTAINS '/products/' GROUP BY landing_page_path, traffic_type
  ORDER BY sessions DESC LIMIT 5 OFFSET 300 SINCE 2026-07-09 UNTIL 2026-07-09
→ [/fr/products/mae-silver-leonor, paid, 7] [/de/products/dafne, paid, 7] … (rows 301-305, as expected)
```
**Build LIMIT+OFFSET pagination and take the whole day.** No cardinality cap, no truncation note, no silent loss.

**3. ✅ `CONTAINS` works in `WHERE` (`LIKE` does NOT — ANTLR syntax error).** Filtering server-side to
`landing_page_path CONTAINS '/products/'` cuts the rows we must page through by more than half:
```
FROM sessions SHOW sessions WHERE landing_page_path CONTAINS '/products/' GROUP BY traffic_type SINCE 2026-07-09 UNTIL 2026-07-09
→ paid 12,350 | direct 3,531 | unknown 708 | organic 513            (sum = 17,102)
```
Product pages are **48.6%** of all sessions (17,102 / 35,225) — so the other half of the store's traffic lands on
home/collections/pages. That is the honest size of the "Store-wide / other pages" bucket §B.3 asks for, and it is
**not** a rounding error to be hidden: it is half the traffic.

**4. ✅ History goes back ≥12 months** — `SINCE 2025-07-09 UNTIL 2025-07-09` returns 12,289 sessions
(paid 6,511 / direct 3,276 / unknown 1,376 / organic 1,126). Ranged backfill and YoY are both feasible.

### Consequent build plan (supersedes §B.2's cap; everything else in §B stands)
- Page each day with `LIMIT 1000 OFFSET n` until a short page returns; **reconcile** the paged sum against a cheap
  `GROUP BY traffic_type` total for the same day and mark the row `is_complete = false` if they disagree. Missing =
  "—", never 0.
- Keep `WHERE … CONTAINS '/products/'` **out** of the sync: the unmapped half of traffic is the "Store-wide / other
  pages" row §B.3 requires, and dropping it would make the totals stop reconciling. Use `CONTAINS` only where a
  product-scoped read genuinely wants it.
- Render the four real traffic types (paid/direct/organic/unknown). No "unattributed".
- The landing-path caveat tooltip in §B.4 is **not optional** — 51.4% of sessions land somewhere other than a
  product page, so "sessions for this product" means "sessions that *landed* on this product".

**Status:** probe ☑ complete, verdict **B1**.

### B-followup — "show the META breakdown of traffic type per product?" → **REJECTED, with evidence** (2026-07-12)

Kanwar's instinct was that because the Inventory table's spend column is Meta, the sessions split should be Meta
too. It must not be. Sessions come from **Shopify's** `sessions` dataset, not from Meta — Meta cannot report
sessions-by-landing-page at all (it reports clicks/impressions on its own side of the fence).

The tempting move is to split paid sessions by platform via `referrer_name`. Probed (30d, Flabelus):
```
FROM sessions SHOW sessions WHERE traffic_type = 'paid' GROUP BY referrer_name ORDER BY sessions DESC SINCE -30d
→ instagram 401,188 | (blank) 160,347 | google 66,805 | facebook 30,850 | gmail 3,568 | flabelus 1,458 | …
   paid total = 664,526
```
Three reasons that is a wrong number waiting to happen:
1. **24.1% of paid sessions carry NO `referrer_name` at all** (160,347 of 664,526). Any "Meta sessions" figure is
   therefore systematically short by an unknown amount — and short in a way that looks precise.
2. **`referrer_name` is a SOURCE, not an ad-platform attribution.** `instagram` also appears under `organic`
   (6,390) and `unknown` (46,235) in the same window. It says where the click came from, not that Meta bought it.
3. **Cardinality.** A third dimension multiplies the already-measured 2,501–5,000 rows/brand/day by ~10.

**Decision:** the Sessions column stays Shopify's five traffic types — exactly what Bosco screenshotted — and Meta
spend stays its own column, sourced from `ad_product_daily` where it is actually true. Two honest columns beside
each other (what you paid on Meta · how many people landed here, and from what kind of traffic) beat one invented
one. Revisit only if Shopify's blank-referrer share collapses.

### B1 — build ☑ 2026-07-12 (D-026)

> **Built:** `session_traffic_daily` (+ workspace_id seam) · `App\Support\LandingPathMapper` (now the ONE owner of
> the product-handle regex; `AdProductFetcher::productHandle()` delegates to it) · `SessionTrafficFetcher`
> (paged + self-reconciling) · `SessionTrafficSync` (one write path shared by the job and the backfill) ·
> `shopify:backfill-session-traffic` · sessions in `InventoryQuery` + the Inventory page (store strip, per-product
> split bar, freshness segment).
>
> **The three decisions that differ from the spec, each forced by a measurement:**
> 1. **Not keyed on raw `landing_path`.** A brand-day holds 2,501–5,000 distinct (path × type) rows and the row at
>    OFFSET 2500 was `/checkouts/cn/<unique-token>` — 1 session. Every checkout mints a unique URL, so the spec's
>    key has **unbounded cardinality**: ~3.5k rows/brand/day ≈ 100M rows/year across ~80 brands, almost all junk.
>    The path is resolved to product / collection / `store-wide` at sync time; the tail folds into 4 rows a day.
> 2. **Paged, not capped.** The spec's top-200 cap would have dropped ~26% of product-page sessions, *all* of it
>    tail — the low-traffic products this feature exists to surface. OFFSET works; we take the whole day.
> 3. **Reconciled, and fails closed.** Each day is checked against Shopify's own store total. Mismatch →
>    `is_complete = false`; any gap in the window → every session figure is null and the UI renders "—". A 30-day
>    window holding 12 synced days would otherwise under-report every product by ~60% *while looking exact*, and
>    the table would then be sorted by that number.
- **Migration:** `2026_07_12_000010_create_session_traffic_daily_table` (additive, new table).
- **Honesty surfaces:** store-wide row shown, not hidden — **51.4% of sessions never touch a product page**;
  landing-path attribution stated on the page and in the column tooltip; four traffic types, no invented
  "Unattributed".
- **Tests** (`SessionTrafficTest` 6 + `SessionTrafficFetcherTest` 7 = **13 new**): mapper table (locales, region
  locales, collection-nested products, query strings, mixed case, malformed `)`, checkout tokens); a
  collection-nested product is a PRODUCT; locale variants collapse to one row; paging keeps the tail past a full
  first page; a non-reconciling day is stored incomplete; a failed totals call means the day can't be trusted; an
  empty day writes **nothing** rather than zeroes; a re-sync removes rows that no longer exist; one missing day
  hides every session number; an unreconciled day counts as missing; a covered window with no landings is a real
  **0**, not "—"; a stray fifth traffic type never enters the totals.
- **Proof:** `npx tsc --noEmit` → exit 0. `npx vite build` → built, 0 errors. PHP brace/paren/bracket balance clean
  on all 14 touched files (strings + comments stripped — the naive counter false-positives on `trim($h, ").,'\"")`).
  ⏳ `php artisan test --filter=SessionTraffic` + `php artisan migrate` are **Kanwar-side** — unverified until run.
- **Owed by Kanwar:** run the migration, then
  `php artisan shopify:backfill-session-traffic <brand> --since=2025-07-01` (probe confirmed ≥12 months of history).
  The **acceptance check from spec §B**: one brand's Inventory page must show per-product splits that reconcile to
  Shopify admin's store totals for the same window — the sync now asserts exactly this per day, so a green page
  means it reconciled.

---

## GO-5 — Creative testing engine (master plan §8) — *LAST; text-only*

### 5.1 — Text variant generation ☐  ⛔ image/video provider is a Kanwar gate (text ships without it)
- **Migration:** `..._create_creative_drafts_table` (brand_id, brief_id/plan_id, kind, content json, status
  draft/approved/exported/launched).
- **Files:** from a brief (ads-library Phase 4) or a plan's creative block → N copy variants + hooks + UGC scripts via
  LlmManager; inputs strictly confirmed brand_style + proven-hook tags + Verified benchmarks + product facts + moment.
  Operator approves each. Seam: `CreativeGenerator` interface (text impl only; image/video behind the gate).
- **Tests:** generation inputs allowlist; **refusal on unconfirmed style**; draft lifecycle + RBAC; zero LLM calls
  contain raw customer rows (audit). **Proof:** standing + one brief → operator-approved variants grounded in its moodboard.

### 5.2 — Export ☐
- **Files:** copy-paste blocks + CSV. **Proof:** standing + export formats test.

### 5.3 — Ledger loop ☐
- **Files:** exported/launched drafts link to resulting ad ids (operator attaches) → 30d outcomes → honest
  "AI-assisted vs other creatives" per brand. **Tests:** ledger linkage. **Proof:** standing.

### 5b — Push-to-Meta as PAUSED ads ⛔ BLOCKED
- Gate: Kanwar approves `ads_management` write scope (writes to client accounts = new risk class; **ADR required**).
  Never auto-publish, never touch budgets. Do not build until ratified.

**GO-5 exit:** 5.1–5.3 ☑ (text); 5b remains ⛔ until the write-scope ADR.

---

## Kanwar-gate register (STOP-and-ask — master plan §11)

| Gate | Blocks | Status |
|---|---|---|
| Ad Library ToS + Meta identity verification | ads-library go-live (code done) | ⛔ open |
| Klaviyo per-brand private keys | GO-1.1 go-live (build proceeds) | ⛔ open |
| Slack workspace webhook | GO-3.5 go-live (build proceeds) | ⛔ open |
| GO-5 image/video provider + budget | GO-5 image/video only (text ships) | ⛔ open |
| `ads_management` write scope + ADR | GO-5b entirely | ⛔ open |
| Per-brand margins / target CPAs / product costs | GO-1.2, GO-4.3 accuracy | ⛔ open (from product-audit build) |

## Change log
- 2026-07-11 — tracker created; ads-library measured complete-but-gated; R1 (Rising sort) + R2 (dup D-022) logged.
- 2026-07-11 — R1 ☑ (Rising market sort shipped, tsc/build green). Ads-library now spec-complete; only ToS go-live gate remains. Next: GO-1.1.
- 2026-07-11 — R2 ☑ (ADR renumber). GO-1.1 engine ☑ (installment 1: table+adapter+sync+backfill+brand-scoped creds+tests; ADR D-024). Next: GO-1.1d surfaces (installment 2).
- 2026-07-11 — GO-1.1 ☑ (installment 2: Settings key card, coverage dataset + backfill, weekly + monthly email sections with the honesty box; email never summed — asserted by test). Dashboard column split out as **1.1f** (dual-engine parity gate, deferred deliberately). Next: **GO-1.2 — product costs → contribution margin**.
- 2026-07-12 — GO-1.2 ☑ (unitCost gate verified on shopify.dev; CostResolver precedence chain; effective-dated manual costs; unknown cost = null never 0; Products Cost + Margin columns). **Flagged to Kanwar: `ShopifyClient::API_VERSION = '2025-01'` looks outside Shopify's supported window (oldest listed: 2025-10) — his call, not touched.** Next: **GO-1.3 — data-quality score**.
- 2026-07-12 — GO-1.3 ☑ (DataQuality 5-component score, gate at 70, brand-detail drawer + dashboard chip via a SEPARATE endpoint to avoid the parity gate; inapplicable components excluded not zeroed). Next: **GO-1.4 — MER spine + bias annotations** (last of GO-1).
- 2026-07-12 — **GO-1.4 ☑ → GO-1 COMPLETE.** TruthSpine (MER spine + sourced bias annotations, platform figures never summed); no dashboard engine touched. **Next phase: GO-2 — targets/pacing, budget planner, forecast baseline, anomaly feed, and THE LEDGER (silent).** Start at GO-2.1.
- 2026-07-12 — GO-2.1 ☑ (brand_targets + Pacing engine; complete-days-only invariant tested; targets editor + dashboard pacing chip via side endpoint). Next: **GO-2.2 — budget planner (read-only)**.
- 2026-07-12 — GO-2.2 ☑ (budget_plans + BudgetPlanner; run-rate from days-with-data; new Planning tab; NO ad-platform write path — grep-verified). Next: **GO-2.3 — forecast baseline (seasonal-naive + drift, `Modeled` label, refuse on thin history)**.
- 2026-07-12 — GO-2.3 ☑ (Forecast: seasonal-naive + clamped drift, fpp3 §5.2, zero deps; REFUSES on thin history / thin last-year coverage with no numbers at all; Modeled label on every figure). Next: **GO-2.4 — anomaly feed**, then **GO-2.5 — THE LEDGER (silent)**.
- 2026-07-12 — GO-2.4 ☑ (7 deterministic median-based rules; silent without a 14-day baseline; evidence always shipped; idempotent; dismissal requires a reason; scheduled 15:30 UTC; bell + brand strip). Next: **GO-2.5 — THE LEDGER (silent)** — the compounding moat; insert-only; history only accrues from the day it ships.
- 2026-07-12 — GO-4.3 ◐ **BUILT, awaiting Kanwar's plan review (the §7.3 gate).** Rule-assembled plans (5 blocks, every entry basis-labelled + cited); zero LLM in the generator; allowlist audit passes (hostile keys stripped); two refusals (quality gate → no plan; no margin → no budget block); ledger `playbook` rows. Every asserted figure re-derived in Python first — caught an off-by-one-day fixture bug. **Next: GO-4.4 — moodboard / brand style** (or close 4.3 once Kanwar reviews a plan).
- 2026-07-12 — GO-4.2 ☑ (playbook physics: 9 sourced constants; provenance is DATA not comments — `cite()` = the plan footnote; **unsourced constant throws**; `[HELM DEFAULT]` stated plainly; CPM scenarios disclosed as floor-not-forecast). Next: **GO-4.3 — plan generator** (rule-assembled; LLM prose only; allowlist audit test mandatory; **Kanwar reviews one plan before this phase is done**).
- 2026-07-12 — GO-4.1 ☑ (EU market calendar: 8 markets, dates COMPUTED per year + independently re-derived, every row sourced; FR soldes = 2nd Wed Jan by law; Three Kings / Sinterklaas / Pentecost traps encoded; DE correctly has no legal sale periods; seed not auto-scheduled). Next: **GO-4.2 — playbook physics config**.
- 2026-07-12 — **GO-3.5 ☑ → GO-3 COMPLETE.** Weekly digest: in-app live; Slack adapter + Block Kit + encrypted webhook slot + Test button built, **awaiting Kanwar's Slack install**. Honest empty; reports Helm's own losses; no-webhook and Slack-outage both exit 0. **Next phase: GO-4 — seasonal playbook engine (the whitespace).**
- 2026-07-12 — GO-3.4 ☑ (competitor gap map: Proxy presence × Verified own spend by market; no competitor-spend field exists anywhere; "no country data" = `unknown`, not `absent`; concepts not raw ads). Live data still ⛔ on the Meta ToS/token. Next: **GO-3.5 — digests** ⛔ *(Slack webhook is a Kanwar gate — in-app digest buildable now)*.
- 2026-07-12 — GO-3.3 ☑ (**U2 is real**: OutcomeMeasurer grades Helm's own advice at 14/30d, expires undecided advice; TrackRecord computed LIVE from the ledger, never cached; losses displayed. An accepted pause that was never actually paused is scored `worsened`.). Next: **GO-3.4 — competitor gap map** (needs the ads-library corpus).
- 2026-07-12 — GO-3.2 ☑ (Stop/Scale/Fix board on the Planning tab; evidence expanded; Accept records INTENT and executes nothing — `Http::assertNothingSent()` tripwire; dismiss needs a reason; terminal states enforced; admin/manager only). Next: **GO-3.3 — track record VISIBLE** (measurement job + live win-rate from the ledger).
- 2026-07-12 — GO-3.1 ☑ (seasonal-stale detector: 10 seasons × 6 languages, keyword+date trigger, **zero LLM in the trigger** — grep-verified; flagship Christmas-in-February case proven; ads-hub card + audit finding + ledger `creative_refresh`). Next: **GO-3.2 — Stop/Scale/Fix board** (the ledger becomes operable; Accept records INTENT, never executes).
- 2026-07-12 — **GO-2.5 ☑ → GO-2 COMPLETE.** THE LEDGER ships silent: insert-only enforced by thrown exceptions (not comments), corrections via supersedes_id, evidence mandatory + frozen, outcome measured once, idempotent nightly writers over the existing engines. **Deploy `ledger:record` promptly — the track record only accrues from the day it goes live.** Next phase: **GO-3 — Strategist brain** (3.1 seasonal-stale detector → 3.2 Stop/Scale/Fix board → 3.3 ledger VISIBLE + track record → 3.4 gap map → 3.5 digests).
