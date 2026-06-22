# Feature spec — Reporting & Creative Intelligence

Status: draft · Owner: Kanwar · Client driver: Bosco · Created 2026-06-21
Relates to spec §00 (Phase 2 deep analytics), §01 (platform adapter), §06 (sync).
Decisions: D-014…D-016 in [`../decisions/`](../decisions/README.md).

## 1. Goal

Replace Motion. Bosco pays €3,000+/month plus five people to pull creative-level
ad data and hand-build client reports. This feature brings every figure **inside
Roasdriven** — ad-and-creative-level performance, Shopify product/country
granularity, a live creative-intelligence dashboard, and white-label client
reports — then layers an LLM that reads each brand's data and writes the strategy
to improve conversions. Target outcome: the agency generates a client-ready,
branded report in minutes, and the Motion line item goes to zero.

This is the largest single build in the project — bigger than Phase 1. It is
scoped as its own phase and shipped incrementally (§8), each slice validated with
Bosco before the next.

## 2. The report catalog

Six pre-installed reports plus custom. All share one engine (template, filters,
export, edit-before-send). Adding a report later is a registry entry, not a
rewrite.

| Report | Side | Granularity it needs | Reference |
|--------|------|----------------------|-----------|
| Overall performance | Commerce + ads | brand revenue · total spend · blended ROAS · period-over-period | prototype (built) |
| Weekly ad report (live, interactive) | Ads | ad/creative · creator · product · format · Brand-vs-PP · status · WoW · playable video | `bruna` weekly report |
| Meta ads audit | Ads | creative-level waste/winners, CTR/CPM/thumbstop, fixes | `heimat_meta_audit` |
| Country / geographic | Commerce | revenue · orders · AOV **by country**, this-30 vs prev-30, recovery drill-down | `heimat_country` / `usa_recovery` |
| Product performance (= Bosco's "Shopify audit") | Commerce | sales by product/SKU/category/**price-point bucket**/launch; YoY per product + category; new-vs-returning; new-design review; slowest-sellers with skip/monitor/keep verdicts; zero-sales flags; insight blocks | `bruna` product report (fully reviewed) |
| Store / conversion audit (site speed · checkout funnel) | Commerce | optional / future — not in Bosco's current reference set; needs its own data source (PageSpeed / pixel / crawler) | not requested yet |
| Custom | Either | natural-language query → report | chat + LLM |

## 3. Architecture — three layers

The pretty templates are ~20% of the work. The 80% is the **data layer**, and
nearly every report depends on granularity Helm does not yet store.

```
  Intelligence   rules (flags, status, signals — never hallucinated)
                 + LLM (narrative, strategy, new ideas — editable before send)
  ──────────────────────────────────────────────────────────────────────
  Surface        live interactive dashboard  ·  white-label client reports
                 (export to PDF / shareable link, per-agency theme)
  ──────────────────────────────────────────────────────────────────────
  Data           granular ads (Meta→Google→TikTok)  ·  granular commerce (Shopify)
                 + the naming-convention parser  ·  + creative assets (embedded)
```

## 4. Data layer (the headline)

### 4.1 Ads — creative-level

Today Helm syncs account-total daily spend per platform. The reports need
**ad-and-creative level**, via the existing `PlatformAdapter` (extend the Meta
`InsightsFetcher`, then Google/TikTok), pulled with the same ranged/`fetchRange`
mechanism already built:

- Per ad/creative/day: spend, impressions, clicks, purchases, purchase value,
  ROAS, CTR, CPC, CPM, and **video metrics** (thumbstop / 3-sec plays, hold rate).
- Campaign → ad set → ad hierarchy (names carry tier, e.g. `TIER1-FULL_FUNNEL`).
- **Creative assets**: store the **thumbnail** (tiny, cheap, keeps sent reports
  from looking broken); **embed the video URL and refresh it on expiry** — no
  video storage (D-015). Consequence, stated plainly: the live dashboard plays
  video (Helm re-fetches the current source when stale); an already-sent PDF or
  an old shared link shows the thumbnail still, because there is nothing stored
  to refresh. Accepted for near-zero storage cost.

### 4.2 The naming-convention parser (the spine)

Every rich section — Creators, Productos, Creative Type, Brand-vs-PP, Winners —
is **derived from the ad name**, not a separate data source. Bosco's convention
is consistent across all brands and automatic (D-014), so one parser turns an ad
name into structured dimensions:

| Dimension | Example token | Drives |
|-----------|---------------|--------|
| Creator | `emitaz`, `helena_brunathel` | Creators section, PP attribution |
| Product | `bordeaux`, `occitanie` | Productos, product×creative |
| Format | `Talking`, `Flatlay`, `Zoom`, `Packshot` | Creative Type |
| Week / version | `W22Y26` | recency, WoW lineage |
| Campaign tier | `TIER1-FULL_FUNNEL`, `WW_BEST-SELLERS`, `ES-FULL_FUNNEL` | funnel/market cut |
| Brand vs PP | creator handle present = PP; brand template (`Vienna Studio`, `BRUNA`) = Brand | Brand-vs-PP, PP/Brand ROAS |

The parser is a **documented, versioned contract**. It must degrade gracefully:
an ad that doesn't match is shown as `unparsed` and surfaced for naming cleanup,
never dropped or guessed.

### 4.3 Status lifecycle (rules)

A deterministic classifier tags each creative per period — `NEW · TESTING ·
SCALING · WINNER · HOLDING · DECLINING · HIDDEN` — from spend, ROAS, age, and WoW
trend. Thresholds come from Bosco's definitions (to be captured as config, not
hard-coded). This is rules, never LLM — it drives the colored badges a client sees.

