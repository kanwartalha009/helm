# Instruction set — Product performance, Store audit cards, Underperformer flags, Ad-set level

**Date:** 2026-07-10 · **Author:** research pass ratified with Kanwar · **Executor:** Claude Opus in Claude Code
**Scope ratified 2026-07-10:** ad-set level for ALL THREE platforms in one go; underperformer flags for BOTH products and ads; product-level ROAS included as its own phase (naming/URL mapping, honest "—" where unmapped).

This document is a complete, phase-wise handover. Each phase has: a plain-language goal, exact files and schemas, the rules with their sources, tests to write, and a "proof of done" the executor must run before calling the phase complete. Work the phases IN ORDER — later phases consume earlier ones.

---

## 0. Non-negotiable guardrails (read before writing any code)

1. **Read first:** `CLAUDE.md` (repo root), `docs/decisions/README.md` (ADR log D-001…D-020), `docs/AS-BUILT.md`. The ADR log overrides the spec docs. The skills in `.claude/skills/` (verified-numbers, clarity-first) apply to you.
2. **Production is live** (~80 real brands since 2026-06-01). Migrations are ADDITIVE ONLY — never drop, rename, or change a column type. New tables and new nullable columns only.
3. **Missing data is never 0.** If a value was not synced, store NULL and render "—" (amber where the UI already does that). Never invent a €0. (This rule caught real bugs twice already — see `audits/REPORTS_AUDIT_2026-07-10.md` R-04.)
4. **All external HTTP lives in `api/app/Platforms/` adapters.** No API calls from controllers, jobs, or report classes.
5. **Dates are brand-timezone** (`$brand->timezone`, fallback UTC). **Money:** store native currency + `fx_rate_to_usd` snapshot on every row; compute ROAS in USD (`col * COALESCE(fx_rate_to_usd, 1)`); display in brand currency.
6. **Revenue = D-005 basis** everywhere: `COALESCE(total_sales,0) + COALESCE(refunds_amount,0)`.
7. **Rules own numbers; LLM writes prose only.** Every badge/flag in this document is deterministic. Nothing here calls the LLM.
8. **Tests:** suite runs on sqlite `:memory:`. sqlite stores cast dates as `Y-m-d 00:00:00`, so exact date matches silently fail — seed daily rows via `DB::table` with date-only strings and query with ranges (`whereBetween`), never exact-date equality. See `tests/Feature/MonthlyReportTest.php:45` for the pattern. Month-key SQL must go through `App\Reports\Support\SqlMonth`.
9. **Frontend gate:** `deploy.sh` aborts on a tsc error and silently serves the OLD bundle. After any type change run `npx tsc --noEmit` in `web/` and `npm run build`.
10. **No new composer/npm dependencies without asking Kanwar.** Everything below is designed to need none.
11. **Queues:** long jobs must keep the chain `job timeout < 3600 (horizon.php supervisors) < 3700 (config/queue.php retry_after)`. If you add a queued job that can run long, set an explicit `$timeout ≤ 3500`.
12. **Every number you show a user must be measured, not estimated.** If a phase needs a number you can't compute yet (e.g. per-account ad-set counts before the first sync), print "pending first sync" — do not guess.

**Deploy note for every phase:** Kanwar deploys via `git pull && bash scripts/deploy.sh` on Cloudways. If you touch anything in `config/`, remind him the deploy needs `php artisan config:cache` (deploy.sh does it — but say so). New scheduled commands need nothing extra (`schedule:run` cron exists). New Horizon queue changes need `php artisan horizon:terminate`.

---

## 1. What exists today (do not rebuild these)

| Piece | Where | State |
|---|---|---|
| Product performance page | `web/src/routes/BrandProductsPage.tsx` + `api/.../BrandProductsController.php` | v1: revenue/orders/units/refunds/share/Δprior from `commerce_daily_metrics` (dimension_type `product`), periods last7/30/90/mtd, search, top-100 |
| Store audit page | `web/src/routes/BrandAuditPage.tsx` + `BrandAuditFindingsController.php` | v1: rearranges 3 existing engines (AdAudit campaign verdicts, DeadInventory, Shopify freshness) into severity cards |
| Campaign rules | `api/app/Reports/Support/AdAudit.php` | ROAS verdicts: <$50 USD spend ⇒ `minor`; ROAS<1.0 ⇒ `dead`; <1.8 ⇒ `weak` (or `scaling_loss` if spend Δ>+20%); ≥3.0 ⇒ `winner`; else `steady` |
| Inventory rules | `api/app/Reports/Support/DeadInventory.php` | `dead` = stock>0 & 0 sold in 90d snapshot window; `slow` = >180 days of cover. Reads `inventory_snapshots` |
| Creative rules | `api/app/Reports/Creative/CreativeReport.php` | fatigue = spend≥$100 USD & prior>0 & (ROAS or CTR −30%); scale = ROAS ≥2× platform median at ≥$50 |
| Ad data granularity | `ad_campaign_daily_metrics` (campaign/day), `ad_creative_daily` (ad/day) | **NO ad-set/ad-group level exists anywhere** — that's Phase 3 |
| Product↔ads mapping (Meta only) | `ad_product_daily` + `MetaBackfillAdProductsCommand` | Maps Meta ad spend → Shopify product **handle** via the ad's landing URL (`/products/{handle}` regex in `AdProductFetcher.php`). Already consumed by InventoryPage |
| Backfill UX | `BackfillBrandDatasetJob` + `BrandDataCoverageController` + `DataCoverageCard` | One tracked job per click; datasets all/history/campaigns/creatives/commerce; `backfill_runs` table |
| Google channel parse | `AdsOverviewQuery::googleChannel()` (read-time name parse) | Convention seen: `NP_<cc>_GADS_<CHANNEL>_…`; `channel_type` column now also synced |

