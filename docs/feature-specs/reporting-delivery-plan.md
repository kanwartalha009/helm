# Reporting & Creative Intelligence — delivery plan

Turns the spec's slices ([reporting-and-creative-intelligence.md](./reporting-and-creative-intelligence.md) §8)
into an executable task list. One loop per slice:

> **prompt (Cowork) → build (Claude Code) → review diffs (Kanwar) → deploy → validate with Bosco → next slice.**

Ship in order. Don't start a slice's build before the previous slice is validated,
except the blockers below, which are cleared in parallel and off the 2.0 path.

## Blockers to clear (parallel — needed for 2.2 / 2.3, not 2.0/2.1)

| Blocker | Needed for | Owner | Status |
|--------|-----------|-------|--------|
| Naming-convention doc — creator · product · format · week · tier · Brand/PP | 2.2 parser | Bosco → Kanwar | open |
| Status thresholds — NEW / TESTING / SCALING / WINNER / HOLDING / DECLINING / HIDDEN | 2.2 classifier | Bosco → Kanwar | open |
| LLM provider + cost + data-privacy (D-016) | 2.3 | Kanwar | open |

## Slice 2.0 — engine + Overall Performance · no new data · prompt ready

Prompt: `prompts/CC_REPORTING_2.0.md`

- [ ] Report engine — `ReportType` contract + `ReportRegistry` + `config/reports.php`
- [ ] `OverallPerformanceReport` — reuse `DashboardQuery` (revenue / spend / blended ROAS / YoY); per-platform with not-connected states
- [ ] Report API — list · build · create-share · public `GET /api/r/{token}`
- [ ] Migrations — `report_shares`, `workspace_settings.report_branding` (additive)
- [ ] White-label template + ReportView — brand name top, Roasdriven / powered-by footer, filters, editable commentary, Export PDF (print CSS)
- [ ] Public `/r/:token` read-only view
- [ ] Smoke test + `tsc` clean
- [ ] Kanwar — review diffs, deploy
- [ ] Bosco — send one real client report, capture feedback

**Gate:** Bosco sends a real Overall Performance report from Roasdriven.

## Slice 2.1 — Shopify line-item data → Country + Product reports · prompt ready

Prompt: `prompts/CC_REPORTING_2.1.md`

- [ ] Shopify line-item fetcher — orders → line items (product · SKU · category · **country** · qty · price · **discount** · refund) + product catalog (launch date, price point)
- [ ] Schema — `order_lines` / `product_daily_metrics`, `country_daily_metrics` (additive)
- [ ] Historical backfill command — chunked like `ads:backfill-spend`, for YoY
- [ ] `CountryReport` type — revenue/orders/AOV by country, period vs comparison, classification
- [ ] `ProductPerformanceReport` type — sales by product/category/price-point/launch, YoY, climbers/drops, slowest-sellers, new-design review
- [ ] Tests + `tsc`
- [ ] Kanwar — review, deploy, run backfill, validate vs Shopify
- [ ] Bosco — validate country + product reports

**Gate:** country + product reports match Bosco's Shopify / Motion figures for one brand.

## Slice 2.2 — Meta creative data + parser + status → Meta audit + live dashboard

Blocked by: naming-convention doc, status thresholds.

- [ ] Ad-creative fetcher — extend `InsightsFetcher` to ad/creative level (spend, ROAS, CTR, CPM, **thumbstop/video metrics**), campaign→adset→ad
- [ ] Schema — `ad_creatives`, `ad_creative_daily_metrics` (additive)
- [ ] Creative assets — store thumbnails; embed + refresh video URL (D-015)
- [ ] Naming parser — the documented contract; `unparsed` fallback surfaced, never guessed
- [ ] Status classifier — deterministic, from thresholds (rules, never LLM)
- [ ] `MetaAuditReport` type
- [ ] Live weekly dashboard surface — sections, 7/14/30D, playable creatives, status badges, WoW
- [ ] Tests + `tsc`

**Gate:** the live dashboard reproduces a `bruna`-grade view from live data for one brand.

## Slice 2.3 — LLM intelligence + custom reports

Blocked by: LLM provider/privacy (D-016).

- [ ] LLM provider integration (ask before adding any library — outside the locked stack)
- [ ] Read-only scoped query layer over a brand's data (never raw DB, never writes figures)
- [ ] Narrative / strategy / new-ideas generation per report — editable before send
- [ ] Custom chat report — natural-language query → report
- [ ] Tests + guardrails (no model-generated numbers)

**Gate:** the LLM draft is good enough that Bosco edits rather than rewrites.

## Slice 2.4 — Google + TikTok ad-level + store/conversion audit (optional)

- [ ] Extend the creative fetcher to Google + TikTok
- [ ] Per-platform audit report types
- [ ] Store/conversion audit — decide data source (PageSpeed / pixel / crawler) once a reference exists

## Now / Next / Later

- **Now:** ship 2.0 (prompt ready). Clear the three blockers in parallel.
- **Next:** 2.1 (prompt ready) once 2.0 is validated with Bosco.
- **Later:** 2.2 → 2.3 → 2.4 as blockers clear.
