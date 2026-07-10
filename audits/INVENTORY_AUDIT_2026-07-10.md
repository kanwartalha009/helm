# Inventory Intelligence audit — 2026-07-10

Trigger: Kanwar — "sometimes for 7 days filter Meta spend comes empty… make it trustable, agency can use to make decisions, make it 10/10." Scope: InventoryQuery, InventoryController, InventoryPage/InventoryTable, and the entire data pipeline behind them (product_catalog, commerce_daily_metrics, ad_product_daily). All findings below FIXED in this pass unless marked OPEN. Verified: suite 113/815 on this pass's base (129 passing in the union with the parallel ad-set work), tsc + build green.

## Root causes of "7-day Meta spend comes empty" — both pipeline gaps

**I-01 · ad_product_daily was never synced daily — FIXED (the actual bug)**
Product-attributed Meta spend was only written by the MANUAL `meta:backfill-ad-products` command. Nothing scheduled it, the daily sync didn't touch it. The moment the last manual backfill's end date passed, every newer window had zero rows — a 7-day filter looked "empty" while a 30-day one still caught older rows. Fix: the Meta daily sync now upserts that day's ad-product spend (fault-isolated, same pattern as the breakdown sync; one extra level=ad insights call per brand-day). The "Backfill everything" button's campaigns dataset also runs the ranged product-spend backfill now, so history is one click.

**I-02 · product_catalog (the stock column) was never scheduled — FIXED**
`shopify:sync-catalog` existed but was not in the scheduler — stock silently aged since the last manual run. Now daily at 14:10 UTC beside the 14:00 inventory snapshot.

## Honesty defects (missing data shown as €0 / 0)

**I-03 · Unsynced window rendered as zeros — FIXED (worst trust defect)**
When the window had NO ad-product rows at all, every product showed spend €0, action "No Meta spend", summary Meta spend €0 — indistinguishable from a genuinely zero-spend week and actively wrong for decisions. Same for commerce (units/revenue 0). Now: a window with no synced data returns NULL throughout (rows, summary, unattributed block) → the UI renders '—' plus a banner: "Meta product spend isn't synced for this window — spend and ROAS show '—', not zero." The critical distinction is preserved: in a COVERED window, a product that truly spent/sold nothing still shows 0.

**I-04 · No freshness surface — FIXED**
Only the catalog timestamp was shown. New `dataThrough {catalog, commerce, adSpend}` payload + a freshness strip on the page: "Stock synced X ago · Sales through DATE · Meta product spend through DATE", each segment amber when behind the selected window (stock amber past 48h).

## Correctness defects

**I-05 · ROAS mixed currencies — FIXED**
Row and summary ROAS divided native revenue by native ad-account spend. For a brand whose Meta account bills in a different currency than the store, the ratio was simply wrong. ROAS is now computed from USD sums on both sides (fx snapshots already on every row); displayed spend/revenue stay native; a `spendCurrencyMismatch` flag drives a caption when the ad-account currency differs.

**I-06 · Archived/draft products polluted the page — FIXED**
The catalog query took every row, so archived products showed as "out of stock — pause" noise and inflated the status counts. Non-active products are now excluded (case-insensitive; null status treated as active so missing metadata never hides stock), with a "{n} archived/draft products excluded" footer.

**I-07 · Custom window accepted today/future dates — FIXED**
`to=today` silently included a partial day. Custom windows now clamp to yesterday (brand timezone), same contract as the reports engine.

**I-08 · Client-side collection totals & sorts treated null as 0 — FIXED**
Group-by-collection summed rows with `?? 0` and sorts interleaved null-spend rows among real zeros. Null-preserving aggregation (all-null group → '—') and null-last sorting now.

## Kept as-is (deliberate)

- Headline blended ROAS keeps unattributed (collection/dynamic) spend in the denominator — dropping it would flatter the number; the unattributed banner explains the gap.
- Peak active ads = MAX(ads_count) over the window — correct for "how many ads pointed at this product at peak".
- Units are gross ordered quantity vs revenue before returns — documented in the docblock; consistent with the page's stated basis.

## OPEN (not this pass)

- **ALERT_AT = 20** stock-alert threshold is a flat constant for all brands. Should become a per-brand/config value (`config/rules.php` — Opus owns that file in the parallel next-features build; fold it into its Phase 1/2 work rather than colliding now).
- Product-spend coverage is Meta-only (`ad_product_daily` has no Google/TikTok rows yet) — the column is honestly labelled "Meta spend"; the next-features spec Phase 5 extends attribution to Google/TikTok landing URLs.
- Opus's own `BrandMarginTest` has a float-strictness failure (50 vs 50.0) in its Phase 0 code — flagged to its work stream, unrelated to inventory.

## Deploy

`git pull && bash scripts/deploy.sh`. New scheduled command (`shopify:sync-catalog` 14:10 UTC) needs nothing extra — the `schedule:run` cron picks it up. For each brand where product spend matters, click "Backfill everything" once (campaigns dataset now includes product spend) or run `php artisan meta:backfill-ad-products <brand> --since=2025-07-01`; the daily sync keeps it fresh from then on.
