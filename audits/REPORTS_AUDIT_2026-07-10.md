# Reports audit — 2026-07-10

Scope: every shipped report type (Overall performance, Monthly) audited end-to-end —
engine payloads, currency/fx handling, freshness gating, comparison math, share/public
flow, and the React documents — plus a gap analysis of what an ads agency needs to send
clients. All findings below were fixed in the same pass and are covered by tests
(`MonthlyReportTest`, `ReportFiltersTest`, `NewReportTypesTest`; suite green at
81 tests / 488 assertions).

Status legend: **FIXED** = shipped in this pass.

---

## 1. Findings — anomalies in existing reports

### Critical

**R-01 · Monthly report made LIVE Meta API calls on every public view — FIXED**
`MonthlyReport` gender/placement sections called the Meta adapter at render time. Every
open of a *shared public link* hit the Meta Marketing API with the workspace token:
slow renders, rate-limit burn attributable to client page-views, and payloads that
changed after the report was "frozen". Now reads `meta_breakdown_daily` (synced data
only); shared reports render from the DB alone.

**R-02 · Overall-performance freshness gate silently dead — FIXED**
`OverallPerformanceReport` referenced `CarbonImmutable` without the import. The
freshness check threw, was swallowed, and the gate defaulted OPEN — stale reports were
being served as fresh, in production, since the report shipped. Import fixed and the
default flipped: **every freshness gate now fails CLOSED** (a bug in the check can
gate a fresh report, it can never un-gate a stale one). Same fail-closed default in
the new Weekly and Creative reports.

**R-03 · Monthly report had NO freshness gate — FIXED**
Only overall-performance attempted gating. Monthly could ship a month with half-synced
final days as if complete. Gate added (same fail-closed contract).

### High

**R-04 · YoY comparisons fabricated zeros — FIXED**
`MonthlySeries` returned 0 for prior-year months with no synced rows, so YoY showed
"+∞% growth" against a fake €0 baseline (violates the platform rule: missing ≠ 0).
Prior-year is now `null` when unsynced → renders as "—" with a "not synced" note.

**R-05 · Revenue basis inconsistent with D-005 — FIXED**
`MonthlySeries` and `CommerceBreakdown` computed revenue without adding refunds back
(D-005: total revenue = total_sales + refunds_amount added back), so report totals
disagreed with the dashboard for any brand with refunds. Both now on the D-005 basis;
`CommerceBreakdown` trend direction fixed alongside.

**R-06 · AdAudit mixed currencies in totals — FIXED**
Platform totals summed native-currency spend across accounts. Totals now carry native
+ USD explicitly; spend/CPM presented in the brand display currency. `landingSellers`
fx conversion fixed the same way.

### Medium

**R-07 · MTD filter on the 1st produced an empty report — FIXED**
`ReportFilters` "month-to-date" on the 1st of a month yielded a zero-day window.
Clamps to the previous full month. Custom ranges clamp to ≤ yesterday (today is
always incomplete by definition).

**R-08 · Month-key SQL was MySQL-only — FIXED**
`DATE_FORMAT` broke the suite on sqlite and would break any future driver move. New
`SqlMonth` helper emits `DATE_FORMAT`/`strftime` per driver.

**R-09 · Platform rows showed €0 spend for unsynced platforms — FIXED**
Overall-performance listed a connected-but-never-synced platform as 0 spend. Now
`null` → "not synced" chip in `ReportDocument`.

**R-10 · Period label interpolation broken in the document header — FIXED**
Template string rendered literally in some paths. Fixed in `ReportDocument.tsx`.

**R-11 · "Coming soon" ribbons leaked into shared reports — FIXED**
Monthly document sections under construction showed placeholder ribbons to CLIENTS on
public links. Ribbons now render in the editable (internal) view only.

**R-12 · Weekly/Creative freshness defaults — FIXED at birth**
New report types written with the fail-closed default from day one (`'upToDate' =>
false` when the check cannot run).

---

## 2. Flow rework (ratified in-conversation 2026-07-10)

Report-FIRST picker at `/reports`: choose the report type, then the brand. Brand list
preserves the dashboard engine's rolling-revenue order (best sellers first, no client
re-sort), searchable, with the same manager/team filter as the dashboard and ads hub
(master_admin/manager only). Picking a brand opens the report in a **new tab** so the
picker stays put for the next brand. Per-brand deep links (`/brands/{slug}/reports/{type}`)
keep working unchanged.

## 3. Gap analysis — what an ads agency sends clients

Present before this pass: overall-performance (periodic), monthly (month-close).
Ratified additions, both shipped:

- **Weekly snapshot** (`weekly`) — the Monday email. Last complete Mon–Sun ISO week in
  the brand's timezone; WoW deltas + same-ISO-week-last-year (null when unsynced);
  daily series, spend by platform, top campaign movers (top 8 by USD spend), action
  plan from the deterministic AdAudit rules. Freshness-gated.
- **Creative report** (`creatives`) — per-platform (Meta/TikTok) from
  `ad_creative_daily`: spend-ranked top creatives with ROAS/CTR/CPA, thumbstop
  (video_3s ÷ impressions), hold rate (thruplays ÷ video_3s), Meta quality rankings;
  deterministic fatigue rule (spend ≥ 100 USD this period, prior spend > 0, ROAS or
  CTR down ≥ 30%) and scale rule (ROAS ≥ 2× platform median at ≥ 50 USD spend).
  Freshness-gated on `ad_creative_daily`.

Considered and NOT built (data honesty): funnel/web-analytics reports (needs GA4 —
Helm has no web-analytics source), cohort/LTV (needs customer-level data we
deliberately do not sync), per-model inventory report (blocked on the ads→model
naming parser — see `helm_new_reports_plan`).

## 4. Invariants (hold for all report types)

- Freshness gates fail CLOSED.
- Missing data is `null`/"—"/"not synced" — never 0.
- Revenue on the D-005 basis everywhere.
- Public/shared views render from synced DB data only — no live platform API calls.
- LLM narrative (where enabled) writes prose only; every number comes from the
  deterministic payload.
