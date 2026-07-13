# MoM Strategy Report v2 — Bosco's meeting template as a product (2026-07-12)

**Executor:** Opus. **Guardrails:** product-audit-adset-underperformers.md §0 verbatim + D-022 (workspace seams) + reports doctrine (freshness fail-closed, missing ≠ 0, Verified/Proxy labels, shares replay filters). Source template: Bosco's 34-slide "MoM Strategy Template ROASDRIVEN" (inventoried below — the PDF is the product requirement).

**Product intent (CEO lens):** this is not a report, it's THE MONTHLY MEETING. Bosco runs client meetings off this deck: every section has a benchmark in its title ("Goal >80% vertical placement", "Benchmark Existing <15%"), a To-Do/commentary box, and a fixed narrative order — money first (slide 4), then markets, then ads mechanics, then web, then actions. v2's job: generate that meeting automatically from synced data, with the agency's own market language (tiers) and their format control (customizer), and NEVER an empty table in front of a client.

**Architecture decision (CTO lens):** build as a NEW report type `mom` ("MoM Strategy") beside the existing `monthly` — the shipped monthly stays untouched for 88 live brands; Bosco switches when v2 is approved, then we retire v1 (separate ratification). Second architectural mandate: **v2 is section-streamed, not monolithic** — each section is its own sub-request (`GET brands/{brand}/reports/mom/section/{key}?month=`) rendered progressively. The current monthly builds one 16.3s+ payload; on the biggest brand it now freezes the tab (see M0). Never build a second monolith.

---

## M0 — P0 BUG FIRST: monthly report dead for Nude Project (biggest brand)

Reproduction evidence (2026-07-12, live prod via Chrome): `GET /brands/new-polinesia/reports/monthly` → first load froze the renderer >45s (CDP timeout); reload rendered the SPA NotFoundPage with ZERO API calls fired (not even /me) and default "Helm" branding. Other brands fine. Worked 2026-07-10 (16.3s API build then). Investigate in this order:
1. Server: `php artisan tinker` — time `ReportRegistry->for('monthly')->build($nudeProject, $filters)`; check PHP-FPM `max_execution_time` / gateway timeout in Cloudways logs for that route; the payload likely grew (availableMonths + multi-platform gender + funnels) past a limit.
2. Client: profile rendering of the returned payload size for new-polinesia (huge arrays? sections with thousands of rows?); the freeze-then-404 pattern suggests the tab crashed mid-render and a stale/errored state routed to `*`.
3. Recent route changes (products-hub commits) — diff App.tsx route order vs the deployed bundle.
Fix root cause; add a regression test that caps monthly payload size (assert < N KB on a seeded heavy brand) or paginates the offending section. THIS SHIPS BEFORE ANY V2 WORK — a client-facing report that 404s is a trust emergency.

---

## M1 — Country tiers + report layout infrastructure