Key schemas you will join against (full column lists in the migrations under `api/database/migrations/`): `commerce_daily_metrics` (unique brand+date+dimension_type+dimension_key), `ad_campaign_daily_metrics` (unique brand+platform+date+campaign_id), `ad_creative_daily` (unique brand+platform+date+ad_id), `inventory_snapshots` (brand+captured_on+dimension_type+dimension_key: ending_units, units_sold, sell_through_rate, window_days=90), `product_catalog` (brand+handle: total_inventory, status, product_type), `ad_product_daily` (brand+date+product_key).

---

## 2. Industry research — the numbers and where they come from

Everything below was researched 2026-07-10 with sources. Two kinds of numbers appear in this document:

- **[SOURCED]** — published by the named source at the URL. Safe to cite to clients.
- **[HELM DEFAULT]** — no published industry standard exists; we set a sensible default, it lives in config so the agency can change it, and the UI must never present it as an "industry benchmark".

### 2.1 Product & store benchmarks

| Metric | Standard / benchmark | Source |
|---|---|---|
| ABC product grading | A = top sellers ≈ 80% of revenue, B = next 15%, C = last 5% (Shopify's own native report definition, graded on last-28-days revenue) | https://help.shopify.com/en/manual/products/inventory/abc-analysis |
| Sell-through rate | `(units sold ÷ stock on hand) × 100`. Healthy 70–80% for in-period assortments; evergreen 40–60% monthly/quarterly acceptable | https://www.shopify.com/blog/sell-through-rate (2025-10-03) |
| Return rate (overall) | 2024: 16.9% of annual retail sales returned (NRF + Happy Returns). 2025: 15.8% overall; **online sales ≈ 19.3% returned** | https://nrf.com/media-center/press-releases/nrf-and-happy-returns-report-2024-retail-returns-total-890-billion · https://nrf.com/media-center/press-releases/consumers-expected-to-return-nearly-850-billion-in-merchandise-in-2025 |
| Return rate (apparel) | 20–40% (footwear 17–30%, accessories/jewelry 12–15%, beauty 4–12%) — compilation citing NRF 2025 Retail Returns Landscape | https://www.richpanel.com/learn/ecommerce-return-rates |
| Repeat customer rate | E-com average 25–30%; Shopify stores ≈ 27%; fashion 20–26% | https://www.mobiloud.com/blog/repeat-customer-rate-ecommerce (2026-03-30) |
| AOV | Global ≈ $145 all industries (Shopify citing Dynamic Yield); paid-ads median $74.12 (Triple Whale, 33k+ brands) | https://www.shopify.com/blog/average-order-value · https://www.triplewhale.com/blog/ecommerce-benchmarks (2026-02-26) |
| Paid-ads medians 2025 | CPA all channels $32.74; Meta CPA $38.19; CTR 1.77%; CVR 2.01% (Triple Whale dataset, $18.4B spend) | https://www.triplewhale.com/blog/ecommerce-benchmarks |
| Weeks of cover | `on-hand units ÷ avg weekly sales`; reorder conversation when a selling SKU is under ~4 weeks of cover | https://www.prediko.io/blog/weeks-of-supply-formula · https://ask-luca.com/blogs/ecommerce-data-analytics |
| Revenue concentration | Keep a single product below ~15% of revenue (practitioner/investor guidance — weak source, treat as info-level only) | https://www.alexanderjarvis.com/what-is-revenue-concentration-risk-in-ecommerce/ |
| Tracking health | Platform-reported revenue vs store revenue variance >10% ⇒ don't trust the numbers, freeze budget changes | https://ask-luca.com/blogs/ecommerce-data-analytics |
| Breakeven ROAS | `AOV × gross margin − CAC = 0` ⇒ breakeven ROAS = AOV/CAC; at 50% margin breakeven = 2.0 (algebraically `1 ÷ gross margin`) | https://www.triplewhale.com/blog/breakeven-roas (2025-12-22) |
| MER / blended | CTC: at 70% gross margin, breakeven blended aMER ≈ 2.0; MER is a store-level metric, "isn't meant to guide media-buying at the ad or campaign level" | https://commonthreadco.com/blogs/coachs-corner/marketing-efficiency-ratio-mer-ecommerce-rating |

**No published standard exists for** "a declining product" (no tool publishes a %-drop threshold — verified against Triple Whale, Polar, Lifetimely, Shopify docs). Our decline rule below is therefore [HELM DEFAULT].

### 2.2 Ads kill/scale/fatigue numbers (practitioner-published)

| Rule | Numbers | Source |
|---|---|---|
| Kill: spend with zero purchases | Pause ad set after spending 1.5× target CPA in last 3 days with 0 purchases (Top Growth); 2.5× target CPL (KlientBoost); statistical version: 0 conversions at 2.3× target-CPA spend = 90% confident it's broken | https://topgrowthmarketing.com/facebook-automated-rules/ · https://www.klientboost.com/facebook/facebook-ads-automated-rules/ · https://admanage.ai/blog/when-to-kill-a-facebook-ad |
| Minimum evidence before judging | Attach "spend > $50" to any CPA pause rule; ~1,000 impressions before judging CTR; data 48–72h old (attribution settles) | https://bir.ch/facebook-automated-rules · https://admanage.ai/blog/when-to-kill-a-facebook-ad |
| Frequency (fatigue) | Keep under 3 (Madgicx); pause/refresh at >4 in a 7-day window (Top Growth) | https://madgicx.com/blog/meta-ads-audit · https://topgrowthmarketing.com/facebook-automated-rules/ |
| CTR floor | Prospecting link CTR consistently <0.5% ⇒ kill/refresh | https://topgrowthmarketing.com/facebook-automated-rules/ · https://admanage.ai/blog/when-to-kill-a-facebook-ad |
| Meta's OWN fatigue statuses | "Creative fatigue" = cost per result ≥ 2× its past level; "Creative limited" = higher but < 2× | https://www.jonloomer.com/creative-fatigue-meta-ads/ (2025-09-15, quoting Meta) |
| Budget scaling | ±10–20%/week (KlientBoost); +20% after 3 consecutive days above target ROAS, −20% after 2 days below breakeven (Top Growth) | as above |
| Budget fragmentation | <$50/day per ad set = fragmentation; consolidate to 3–5 well-funded ad sets; daily budget should be ≥10× the CPA of the optimization event (15–20× is stable) | https://madgicx.com/blog/meta-ads-audit · https://admanage.ai/blog/facebook-ads-audit |
| Meta learning phase | ~50 optimization events within 7 days of last significant edit to exit learning (Meta's help page is robots-blocked; number is secondary-verified via AdManage/Madgicx/WordStream — label it "per Meta guidance" not with a hard citation) | https://admanage.ai/blog/facebook-ads-audit |
| TikTok learning phase | Volatility declines after ~25 results or 7 days (TikTok's OWN help page — primary) | https://ads.tiktok.com/help/article/learning-phase |
| Audience overlap | >20% overlap between ad sets needs attention | https://madgicx.com/blog/meta-ads-audit |

### 2.3 Ad-set level API facts (primary-source verified 2026-07-10)

- **Meta Marketing API v25.0:** Insights `level` enum includes `adset` (https://developers.facebook.com/docs/marketing-api/insights/parameters/). Ad set entity exposes `daily_budget`, `lifetime_budget`, `bid_amount`, `optimization_goal`, `status`, `effective_status`, `budget_remaining`, `targeting` (https://developers.facebook.com/docs/marketing-api/reference/ad-campaign/). `learning_stage_info` type has `status` ∈ {`LEARNING`, `SUCCESS`, `FAIL`} + `conversions` since last significant edit + `last_sig_edit_ts` (https://developers.facebook.com/docs/marketing-api/reference/ad-campaign-learning-stage-info/). Insights rate limits: BUC per ad-account/hour, `ads_insights` standard tier = `600 + 400 × active ads − 0.001 × user errors`; watch the `x-fb-ads-insights-throttle` header (https://developers.facebook.com/docs/graph-api/overview/rate-limiting/ · https://developers.facebook.com/docs/marketing-api/insights/best-practices/).
- **Google Ads (GAQL):** `ad_group` resource supports `metrics.cost_micros, conversions, conversions_value, ctr, average_cpc, search_impression_share, search_budget_lost_impression_share` + `ad_group.status, type, cpc_bid_micros` (https://developers.google.com/google-ads/api/fields/v22/ad_group — repo pins google-ads-php ^33.3 = API V24; verify field availability against V24 before coding, expected identical). **Performance Max has NO ad groups** — Google verbatim: "Performance Max campaigns don't have ad groups or ad objects that you can see" (https://developers.google.com/google-ads/scripts/docs/campaigns/performance-max/using-ads-app); PMax reports at `asset_group` level instead (conversions, conversions_value, cost_micros, clicks, impressions + `asset_group.ad_strength`) (https://developers.google.com/google-ads/api/performance-max/asset-group-reporting). Pagination fixed at 10,000 rows/page since v19.
- **TikTok Business API v1.3:** `/report/integrated/get/` accepts `data_level=AUCTION_ADGROUP` with dimensions `["adgroup_id","stat_time_day"]`; metrics include `spend`, `complete_payment`, `complete_payment_roas`, `purchase` (official SDK repo: https://github.com/tiktok/tiktok-business-api-sdk — portal docs are JS-only). Ad-group human statuses include "Out of ad group budget", "Limited Delivery" (https://ads.tiktok.com/help/article/ad-group-statuses-and-definitions). Helm's `TikTokClient` already handles rate-limit code 40100 with PAGE_SIZE 50.

---

## 3. Ratified Helm rule set (single source of truth)

All thresholds live in **one new config file: `api/config/rules.php`** (env-overridable per value). The UI and any report must read thresholds from this config — never hardcode them in two places. Structure:

```php
return [
    // Products ---------------------------------------------------------
    'product' => [
        'decline_pct'        => (float) env('RULE_PRODUCT_DECLINE_PCT', 30),      // [HELM DEFAULT] revenue drop vs prior equal window
        'decline_floor_usd'  => (float) env('RULE_PRODUCT_DECLINE_FLOOR', 100),   // [HELM DEFAULT] ignore products smaller than this (per window, USD)
        'refund_warn_pct'    => (float) env('RULE_PRODUCT_REFUND_WARN', 15),      // money-based; see caveat below
        'refund_crit_pct'    => (float) env('RULE_PRODUCT_REFUND_CRIT', 25),      // apparel runs 20–40% units-based [SOURCED]
        'cover_low_days'     => (int)   env('RULE_PRODUCT_COVER_LOW', 28),        // stockout risk: <4 weeks cover while selling [SOURCED Luca/Prediko]
        'cover_high_days'    => (int)   env('RULE_PRODUCT_COVER_HIGH', 180),      // matches existing DeadInventory::SLOW_COVER_DAYS
        'concentration_pct'  => (float) env('RULE_PRODUCT_CONCENTRATION', 15),    // single product share, info-level [weakly SOURCED]
        'abc'                => ['a' => 80, 'b' => 95],                            // cumulative revenue share cutoffs [SOURCED Shopify]
    ],
    // Ad sets ----------------------------------------------------------
    'adset' => [
        'min_evidence_usd'   => (float) env('RULE_ADSET_MIN_EVIDENCE', 50),       // no verdict below this spend [SOURCED Bïrch]
        'kill_cpa_mult'      => (float) env('RULE_ADSET_KILL_CPA_MULT', 2.0),     // 0 purchases after spending ≥2× target CPA [SOURCED range 1.5–2.5]
        'frequency_warn'     => (float) env('RULE_ADSET_FREQ_WARN', 4.0),         // 7-day frequency [SOURCED]
        'ctr_floor_pct'      => (float) env('RULE_ADSET_CTR_FLOOR', 0.5),         // [SOURCED]
        'fragment_usd_day'   => (float) env('RULE_ADSET_FRAGMENT', 50),           // avg daily spend under this = fragmentation, info [SOURCED Madgicx]
        'budget_lost_is'     => (float) env('RULE_ADSET_BUDGET_LOST_IS', 0.10),   // Google: ≥10% impression share lost to budget [HELM DEFAULT on a SOURCED metric]
    ],
    // Store-level ------------------------------------------------------
    'store' => [
        'reconcile_warn_pct' => (float) env('RULE_STORE_RECONCILE', 10),          // platform conv value vs store revenue variance [SOURCED Luca]
        'refund_baseline_mult'=> (float) env('RULE_STORE_REFUND_SPIKE', 1.5),     // window refund-rate ≥1.5× trailing-90d baseline [HELM DEFAULT]
    ],
];
```

**Two new brand-level inputs (Phase 0):** `gross_margin_pct` (nullable) and `target_cpa` (nullable, native currency). Breakeven-ROAS and kill-by-CPA rules ONLY activate when these are set; when null, those rules are silently skipped (never guessed). Breakeven ROAS = `1 ÷ (gross_margin_pct/100)` [SOURCED Triple Whale, algebraic].

**Refund-rate caveat (must appear in the UI as a tooltip):** Helm's product refund rate is money-based (`refunds_amount ÷ revenue`) because Shopify's ShopifyQL feed gives refund amounts, not refunded-unit counts per product. Published benchmarks (19.3% online overall, 20–40% apparel) are mostly units/sales-share based — comparable in spirit, not identical. Say "refund rate (by value)" in the UI.

---

## 4. The phases

Order: 0 → 1 → 2 → 3 → 4 → 5 → 6. Phases 1–2 need no new synced data (ship fast). Phase 3 is the big data build. Phase 4–5 consume it.

---

### Phase 0 — Settings & config seam (small, do first)

**Plain language:** before any rule can say "this is below YOUR breakeven", the agency must be able to tell Helm each brand's gross margin and target CPA. This phase adds those two fields and the config file.

Build:
1. Migration (additive): `brands` table + `gross_margin_pct` (decimal 5,2 null) + `target_cpa` (decimal 12,2 null — native currency).
2. `api/config/rules.php` exactly as §3.
3. Brand Settings tab (`BrandDetailPage` → SettingsTab): two labelled inputs with plain-language helper text ("Gross margin = what's left of revenue after product cost, %. Used to compute your breakeven ROAS. Leave empty to skip margin-based flags."). PATCH via the existing brand update endpoint (extend validation: `gross_margin_pct` 1–99 nullable, `target_cpa` ≥0 nullable).
4. Expose both + computed `breakevenRoas` (`gross_margin_pct ? round(100/gross_margin_pct, 2) : null`) in the brand detail API payload.

Tests: update + validation bounds + breakeven math (50% ⇒ 2.0, null ⇒ null).
**Proof of done:** `php artisan test` green; set margin 50 on a brand via UI, API returns `breakevenRoas: 2.0`.

---

### Phase 1 — Underperformer rules engine + Product performance page v2

**Plain language:** the products page grows from a revenue table into the page a merchandiser actually uses: every product gets an A/B/C grade (Shopify's own 80/15/5 method), stock-cover and sell-through columns, and plain-English flags like "Declining", "High refunds", "Stockout risk", "Dead stock". One rules engine produces these flags; the audit cards (Phase 2) and reports reuse the same engine, so a product is never "fine" on one page and "flagged" on another.

Build:

1. **`api/app/Services/Rules/ProductFlags.php`** — new service, pure functions, no HTTP. Input: brand + window (start/end, prior equal window). Reads `commerce_daily_metrics` (product dimension, D-005 revenue), `inventory_snapshots` (latest capture, product dimension), `product_catalog` (current stock, status). Output per product key: 

   - `abc`: 'A'|'B'|'C' — sort window revenue desc, cumulative share ≤80% ⇒ A, ≤95% ⇒ B, else C [SOURCED Shopify]. Only grade when the brand has ≥10 products with revenue in the window (below that, grading is noise — [HELM DEFAULT], show "—").
   - `flags[]`, each `{key, severity, label, detail}`:
     - `declining` (warn): current-window revenue ≥ floor AND prior ≥ floor AND drop ≥ `decline_pct`. Detail: "Revenue −{pct}% vs the previous {N} days."
     - `high_refunds` (warn at ≥refund_warn_pct, critical at ≥refund_crit_pct): money-based rate, window revenue ≥ floor. Detail carries the caveat wording from §3.
     - `stockout_risk` (warn): selling (units>0 in window) AND days of cover < `cover_low_days`. Cover = `ending_units ÷ (units_sold ÷ window_days)` from the latest inventory snapshot. Detail: "About {d} days of stock left at the current sell rate."
     - `dead_stock` (warn) / `slow_mover` (info): reuse `DeadInventory` VERBATIM (call it, don't reimplement — one source of truth).
     - `concentration` (info, brand-level not per-product… emit on the top product only): share ≥ `concentration_pct`. Detail: "This one product is {pct}% of revenue in this window — a supply or ad hiccup on it moves the whole brand."
   - Every flag detail is plain English, no jargon, numbers rounded to 1dp.

2. **Extend `BrandProductsController@index`:** add `sort` param (`revenue|units|delta|refunds|cover`, default revenue), merge per-product `abc`, `flags`, `coverDays`, `sellThroughPct` (from latest snapshot: `sell_through_rate` as %, else null → "—"), `aov` (`revenue ÷ orders`, null when orders 0). Keep MAX_ROWS 100 and the existing shape (additive JSON only — the page already ships).

3. **`BrandProductsPage.tsx` v2:** columns Product · Grade (A/B/C chip, tooltip "Shopify ABC method: A = top ~80% of revenue") · Revenue · Share · vs prior · Orders · Units · AOV · Refund % (by value) · Cover (days, "—" when no snapshot) · Flags (compact colored chips; hover = detail). Sort headers. Row count line: "{n} products · window {start}–{end} · stock snapshot {capturedOn}". Keep the DataCoverageCard and empty states exactly as they are.

4. **Freshness honesty:** if the latest inventory snapshot is older than 3 days, the Cover/Sell-through column header shows "(snapshot {date})" in amber.

Tests (`ProductFlagsTest`): ABC cumulative math incl. <10-product guard; each flag fires exactly at its threshold and not below; floor suppresses tiny products; DeadInventory delegation; sqlite date seeding per guardrail 8; endpoint shape test.
**Proof of done:** `php artisan test` green · `npx tsc --noEmit` + `npm run build` green · screenshot of the page for a real brand with at least one of each flag (seed locally if needed).

---

### Phase 2 — Store audit cards v2

**Plain language:** today the audit page repeats what other pages already say. v2 turns it into the "what needs attention" morning page: revenue trend, refund spikes, whether ad-platform numbers still reconcile with Shopify's, breakeven checks, and the Phase-1 product flags — each as a card that says what happened, why it matters, and what to do, in one sentence each. Rules only, no LLM, same fail-honest behavior as reports.

Build — extend `BrandAuditFindingsController` (keep the existing finding shape `{id, area, severity, title, detail, meta}`; new `area` values: `revenue`, `tracking`, `products`):

1. **Revenue trend card** (`revenue`): window vs prior equal window, D-005 basis from `daily_metrics` (shopify rows). Drop ≥20% ⇒ warn; ≥35% ⇒ critical [HELM DEFAULT — no published standard; the 20% echoes the budget-scaling steps practitioners use]. Growth ≥20% ⇒ `good` card. Fewer complete days than the window needs ⇒ no verdict, emit the existing freshness card instead (never judge partial windows — same gate as reports).
2. **Refund spike card** (`revenue`): window refund rate (money-based, brand-level) ≥ `refund_baseline_mult` × trailing-90-day baseline AND window rate ≥5% ⇒ warn. Detail: "Refunds are {rate}% of revenue this window vs {base}% normally."
3. **Tracking reconciliation card** (`tracking`) [SOURCED Luca, >10%]: compare Σ platform `conversion_value` (all ad platforms, USD) with Σ Shopify revenue (USD) over the window. If ads-attributed revenue > store revenue × (1 + `reconcile_warn_pct`/100) ⇒ warn: "Your ad platforms claim more revenue than the store actually took — attribution is over-counting; don't judge ROAS this window without a pinch of salt." Also the reverse check for pixel undercounting when conversion_value < 20% of store revenue while spend > $500 [HELM DEFAULT] ⇒ info "purchases may be under-tracked."
4. **Breakeven card** (`ads`, only when `gross_margin_pct` set): blended window ROAS (Σ platform conversion_value ÷ Σ spend, USD) vs breakeven (`1÷margin`). Below ⇒ critical "Blended ROAS {x} is under your breakeven {y} — the store loses money on paid traffic at this margin." Within 10% above ⇒ warn. MER context line uses store revenue ÷ spend and cites nothing (MER is store-level only — CTC explicitly warns against using it per-campaign [SOURCED]).
5. **Product flags rollup card(s)** (`products`): from Phase 1 engine — one card per flag type with count + top 3 product names, deep link `/brands/{slug}/products`. (Counts, not 30 cards.)
6. **AOV trend card** (`revenue`, info-only): window AOV vs prior; ±15% ⇒ info card [HELM DEFAULT]. Detail mentions the $74–145 published context ONLY as "for context, paid-ads median AOV is ~$74 (Triple Whale 2025)" — clearly framed as context, not judgment.
7. **Keep every existing card** (freshness, AdAudit verdicts, DeadInventory) — this is additive.
8. **UI (`BrandAuditPage.tsx`):** group cards by area with sticky section headers (Data health → Revenue → Tracking → Ad accounts → Products → Inventory), critical always sorted first within a section; a one-line summary strip at top: "{c} critical · {w} warnings · {g} good" chips that filter on click. No score/grade — scores invite arguing with the number; cards invite action [design decision, matches Shopify's severity-icon approach].

Tests (`AuditFindingsV2Test`): each new card at/below threshold; reconciliation both directions; breakeven skipped when margin null; partial-window suppression; rollup counts.
**Proof of done:** suite + tsc + build green; audit page screenshot for a brand with margin set and one without (breakeven card present/absent respectively).

---

### Phase 3 — Ad-set level data layer (all 3 platforms)

**Plain language:** Helm currently sees campaigns (top level) and individual ads (bottom level) but is blind to the middle layer — Meta ad sets, Google ad groups, TikTok ad groups — which is where budgets, audiences and Meta's "learning" actually live. This phase syncs that layer daily for every brand, backfills 12 months, and stores budget + learning status so Phase 4 can flag "this ad set is budget-starved" or "stuck in learning". This is the largest phase; it touches sync, so it must be gentle on API limits.

**3a. Migration** (one new table, additive):

```
ad_set_daily_metrics
  id, brand_id FK cascade, platform (string 16),
  date (date, brand tz),
  ad_set_id (string 64), ad_set_name (string 255 null),
  campaign_id (string 64 null),
  entity_kind (string 16 default 'ad_set'),   -- 'ad_set' | 'asset_group' (Google PMax)
  status (string 32 null),                    -- platform-native effective status
  learning_status (string 16 null),           -- meta: LEARNING|SUCCESS|FAIL; others null
  daily_budget (decimal 14,2 null),           -- native currency, point-in-time snapshot
  lifetime_budget (decimal 14,2 null),
  spend (decimal 14,2 default 0),
  impressions (unsignedBigInteger default 0), clicks (unsignedBigInteger default 0),
  reach (unsignedBigInteger null), frequency (decimal 8,4 null),   -- meta only; null elsewhere, NEVER 0
  conversions (unsignedInteger default 0), conversion_value (decimal 14,2 default 0),
  search_impression_share (decimal 6,4 null), search_budget_lost_is (decimal 6,4 null),  -- google ad groups only
  currency (string 8 null), fx_rate_to_usd (decimal 14,8 null),
  is_complete (bool default true), pulled_at (timestampTz null), timestampsTz
  UNIQUE ad_set_unique (brand_id, platform, date, ad_set_id)
  INDEX (brand_id, platform, date), INDEX (brand_id, campaign_id, date)
```

Budget/learning/status are **point-in-time snapshots taken at sync time** (the APIs don't give budget history). Rows synced yesterday show yesterday's budget. Document this in the model docblock and show "as of last sync" in any UI that renders budget.

**3b. Fetchers** (all inside existing adapter folders — guardrail 4; mirror the style of the campaign fetchers exactly):

- **Meta** `Platforms/Meta/AdSetFetcher.php`: (1) insights `level=adset`, `fields=adset_id,adset_name,campaign_id,spend,impressions,clicks,reach,frequency,actions,action_values,account_currency`, `time_increment=1`, same `7d_click` attribution + purchase-action priority as `InsightsFetcher` (reuse its helpers). (2) One entity call per account per sync — `{account}/adsets?fields=id,name,effective_status,daily_budget,lifetime_budget,learning_stage_info{status}` — merged onto that day's rows. Meta budgets come back in minor units (cents) — divide by 100; VERIFY against one real account before trusting (protocol: measured, not assumed).
- **Google** `Platforms/Google/AdGroupFetcher.php`: GAQL `SELECT ad_group.id, ad_group.name, ad_group.status, campaign.id, campaign.advertising_channel_type, customer.currency_code, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.search_impression_share, metrics.search_budget_lost_impression_share FROM ad_group WHERE segments.date BETWEEN … AND ad_group.status != 'REMOVED'`. Second query for PMax: `SELECT asset_group.id, asset_group.name, asset_group.status, campaign.id, … metrics FROM asset_group WHERE …` → rows with `entity_kind='asset_group'`, budget columns null (asset groups have no budget). Impression-share metrics are Search-only — expect null elsewhere and store null.
- **TikTok** `Platforms/TikTok/AdGroupFetcher.php`: `report/integrated/get/` with `data_level=AUCTION_ADGROUP`, `dimensions=["adgroup_id","stat_time_day"]`, metrics mirroring the campaign fetcher (`spend, impressions, clicks, complete_payment, total_complete_payment_rate…` — copy the exact metric list from `ReportsFetcher` campaign path, plus `adgroup_name`). Entity budgets/status via `adgroup/get/` (fields incl. `adgroup_id, adgroup_name, budget, budget_mode, operation_status, secondary_status`) merged the same way as Meta.

**3c. Sync + backfill wiring:**
- Extend `CampaignSync::syncDay` (or add `AdSetSync` beside it, called from `SyncBrandDayJob`) to upsert ad-set rows for the synced day — same fx snapshot pipeline as campaign rows.
- New ranged command `ads:backfill-adsets {brand} {--since=} {--platform=}` following `AdsBackfillCampaignsCommand`'s chunking exactly (weekly/monthly ranged pulls — NEVER per-day fan-out).
- Wire into the one-job backfill: `BackfillBrandDatasetJob` — the `campaigns` dataset now also runs `ads:backfill-adsets`; `BrandDataCoverageController` tracks the new table under the campaigns dataset (earliest/latest union of both tables).
- **API-load honesty:** before enabling the daily sync for all brands, run the backfill for ONE real brand and record rows + request counts from the throttle headers in the PR description. Meta's insights budget is per-ad-account (`600 + 400×active ads`/hr standard tier) so adset-level roughly doubles insight calls per account per day (one extra level) — measured numbers go in the PR, not estimates. If any account trips 40100/429, the existing `Throttle` + `PlatformRateLimitedException` classes handle release — reuse them.

**3d. Tests** (`AdSetSyncTest`): fetcher parsing from canned API fixtures per platform (incl. PMax asset-group rows, null frequency for non-Meta, minor-unit budget conversion); upsert idempotency on the unique key; backfill command ranged-chunk boundaries; coverage union logic.

**Proof of done:** suite green · one real brand backfilled on Kanwar's server with row counts reported per platform (`select platform, entity_kind, count(*), min(date), max(date) from ad_set_daily_metrics where brand_id=? group by 1,2`) · daily sync runs for 2 consecutive days without a rate-limit trip (check `sync_logs` + Horizon).

---

### Phase 4 — Ad-set UI + ad-set underperformer flags

**Plain language:** now that Helm can see ad sets, show them where a media buyer looks: click a campaign → see its ad sets with spend, ROAS, budget, learning status, and plain flags ("Budget-starved", "Stuck in learning", "Frequency too high", "Spending with zero sales"). The same flags feed the audit page.

Build:
1. **`api/app/Services/Rules/AdSetFlags.php`** — same shape as ProductFlags. Per ad set over the window (all USD):
   - `no_purchase_kill` (critical): conversions = 0 AND (target_cpa set ? spend ≥ `kill_cpa_mult` × target_cpa : spend ≥ `min_evidence_usd` over ≥3 days) [SOURCED §2.2]. Detail: "Spent {x} with zero purchases — pause or fix targeting/creative."
   - `below_breakeven` (warn; needs margin): ROAS < breakeven with spend ≥ `min_evidence_usd`.
   - `high_frequency` (warn, Meta only): window frequency ≥ `frequency_warn` [SOURCED]. "The same people are seeing this ad {f}× — fatigue territory; refresh creative or widen the audience."
   - `low_ctr` (info): CTR < `ctr_floor_pct` with impressions ≥ 1,000 [SOURCED].
   - `learning_limited` (warn, Meta): learning_status = FAIL. "Meta says this ad set can't gather ~50 optimization events a week — consolidate budget or loosen targeting (per Meta guidance)."
   - `budget_starved` (info): Google `search_budget_lost_is` ≥ `budget_lost_is` [metric SOURCED, threshold HELM] — "Losing {pct}% of possible impressions to budget"; TikTok `secondary_status` contains budget-exceeded; Meta: spend ≈ daily_budget on ≥5 of last 7 days [HELM DEFAULT].
   - `fragmentation` (info, account-level, emit once): ≥4 active ad sets averaging < `fragment_usd_day`/day [SOURCED Madgicx] — "Budget is spread across {n} small ad sets — consider consolidating to 3–5 (industry guidance)."
   - Verdict gate: NO flag of any kind below `min_evidence_usd` spend except `budget_starved`/`learning_limited` (status-based, not performance-based).
2. **Endpoint:** `GET brands/{brand}/ads/campaigns/{campaign}/adsets?period=` → rows + flags + `asOf` (latest pulled_at). Register in `api/routes/api.php` inside the `access.brand` group next to the existing campaign route.
3. **UI:** inside `AdsCampaignDrawer.tsx`, an "Ad sets" section: table Name · Status · Learning (Meta chip) · Budget/day ("as of {date}") · Spend · ROAS · CPA · Freq (Meta, "—" elsewhere) · Flags (chips, hover detail). Google PMax campaigns render asset groups with an "Asset group" tag and a one-line note "PMax has no ad groups — these are its asset groups (Google reports them instead)." Empty state: "No ad-set rows yet — run the backfill on the brand page."
4. **Audit integration:** `BrandAuditFindingsController` consumes `AdSetFlags` rollups (counts + worst offenders per platform) as `ads`-area cards. Weekly report's action list may cite ad-set flags (optional, only if trivial).

Tests: each flag at/below threshold, evidence gate, fragmentation counting, endpoint shape + RBAC (reuse the team_member-attached 403 pattern from `DataCoverageTest`).
**Proof of done:** suite + tsc + build green; drawer screenshot with real ad sets and at least one flag; audit page shows the rollup card.

---

### Phase 5 — Product-level ROAS (ads → product mapping)

**Plain language:** "which ads sell THIS product?" Meta already answers it in Helm through ad landing-page URLs. This phase widens that to Google and TikTok where possible, and puts an honest Ad spend / ROAS pair on the products page. Where Helm can't tell which product an ad sells, it shows "—" and says why — it never guesses.

Build:
1. **Reuse, don't rebuild:** `ad_product_daily` + `AdProductFetcher` (Meta, landing-URL `/products/{handle}` regex) is the working pattern and already feeds InventoryPage.
2. **Google** `Platforms/Google/AdProductFetcher.php`: GAQL on `ad_group_ad` final URLs (`SELECT ad_group_ad.ad.final_urls, metrics.cost_micros, segments.date FROM ad_group_ad WHERE …`) → same handle regex → upsert into `ad_product_daily`. **Verified 2026-07-10: the table has NO `platform` column and its unique key is `(brand_id, date, product_key)`** — additive migration required: add `platform` (string 16, default 'meta' so existing Meta rows are labelled correctly), then add a new unique index `(brand_id, platform, date, product_key)` and drop only the old unique INDEX (index swaps don't touch data — allowed; document it in the migration comment). Update `AdProductFetcher`'s upsert + `InventoryQuery`'s join to include platform. Shopping/PMax spend cannot be URL-mapped (product feeds, no final URL per product) → those campaigns' spend stays UNMAPPED, never smeared across products.
3. **TikTok**: ad entity `landing_page_url` via `ad/get/` → same regex.
4. **Naming-convention fallback (registry, OFF by default):** `api/app/Services/Rules/CampaignNameParser.php` — per-workspace regex list mapping campaign-name tokens → product handle (seeded empty; Settings UI textarea "one rule per line: PATTERN => handle"). Only applies to spend not already URL-mapped. Every parsed match stores `source='name'` vs `source='url'` so the UI can mark confidence. (The existing `NP_<cc>_GADS_<CHANNEL>` convention carries channel, not product — so this ships as a seam, not a promise.)
5. **Products page columns:** Ad spend (window, mapped only) · ROAS (product revenue ÷ mapped spend). Footer line, always visible: "{pct}% of ad spend in this window is mapped to products via landing URLs — unmapped spend (e.g. Google Shopping/PMax) is excluded, so product ROAS reads HIGH. Blended truth lives on the dashboard." Compute pct as mapped spend ÷ total spend from `daily_metrics`.
6. **Flag:** `losing_on_ads` (warn): mapped spend ≥ $100 USD AND product ROAS < breakeven (margin set) or < 1.0 (margin unset) [HELM DEFAULT floors, SOURCED breakeven].

Tests: URL regex table (locales `/en-de/products/x`, query strings, non-product URLs → null); unmapped-spend exclusion math; footer pct; Google fetcher fixture.
**Proof of done:** suite + tsc + build green; products page for a Meta-mapped brand showing spend/ROAS on mapped rows and "—" + footer on the rest.

---

### Phase 6 — Verification & rollout (do not skip)

1. Full suite, `npx tsc --noEmit`, `npm run build` — all green on the final tree.
2. Threshold audit: grep the diff for every numeric literal — each must exist in `config/rules.php` or cite a §2 source in a comment. No orphan numbers.
3. Missing-data sweep: for one fresh test brand with zero synced data, open products page, audit page, ad-set drawer — everything must render "—"/empty states, zero `0`s or NaNs, no 500s.
4. Load honesty: report measured sync duration + API request counts for the ad-set daily sync across all brands on day 1 and day 2 (from Horizon + throttle headers). If day-1 backfill pressure trips limits, stagger brand backfills via the existing queue rather than raising process counts.
5. Deploy checklist for Kanwar: `git pull && bash scripts/deploy.sh`; new config file ⇒ verify `config:cache` ran; new queued work ⇒ `php artisan horizon:terminate` and confirm restart; then per brand: set gross margin + target CPA in Settings, click "Backfill everything" (now includes ad sets), watch Sync health.
6. Update `docs/AS-BUILT.md` + add an ADR row if any decision here was changed during implementation.

---

## 5. Decisions already made (do not re-ask Kanwar)

| Decision | Answer |
|---|---|
| Ad-set platforms | All three (Meta ad sets, Google ad groups + PMax asset groups, TikTok ad groups) in one phase |
| Flag targets | Both products and ads |
| Product ROAS | In scope, own phase (5), URL-mapping first + naming-parser seam, honest unmapped handling |
| LLM in rules | Never — rules own numbers, LLM is prose-only elsewhere (D-016) |
| Thresholds | Central `config/rules.php`, env-overridable; [SOURCED] vs [HELM DEFAULT] distinction preserved in comments and UI |
| Score/grade on audit page | No overall score — severity cards only |
| New dependencies | None permitted without asking |

## 6. Open items the executor must raise WITH Kanwar (not decide alone)

1. Per-brand gross margin values and target CPAs — only he/Bosco know them (Phase 0 unblocks, values come later; rules stay silently off until set).
2. Whether TikTok is live for any brand yet (adapter is built but was untested live as of 2026-06-18 — `php artisan tiktok:diagnose` first; if no BC token, TikTok paths ship dark and that's fine).
3. Naming-convention rules for the Phase-5 parser (agency-specific).
4. If measured Meta API load from Phase 3 exceeds comfortable headroom on big accounts, whether to sync ad-set level daily for all brands or top-N spenders + on-demand for the rest.
