# Feature spec — Inventory Intelligence report

Status: **BUILDING** (Shopify data-layer gap closed 2026-07-01). Author: Nova.
Requested by Bosco. Reference: the Ganzitos "Stock por modelo × Meta" artifact
(Motion-based) — **logic reference only; we do NOT integrate Motion**, Meta spend
comes from our own connection.

> Report 2 (Customer-Mix New vs Returning) is **deferred** — Bosco wants only this
> one for now. The `shopify:diagnose-customer-type` probe is kept for when it's revived.

---

## 1. What it is

Per-brand, drill-in table: **one row per Shopify product** (not the "first word of
title" model grouping), expandable to its **variants**. Selectable date window
(default last 7 full days, up to yesterday; prior window for the Δ%). Columns:

| Column | Meaning |
|---|---|
| Product | title (+ variant count "4 colores" — informational) |
| Stock total | Σ variant inventory on hand (incl. negatives) |
| Variants | count of colours/sizes/etc. |
| Uds 7d (+Δ%) | gross units ordered (`quantity_ordered`) vs prior window |
| Gasto Meta 7d | Meta spend attributed to the product |
| Revenue 7d | `total_sales + refunds` (before returns), **Online Store only** |
| ROAS blended | Revenue ÷ Meta spend, **at product level** (variants share it) |
| Ads activos | active ads attributed to the product |
| Estado | 🔴 stock ≤ 0 · 🟡 ≤ 20 · 🟢 > 20 |
| Acción | derived action (e.g. "Stock suficiente") |

Confirmed with Kanwar: revenue = Online Store only; spend + ROAS at **product**
level (variants shown as a count, not per-variant spend); unattributed Meta spend
(dynamic/Advantage+/home) shown as a banner, not distributed.

---

## 2. Data inventory — what EXISTS vs what's NEW

Most of this is already built. Corrected after reading the code (earlier draft
overscoped it).

| Need | Source | Status |
|---|---|---|
| Per-product daily units | `commerce_daily_metrics` (dimension=`product`), `units` col | ✅ **now populated** — added `quantity_ordered` to `RevenueFetcher::groupedSalesByDay` (with a fallback to the proven metric set so Country/Product never regress). Run `shopify:backfill-commerce --dimension=product`. |
| Per-product revenue before returns | same table: `total_sales` + `refunds_amount` | ✅ **now populated** — added `returns` to the same query; `refunds_amount` col already existed. |
| Per-product daily net/orders | same table | ✅ already there |
| Stock per product | `inventory_snapshots` (dimension=`product`, `ending_units`) | ✅ exists (dead-stock report). Fed by `shopify:sync-inventory`. |
| **Variant count + per-variant stock** | Shopify GraphQL products→variants | 🔴 NEW (small): a catalog pull for variant count + per-variant inventory (for the expand row). |
| **Meta spend → product** | NEW ad-level pull | 🔴 NEW (the main work) — see §3. |
| **Report type + UI** | report engine / brand view | 🔴 NEW — see §4. |

**Verify on first run:** `quantity_ordered` is the Ganzitos-proven gross-units
metric but isn't yet used in our code. The fallback means a wrong name can't break
anything — it just leaves `units` null and logs `shopify.shopifyql.parse_error`
with the exact QL. After the first `shopify:backfill-commerce --dimension=product`,
confirm `commerce_daily_metrics` product rows have non-null `units`; if not, the log
names the fix (likely `units_sold`).

---

## 3. Meta spend → product attribution (the main new build)

Our Meta sync (`InsightsFetcher`) runs at **account + campaign** level only — no
ad-level or landing-URL data. To attribute spend to a product like Motion did:

1. **New ad-level pull**: extend the Meta adapter to fetch `level=ad` insights
   (spend, active count) + each ad's **creative destination/landing URL**
   (`creative{ object_story_spec / link_url / call_to_action.value.link }`).
2. **URL → product handle**: parse the landing URL, stripping the market prefix so
   spend combines across market domains:
   `~(?:/[a-z]{2}(?:-[a-z]{2})?)?/products/([^/?#]+)~i` → handle → product.
   Also handle `/collections/<slug>` when the slug matches a product/handle.
3. **Unattributed** (dynamic/Advantage+/home, ~15–20%): summed into a banner, not
   distributed onto rows.
4. Store per (brand, date, product) in a new `ad_product_daily` table (spend,
   active_ads, currency, fx, is_complete) — same additive pattern as the others.

ROAS = product Online-Store revenue (before returns) ÷ product Meta spend.

---

## 4. Placement / UI
Brand-scoped **Inventory Intelligence** view (Brand → new "Inventory" tab, or
extend the products page): the products table above + summary cards (Modelos,
Pausar/Alerta/OK counts, Stock neto, Uds 7d, Gasto Meta 7d, Revenue 7d, ROAS),
filters (Estado, catalog), sort, expandable variant rows. Reuses the freshness
gate (never a partial window).

---

## 5. Build sequence
1. ✅ Shopify commerce: units + returns per product (done — needs a backfill run + the units confirm in §2).
2. Variant catalog pull (count + per-variant stock).
3. Meta ad-level + landing-URL pull → `ad_product_daily` attribution (§3).
4. `InventoryQuery` service (join commerce + inventory + ad-product) + API.
5. Brand Inventory view (table + cards + filters + variant expand).

---

## 6. Open items
- Stock status/action thresholds beyond the ≤0/≤20/>20 tiers (with Bosco).
- Confirm `quantity_ordered` populates on the first backfill (§2).
- Collections roll-up: needed, or product/variant only? (assumed product/variant.)
