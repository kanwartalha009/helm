# Claude Code — Build: Shopify line-item data + Country & Product reports (slice 2.1)

Paste into Claude Code in the Helm repo. Builds **slice 2.1 only**. Slice 2.0
(the report engine) must already be merged — this adds the commerce data layer and
two report types that plug into it. Read first:

1. `docs/feature-specs/reporting-and-creative-intelligence.md` (§2 catalog, §4.4 commerce data, §8 slices) and `docs/feature-specs/reporting-delivery-plan.md` (slice 2.1 tasks).
2. `AGENTS.md`, `docs/decisions/README.md` (MySQL, live prod, additive-only), `docs/05-platforms/shopify.md`, and `docs/feature-specs/` for the revenue definition.
3. Existing code: `app/Platforms/Shopify/RevenueFetcher.php` (already uses **ShopifyQL** on API `2026-04` via `SHOPIFYQL_API_VERSION`; `netSalesByDay` / `salesByDayRange` are the pattern to extend), `app/Console/Commands/ShopifyBackfillSalesCommand.php` and `AdsBackfillSpendCommand.php` (backfill patterns — monthly-chunked, additive upsert), `app/Reports/*` (the 2.0 engine), `app/Models/DailyMetric.php`.

## What 2.1 is

The commerce **data layer** at line-item granularity, plus two report types built
on it: **Country** and **Product Performance** (= Bosco's "Shopify audit"). Today
Helm stores only brand-total ShopifyQL revenue; these reports need by-country and
by-product/category/price-point data with YoY.

## NOT in 2.1

No ad/creative data, no naming parser, no live creative dashboard, no LLM. Those
are 2.2–2.3. Reuse the 2.0 white-label engine and template — do not rebuild it.

## Data source (extend the existing ShopifyQL approach)

Prefer ShopifyQL `GROUP BY` dimensions over raw order pagination where possible
(it matches `RevenueFetcher` and is far cheaper at 100-brand scale). Verify exact
dimension names against the pinned `2026-04` API before relying on them.

- **By country / day:** ShopifyQL `FROM sales SHOW total_sales, net_sales, orders GROUP BY day, <country dimension> WHERE sales_channel = 'Online Store'`. Confirm the country dimension (shipping vs billing) and match how the example country report attributes it.
- **By product / category / day:** ShopifyQL grouped by product and product_type (category). Plus a **Products API** pull for catalog metadata not in ShopifyQL: `created_at` (= launch date), `product_type` (category), variant price (price point bucket).
- **New vs returning, discount vs full-price:** ShopifyQL customer-type and discount dimensions if available; otherwise derive. Flag which are available vs derived.
- **Refunds** by product/country as ShopifyQL exposes them.

## Backend (`api/`)

- Extend `app/Platforms/Shopify/` with ranged grouped fetchers (e.g. `CommerceFetcher` or methods on `RevenueFetcher`) — **all Shopify calls stay inside `Platforms/Shopify/`** (no Guzzle elsewhere).
- Schema (additive migrations, MySQL, non-destructive):
  - `commerce_daily_metrics` (brand_id, date, dimension_type [`country`|`product`|`category`], dimension_key, dimension_label, orders, units, net_sales, total_sales, refunds, discount_amount, currency, fx_rate_to_usd, is_complete, pulled_at) — one flexible table, or split per dimension if cleaner. Unique on (brand_id, date, dimension_type, dimension_key).
  - `products` catalog (brand_id, external_id, title, category, launch_date, price, price_bucket, first_seen) for the new-design/launch analysis.
- `php artisan shopify:backfill-commerce {brand?} {--since=2025-01-01}` — monthly-chunked, additive upsert, never clobbers existing `daily_metrics`. ≥13 months for YoY. Mirror the existing backfill commands (resolveBrands lenient match, span output, fx snapshot per day).
- Report types: `app/Reports/Country/CountryReport.php` and `app/Reports/Product/ProductPerformanceReport.php` implementing the 2.0 `ReportType` contract; register in `config/reports.php`. Each `build()` returns the render-ready payload (KPIs, tables, classifications, price-point buckets, climbers/drops, slowest-sellers with skip/monitor/keep, new-design review).
- Period + comparison reuse the 2.0 filter params and the existing comparison-window logic in `DashboardQuery`.

## Frontend (`web/`)

- Render Country and Product reports through the **2.0 white-label engine/template** — add report-specific section components (country table + classification matrix; product table, price-point bars, winners, slowest-sellers, insight blocks) under `web/src/components/reports/`. Same theme, filters, editable commentary, Export PDF, share link as 2.0.
- Now that country data exists, add the **top-markets section to the Overall Performance report** (it was deferred in 2.0).

## Conventions (non-negotiable)

- Locked stack; no new dependency without asking.
- Every table a migration; additive / non-destructive (live prod, ~80 brands).
- Native currency + `fx_rate_to_usd` at write time; brand-timezone dates; missing ≠ zero (a market or product with no data renders "—", never 0).
- Don't overload `daily_metrics` — granular commerce lives in the new tables; `daily_metrics` stays the brand×platform×day rollup.
- `tsc --noEmit` clean; update `mockApi.ts` for shared types. PHPUnit smoke tests for the two report builders + the backfill upsert (asserts non-destructive: a commerce backfill never changes a Shopify `daily_metrics` row).
- Explain non-obvious decisions plainly; name the spec section each piece implements; end with a deploy note (migrations + `shopify:backfill-commerce` run order) and the manual test path.

## Acceptance (2.1 gate)

1. `shopify:backfill-commerce <brand>` fills country + product history; output shows the covered span; reruns are idempotent and never alter `daily_metrics`.
2. Country report renders real by-country revenue/orders/AOV for the period with the comparison and classification, matching Shopify for one brand.
3. Product report renders sales by product/category/price-point, YoY, climbers/drops, slowest-sellers, and the new-design review, matching Shopify.
4. Overall Performance report now shows the top-markets section.
5. Both reports export to PDF and share link via the 2.0 engine; theme applies.
6. `tsc` clean; smoke tests green; no new dependencies.