### 4.4 Commerce — line-item level

Today Helm syncs brand-total ShopifyQL revenue. The country and product reports
need **order-line-item + product-catalog** data:

- Order line items: product, variant/SKU, quantity, price, **country**, discount, refund.
- Product catalog: title, category/type, **price point (bucketed)**, **launch/created date**.
- Customer: new vs returning.
- Full history for YoY (the product report compares vs prior year per SKU/category).
- Derived for the product report: price-point revenue share, **full-price vs discounted** split, **sales velocity** (revenue ÷ days since launch — normalises Jan vs Mar launches), category **cannibalisation** (new-product revenue vs category YoY lift). Velocity and cannibalisation are the "sharper future analysis" the report flags for itself — treat as 2.1 stretch.

### 4.5 Schema (additive, migrations per spec rule §5)

New tables, additive and non-destructive (D-001 discipline). Do not overload the
polymorphic `daily_metrics` — it stays the brand×platform×day rollup; granular
data lives beside it:

- `ad_creatives` (brand, platform, external id, name, parsed dims, thumbnail url, video ref, first/last seen).
- `ad_creative_daily_metrics` (creative, date, spend, impressions, clicks, purchases, value, video metrics, fx).
- `shopify_order_lines` or `product_daily_metrics` (brand, date, product/sku/category/country, units, revenue, refunds) — final shape decided in 2.1.
- `country_daily_metrics` (brand, date, country, orders, revenue) — may be a view over order lines.

Volume warning: ad-creative granularity ×80 brands ×daily ×history is a large
pull. Meta rate limits at this granularity are the real risk — the monthly-chunked
`fetchRange` pattern and Horizon backoff already in place are the mitigation;
budget the first full backfill as a multi-hour job.

## 5. Surface layer

### 5.1 Live interactive dashboard (the agency's working tool)

The `bruna` weekly report: left-nav sections, `7D / 14D / 30D` toggle, KPI cards,
playable creative grid with status badges + WoW, per creative/creator/product/
category, and rules+LLM blocks (Observaciones, Outputs Accionables, Plan de
Acción, Nuevas Ideas). This is where Bosco works; data is live.

### 5.2 White-label client reports (the deliverable)

