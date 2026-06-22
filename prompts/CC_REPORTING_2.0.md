# Claude Code — Build: Reporting engine + Overall Performance report (slice 2.0)

Paste into Claude Code in the Helm repo. This builds **slice 2.0 only** of the
Reporting & Creative Intelligence feature. Read these first, in order:

1. `docs/feature-specs/reporting-and-creative-intelligence.md` — the feature spec. §8 lists the slices; you are building 2.0.
2. `AGENTS.md` and `docs/README.md#non-negotiables` — project rules. Follow them exactly.
3. `docs/decisions/README.md` — ratified deviations (MySQL not Postgres, etc.). The DB is **MySQL**, live in production; every schema change is a migration and must be additive / non-destructive.
4. Existing code you will build on: `app/Services/Aggregation/DashboardQuery.php` (revenue / spend / blended ROAS + the year-over-year comparison are already computed here — reuse, do not re-derive), `app/Models/DailyMetric.php`, the `workspace_settings` table + model, `routes/api.php`, `web/src/routes/DashboardPage.tsx`, `web/src/components/dashboard/BrandsTableWide.tsx`, `web/src/styles/globals.css`.

## What 2.0 is

Two things: (a) the **reporting engine** that every later report plugs into, and
(b) the **Overall Performance report** as the first report type — revenue vs ad
spend vs blended ROAS, period-over-period, on data Helm already syncs. No new ad
or commerce granularity in this slice.

## Explicitly NOT in 2.0 (do not build)

Granular ad/creative data, the naming parser, the live creative dashboard, the
Shopify line-item / product / **country** data layer, the LLM narrative, and the
country / product / Meta-audit reports. Those are slices 2.1–2.4. If a piece of
the Overall Performance report needs data Helm doesn't have yet (e.g. revenue by
country), **omit that section in 2.0** and leave a clear extension point — do not
stub it with fake data (missing ≠ zero).

## Backend (`api/`)

Mirror the `Platforms/` adapter pattern — a contract + a registry + one concrete type.

- `app/Reports/Contracts/ReportType.php` — interface: `key(): string`, `label(): string`, `build(Brand $brand, ReportFilters $filters): array` returning a structured, render-ready payload (no HTML on the server).
- `app/Reports/ReportRegistry.php` + `config/reports.php` — register report types by key (mirrors `PlatformRegistry` / `config/platforms.php`).
- `app/Reports/OverallPerformance/OverallPerformanceReport.php` — the first type. Reuse `DashboardQuery` for the figures: total revenue (Total sales / `total_sales`), total ad spend (sum across connected ad platforms), blended ROAS, orders, AOV, all for the selected period and the comparison period. Per-platform block must show **"not connected"** for platforms the brand has no active connection to — never €0 (carry the dashboard's missing-≠-zero discipline).
- `app/Http/Controllers/Api/ReportController.php`:
  - `GET /api/reports` — list available report types (key, label).
  - `GET /api/brands/{brand}/reports/{type}` — build + return the report payload for `?period=` and `?compare=` (reuse the dashboard's period/compare params).
  - `POST /api/brands/{brand}/reports/{type}/shares` — snapshot the report (type, brand, filters, the **edited narrative/commentary**, generated_at) and return a public token.
  - `GET /api/r/{token}` — return a snapshot's payload for the public read-only view (no auth).
- Migration: `report_shares` (id, brand_id, report_type, filters json, content json [edited narrative + comments], token unique, created_by, expires_at nullable, timestampsTz). Additive only.
- Report branding/theme is **per agency** = workspace-level (Helm is single-tenant). Add a `report_branding` JSON column to `workspace_settings` (agency display name, accent colors, footer text, optional logo url) via an additive migration, with sane defaults (agency name "Roasdriven", footer "Powered by novasolution.ae"). Expose read/update on the existing settings endpoint.
- Authorization: report routes honor the existing `Brand` access scope / policies (a user only reports on brands they can see). The public `GET /api/r/{token}` is intentionally unauthenticated but read-only and token-gated.

## Frontend (`web/`)

The report is a **client-facing, white-label deliverable** — it uses its **own
editorial theme** (serif display headings, warm neutral palette, the look of the
example reports), **not** the dashboard's Linear/Stripe design system. Theme
values come from the workspace `report_branding` config as CSS variables, so the
agency recolors every report from one place.

- `web/src/routes/ReportsPage.tsx` — pick a brand + a report type.
- `web/src/routes/ReportViewPage.tsx` — render the Overall Performance report from the API payload: header with **brand name on top**, KPI row, revenue-vs-spend table (period vs comparison), by-platform block (with not-connected states), an **editable commentary block** (contenteditable, saved into the share snapshot), footer with the agency name + "powered by novasolution.ae". Filters: period + comparison (reuse the dashboard's controls). Actions: **Export PDF** (print-friendly CSS + `window.print()` — no new dependency) and **Create share link**.
- `web/src/components/reports/ReportShell.tsx` + small pieces (KpiRow, etc.) — the reusable white-label template later reports reuse. Print CSS (`@media print`) so Export PDF and a browser print both produce a clean page.
- `web/src/routes/PublicReportPage.tsx` — route `/r/:token`, renders the snapshot read-only (no app shell, no auth), same template + theme.
- `web/src/hooks/useReports.ts`, types in `web/src/types/domain.ts`.

## Conventions (non-negotiable)

- Locked stack only. **No new dependency without asking** — in particular, do NOT add a PDF library; use print CSS for 2.0. If a true server-generated PDF (emailable attachment) is wanted, stop and flag it as a follow-up needing sign-off.
- Every table is a migration; additive and non-destructive (production is live, ~80 brands).
- Native currency + `fx_rate_to_usd`; brand-timezone dates; reuse `DashboardQuery`'s currency handling.
- `tsc --noEmit` must pass in `web/`; update `web/src/lib/mockApi.ts` if you touch shared types. Add a PHPUnit smoke test for `GET /api/brands/{brand}/reports/overall-performance` (asserts the payload shape + the not-connected platform state).
- Explain non-obvious decisions in plain language in your summary (Kanwar reviews diffs, not your keystrokes). Name which spec section each piece implements.
- End with a deploy note (migrations to run, any env/config) and the manual test path.

## Acceptance (2.0 exit gate)

1. Pick a brand → Overall Performance report renders: revenue, ad spend, blended ROAS, orders, AOV for the period, with the comparison column, and a per-platform block that shows "not connected" where applicable.
2. The commentary block is editable; edits persist into a created share.
3. Export PDF produces a clean branded page (brand name top, Roasdriven / powered-by footer).
4. Create share link → opening `/r/:token` shows the same report read-only with the saved commentary and theme.
5. Changing the workspace `report_branding` (accent color, agency name, footer) re-themes the report.
6. `tsc` clean; report smoke test green; no new dependencies.

When done, the next slices are 2.1 (Shopify line-item → country + product reports) and 2.2 (Meta creative data + naming parser → Meta audit + live dashboard) per the spec.
