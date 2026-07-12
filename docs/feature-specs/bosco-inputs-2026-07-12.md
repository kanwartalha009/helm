# Bosco inputs — brand goals + sessions by traffic type (2026-07-12)

**Executor:** Opus. **Guardrails:** product-audit-adset-underperformers.md §0 verbatim (additive migrations, missing≠0, brand-tz, fx snapshots, D-005 revenue, sqlite test gotchas, tsc/build gate, D-022 workspace_id seams). Two independent items — build A first (small, certain), B starts with a mandatory data probe.

---

## A. Brand goals in Brand Configuration (Bosco: "Target Monthly Revenue, Target ROAS etc. — add it in brand Settings")

This is the master plan's GO-2 §5.1 pulled forward. Build it AS SPECIFIED THERE so GO-2 doesn't rebuild it — then mark that sub-phase partially done in the execution tracker.

1. **Migration (additive):** `brand_targets` — id, brand_id FK, workspace_id (nullable, D-022), month (string 7 'Y-m' **nullable — null = standing default that applies to every month without an override**), revenue_target (14,2 null, native currency), roas_target (6,2 null), spend_cap (14,2 null), mer_target (6,2 null), set_by_user_id, timestamps; unique (brand_id, month). v1 UI sets ONLY the standing default row (month null); per-month overrides are GO-2's job later — the schema is already ready for them.
2. **Settings tab (brand detail → Settings, where Bosco pointed):** new "Goals" section under the existing fields: Target monthly revenue (native currency input), Target ROAS (e.g. 3.0), optional Monthly spend cap. Helper text: "Used for pacing on the brand overview and dashboard. Leave empty to hide goal tracking." Validation: nullable, numeric ≥ 0; roas_target ≤ 100 sanity. RBAC: master_admin/manager (route gate like backfill-dataset).
3. **Pacing cards (Bosco's mockup):** on brand Overview, two cards when targets exist:
   - *Revenue MTD vs Monthly Target*: `€{mtd} / €{target}`, bar = mtd/target, color = on-pace green vs behind red where on-pace means `mtd ≥ target × (elapsed COMPLETE days ÷ days in month)` — brand tz, complete days only (the existing completeness convention). Show the pace line ("day 12/31 · needs €X/day to hit goal").
   - *ROAS Target*: MTD blended ROAS (D-005 revenue, USD-correct ratio — reuse the dashboard engine's math, do NOT recompute differently) vs target, `✓ goal hit` when ≥.
   - Missing month data → "—" + amber note, never a fake 0% bar. No targets set → cards absent entirely.
4. **API:** extend brand detail payload with `targets {revenueTarget, roasTarget, spendCap, pacing {mtdRevenue, mtdRoas, elapsedDays, daysInMonth, onPace}}`; PATCH via a `brands/{brand}/targets` endpoint (or fold into the existing brand update — follow whichever pattern the margin/target_cpa fields used, they're the precedent).
5. **Dashboard (optional, same pass if cheap):** a small "goal" chip on brand rows where targets exist ("78% to goal · behind"). Skip if it needs dashboard-engine changes — don't touch both engines for a chip.
6. **Tests:** target CRUD + validation + RBAC; pacing math fixtures (mid-month, month boundary, tz edge, missing days → no verdict); payload shape; cards-absent-when-null.
**Proof:** suite+tsc+build green; screenshot of Settings Goals + both pacing cards on a real brand with targets set.

---

## B. Sessions by traffic type at product/collection level (Inventory Intelligence)

Bosco's screenshot is Shopify's STORE-level "Sessions by traffic type" (Paid/Direct/Organic/Unknown/Unattributed). He wants it per product/collection on the Inventory page. **Data honesty first: Helm's funnel sync (`shopify_funnel_daily`) currently stores sessions by country + landing path — traffic TYPE at landing-path grain is NOT yet synced. Do the probe before promising anything.**

1. **PROBE (mandatory first step — same pattern as the customer_type probe in brand-inventory-and-customer-mix-reports.md):** via ShopifyQL against one real store, verify whether the `sessions` dataset supports grouping landing path × traffic-type dimension (candidate dimensions: `referrer_source`, `traffic_type`, `referrer_grouping` — check the current ShopifyQL schema; Shopify's own report groups by "traffic type" so a dimension exists at STORE level; the open question is whether it combines with `landing_page_path`). Record the probe result (exact query + output) in the execution tracker. Three outcomes:
   - **B1 combines** → build the full feature below.
   - **B2 traffic type only store-level** → build a brand-level "Sessions by traffic type" strip on the Inventory page header (still useful context above the product table) + per-product total sessions (no type split) from landing paths; label honestly.
   - **B3 sessions dataset unavailable at needed grain** → report back with the probe evidence and STOP; do not fake it.
2. **Sync (assuming B1):** extend the funnel/session sync with a second grouped pull: date × landing_page_path × traffic_type → new table `session_traffic_daily` (brand_id, workspace_id nullable, date, landing_path (191), traffic_type (24), sessions (unsignedInt), pulled_at; unique brand+date+landing_path+traffic_type). Ranged backfill command + ride the `history` dataset in BackfillBrandDatasetJob when shopify connected + coverage row. Mind ShopifyQL cost throttling — reuse the Throttle pattern; cardinality guard: top-N landing paths per day by sessions (config, default 200) with a logged truncation note (no silent caps).
3. **Mapping:** landing_path → product handle via the exact `/products/{handle}` regex already used by AdProductFetcher (locale prefixes handled the same); `/collections/{handle}` → collection. Unmapped paths (home, pages, blogs) roll up to a "Store-wide / other pages" row so totals still reconcile — never dropped silently.
4. **Inventory Intelligence UI:** per product row, new expandable "Sessions" cell: total sessions in window + mini stacked bar by traffic type (Paid/Direct/Organic/Unknown — same palette idea as Shopify so Bosco feels at home); collection group-mode aggregates the same. Window follows the page's existing period filter. Freshness: sessions data joins the existing dataThrough strip ("Sessions through {date}"). Missing data = "—" + the standard banner, never 0. Caveat tooltip: "Sessions are landing-page attributed — a visitor landing on the homepage then viewing this product is counted under Store-wide, not here." (That's the honest limitation of landing-path attribution; say it, don't hide it.)
5. **Bonus join (only if B1 lands cleanly):** paid sessions per product beside Meta product spend → product-level "paid session → purchase" context. Keep it a display join, no new metrics invented.
6. **Tests:** probe-documented; sync fixture parsing incl. truncation; mapping regex table (locales, query strings, collections, unmapped); UI aggregation per group mode; missing-data rendering.
**Proof:** suite+tsc+build green; one real brand's inventory page showing per-product session splits that reconcile (±ShopifyQL rounding) with Shopify admin's store totals for the same window; probe evidence pasted in the tracker.

---

**Decisions made (don't re-ask):** goals live in brand Settings (Bosco's explicit placement); standing default target v1, per-month overrides deferred to GO-2; pacing uses complete-days convention; traffic-type work is probe-gated with honest fallbacks B2/B3; landing-path attribution caveat is mandatory copy.
**Kanwar/Bosco-owed:** nothing for A; for B, nothing unless the probe hits B3 (then a decision on whether GA4 depth justifies the GO-1 optional adapter).