**Tiers (Bosco's ask; note his real tiers are custom groups: T1, T4, US, ASIA, SUMMER, ES, NO — so tiers are agency-defined labels, not a fixed 1/2/3):**
- `country_tiers` — id, workspace_id (nullable), brand_id (nullable — null = agency default set, set = per-brand override set), tier_key (24), label (48), color (7 hex), countries (json of ISO-2), position (int), timestamps. Resolution: brand set if ANY brand rows exist, else agency set. Seed migration: agency default T1/T2/T3 + "Other" — fully editable.
- Settings → new "Country tiers" section (agency-wide) + brand Settings "Customize tiers for this brand" (copies agency set, then edits). Unassigned countries auto-bucket to "Other" — never dropped.
- Service `CountryTiers::resolve($brand)` returning country→tier map; used by report sections + reusable later (dashboard, ads views).

**Report layouts (the customizer):**
- `report_layouts` — id, workspace_id (nullable), brand_id (nullable — same override semantics as tiers), report_type (24), sections (json: ordered [{key, enabled, settings?}]), updated_by, timestamps; unique (workspace_id, brand_id, report_type).
- Resolution: brand override → agency default → code default (the §M2/M3 catalog order). Share/public tokens snapshot the RESOLVED layout into the share's filters json so a client link never reshuffles after the agency re-customizes.
- Settings → "Report format" page: pick report type → drag-to-reorder section list (plain HTML5 drag or up/down buttons — no new deps) + enable toggles + "Reset to default". Brand Settings gets "Customize report format for this brand".

Tests: tier resolution precedence + Other bucketing; layout resolution precedence; share snapshot immunity; RBAC (master_admin/manager). **Proof:** suite+tsc+build; reorder in Settings visibly reorders a generated mom report.

## M2 — Core money + market sections (the meeting's first half)

Every section: (a) own endpoint, (b) commentary + To-Do editable blocks (stored per brand+month+section in `report_commentaries`, carried into shares), (c) benchmark chip in the header where defined (`config/momreport.php` — agency-editable values later, defaults from the PDF), (d) internal view shows a backfill CTA on missing data; shared view AUTO-HIDES incomplete sections (the "no empty fields" law).

- **S1 Financial matrix (PDF slide 4 — ALWAYS FIRST by default).** Rows = every month of report year + full prior year (two stacked tables exactly like the slide). Columns: Orders, AOV, %Returns, Revenue, Spend ("Inversión"), Google share % of spend, blended ROAS, New customers, Returning customers, %Ret, Total customers, CAC (spend ÷ new customers), ROAS-nc (new-customer revenue ÷ spend) where customer-type data exists, MoM deltas (Δ acquisition, Δ retention, Δ revenue, Δ budget). Sources: daily_metrics + campaign tables; New/Returning needs the ShopifyQL customer_type PROBE (same probe as brand-inventory-and-customer-mix spec — run it; if unavailable, render the matrix WITHOUT those columns + an honest note, never fake them). Heatmap cells (green/red vs prior month) like the spreadsheet. Summary callout row (Revenue +X% YoY, CAC, ROAS, AOV) auto-computed.
- **S2 Total sales evolution.** Daily revenue line for the report month with prior-year same-month overlay (both from daily_metrics; plain SVG/div chart per report conventions).
- **S3 New vs Returning evolution.** Daily new vs returning revenue charts — customer_type probe dependent; hide with note if unavailable.
- **S4 Market revenue by TIER (slide 7).** Tier × month matrix: revenue share %, revenue €, ΔMoM/ΔYoY/ΔYTD, ROAS by tier by month (Meta spend by country breakdown ÷ revenue by country, both already synced). Uses CountryTiers::resolve.
- **S5 Country revenue MoM (slide 8) + S6 ROAS by country (slide 9).** Country × month revenue matrix w/ tier tag column, per-country ROAS, ΔYoY/ΔMoM, Meta spend % by country; status column TOP/CHECK/ALARM from deterministic rules (config: ALARM = ROAS < breakeven (margin set) or <1.5 [HELM DEFAULT] with spend ≥ floor; TOP = top-quartile ROAS at meaningful spend) — title auto-suggests "Push {countries}" from TOP list.
- **S7 Best categories MoM/YoY (slides 15–16)** — commerce category dimension, month columns + YoY deltas + share %; stock question chip when a top category's products show low cover (inventory join).
- **S8 Best sellers MoM (slides 17–18)** — product × month revenue + Last-6-months + share + STOCK column (catalog join, red when low/0) + YoY.
- **S9 Sessions & CR YoY (slide 21)** — daily sessions + conversion rate with prior-year overlay (shopify_funnel_daily).
- **S10 Funnel by country / S11 by landing path (slides 22–23)** — sessions→ATC→checkout→purchase rates with YoY sub-rows; red/green flags vs config thresholds.
- **S12 Prior-year next-month lookback (slide 27)** — next month's daily sales LAST year ("what spiked this time last year") — pure daily_metrics.

**Proof:** each section endpoint hand-verified against SQL on one real brand; the assembled default report matches the PDF's order S1→S12.

## M3 — Meta mechanics sections (the ads half)

- **S13 Audience: new vs existing spend (slide 10).** Per campaign: spend split by Meta audience segments (meta_breakdown_daily audience axis — already synced daily), Existing% chip vs benchmark <15% (config), sorted by spend desc, AOV column included (the PDF explicitly asks). 
- **S14 Placement mix (slide 11).** Placement × (cost, CPC, CTR, CPM, %spend, acc%) from the placement breakdown axis + "vertical placement %" (stories+reels) vs Goal >80% chip + Feed-vs-Stories/Reels delta mini-table. Needs `meta:backfill-breakdown placement` coverage — backfill CTA when missing.
- **S15 Gender mix (slide 13)** — age_gender axis folded to gender (reuse genderRows).
- **S16 Thruplay/awareness country concentration (slide 14)** — country breakdown filtered to awareness campaigns (campaign objective from ad_campaign_daily_metrics), concentration alert when top country > threshold.
- **S17 Landing spend × best sellers (slides 19–20)** — ad_product_daily spend by product landing vs product revenue + stock — both already synced; flags mismatches ("spending on X, best seller is Y").
- **S18 Klaviyo attribution + list growth (slides 24–25)** — DEPENDS ON GO-1 Klaviyo adapter (master plan). Until then: section renders a "Connect Klaviyo" placeholder internally and auto-hides on shares. Build the section shell now, data wiring lands with GO-1.

## M4 — Editorial layer (what makes it a MEETING)

- **S0 Next Steps carryover (slide 3 + 28–29).** `report_next_steps` (brand_id, month, items json [{text, group ('mes'|'ads'|'countries'|'email'|'cro'), status open|done|dropped, carried_from}]). Generating month M pre-fills from M-1's open items (the PDF's "copiar y pegar para ver status" — automated). Editable checklist UI; More/Better/New template buttons (slide 29). Optionally seeded from accepted Ledger recommendations when GO-3 lands (seam, not dependency).
- **S19 Novedades (slide 31).** Agency-wide monthly talking points (workspace-level `report_notes` per month, written once in Settings, appears in every brand's report that month, per-brand editable copy).
- Per-section commentary/To-Do blocks (M2) complete the annotation story. Out of scope, documented as manual forever: creative example screenshots (slide 12), cookie-banner audits (32), Agentic dashboard (33) — the customizer supports a free-text/image "Custom block" section type for these.

## M5 — No-empty-fields enforcement + performance

- Report view embeds the existing DataCoverageCard (one-job backfill) at top when ANY section reports missing coverage + per-section "Backfill this data" chips → same backfill-dataset endpoint (dataset mapped per section: history/campaigns/creatives/commerce/breakdowns — extend the job's dataset routing for breakdown backfills: NEW dataset key 'breakdowns' running meta:backfill-breakdown axes + coverage row).
- Shared/public view: incomplete sections auto-hidden; freshness gate fail-closed as everywhere.
- Performance budget: report shell < 1s; each section < 5s on the largest brand (measure on Nude Project — the M0 brand IS the benchmark); sections load in parallel, progressive render, per-section retry. Month selector reuses availableMonths.

## Decisions made (don't re-ask)
| Decision | Answer |
|---|---|
| New type vs update | NEW `mom` type; v1 monthly untouched until Bosco approves switch |
| Slide 4 | S1 financial matrix, first by default; customizer can reorder but default order mirrors the PDF |
| Tiers | Agency-defined labeled groups (not fixed 1/2/3), workspace default + per-brand override, "Other" auto-bucket |
| Customizer scope | Section order + enable/disable + custom free blocks; agency default + per-brand override; shares snapshot resolved layout |
| No empty fields | Internal = backfill CTAs; shared = auto-hide incomplete sections |
| Architecture | Section-streamed endpoints, never a monolith payload (M0 lesson) |
| New/Returning | ShopifyQL customer_type probe first; honest omission if unavailable |
| Klaviyo section | Shell now, data with GO-1 |

## Kanwar/Bosco-owed
1. Bosco defines the agency tier sets + benchmark values (existing <15%, vertical >80%, Klaviyo 50% are the PDF defaults — confirm).
2. M0 root-cause may need Cloudways log access (Kanwar).
3. customer_type probe result decides S1's customer columns + S3 — if Shopify won't give it, decide whether Klaviyo/GO-1 becomes the customer-mix source.