"Export to client report" off the dashboard produces a static, branded artifact —
same data, narrative-first. One template, themed per agency:

- Brand name on top; **Roasdriven** + **powered by novasolution.ae** in the footer.
- Per-agency theme (colors, type) — the prototype's six CSS variables, stored
  against the agency, applied to every report.
- Common filters (date range, period comparison).
- **Editable content + comments before send** — every narrative block is editable;
  Bosco adds notes, rewrites the LLM draft, then exports.
- Export to **PDF + shareable link** (D-016). Video → thumbnail in these artifacts.

### 5.3 Customization is per-agency

Theme and any custom report definitions are scoped to the agency, so they apply
across all that agency's brands — set once, reused everywhere. This is what makes
it usable by anyone with basic skills.

## 6. Intelligence layer

- **Rules** produce every number, flag, status, and signal. Deterministic and
  auditable — the figures a client sees are never model-generated.
- **LLM** produces the narrative: root-cause ("Ramadan effect faded"),
  recommendations, the action plan, and new-idea generation, **per brand, from
  that brand's data**, aimed at improving conversions. Always a draft the agency
  edits before send (§5.2).
- **Custom reports**: a chat surface where the LLM queries the brand's data and
  generates a report from a natural-language request.

LLM is **outside the locked stack** (§02) and needs a written decision before any
build: provider, cost model at 100-brand scale, and — critically — the
data-privacy stance, since client commerce/ad data goes to the model. The LLM gets
a **read-only, scoped query layer** over the brand's data, never raw DB access,
and never writes numbers into the report (those come from rules). Captured as
D-016, open until you choose a provider.

## 7. Non-negotiables carried in

- Missing ≠ zero: an unconnected platform or a gap renders amber/"not connected",
  never €0 (shown in the prototype).
- Native currency + `fx_rate_to_usd` at write time; brand-timezone dates.
- Every external call through its `PlatformAdapter`; no Guzzle outside `Platforms/`.
- Adapter pattern unchanged — creative-level fetch is an extension of the same
  contract, not a new architecture.

## 8. Delivery — incremental, each gated and client-validated (D-013)

| Slice | Ships | Depends on | Exit gate |
|-------|-------|------------|-----------|
| 2.0 | Overall performance report + white-label engine (template, theme, filters, edit-before-send, PDF/link export) | data Helm already has | Bosco sends one real client report from it |
| 2.1 | Shopify line-item/product/country data layer → Country + Product reports | 2.0 engine | Country + product reports match Bosco's Motion/Shopify numbers for one brand |
| 2.2 | Meta ad+creative data layer + naming parser + status classifier → Meta audit + live weekly dashboard (playable) | 2.1 patterns | Weekly dashboard reproduces a `bruna`-grade view for one brand from live data |
| 2.3 | LLM intelligence layer → strategy/recommendations/new ideas + custom chat reports | 2.2 data, D-016 provider | LLM draft is good enough that Bosco edits rather than rewrites |
| 2.4 | Google + TikTok ad-level; store/conversion audit | 2.2/2.3 | per-platform audits + store audit at parity |

## 9. Open questions

1. **LLM provider, cost, and data privacy** — blocking for 2.3. Pick a provider;
   confirm client data may be sent to it (and under what terms). (D-016)
2. **Status thresholds** — get Bosco's exact definitions for NEW/TESTING/SCALING/
   WINNER/HOLDING/DECLINING/HIDDEN, or Helm proposes and Bosco ratifies.
3. **Naming-convention spec** — Bosco hands over the authoritative convention
   document; it becomes the parser contract (§4.2) and must stay stable.
4. **Store / conversion audit** — needs its own reference + a data source (site
   speed/checkout funnel isn't in Shopify orders — PageSpeed API, pixel/event
   checks, or a crawler). Detail when the reference arrives.
5. **History depth** — how far back to backfill creative + line-item data (YoY
   reports need ≥13 months; Meta creative history + rate limits set the ceiling).
