# 00 — Overview

## What Helm is

A unified web platform that consolidates Shopify revenue, Meta / Google / TikTok ad spend, and blended ROAS across 100+ DTC stores into a single dashboard. Built as an internal tool for one agency. Single-tenant, no white-label, no SaaS distribution.

The agency owner is the first and only user at week 10. Team and brand-side users come in Phase 1.5. Deep analytics in Phase 2. Ticketing in Phase 3.

## Build approach

Five phases over 22 working weeks. Phase 1 ships a functional dashboard for the agency owner (single user) within 10 weeks. Subsequent phases add capabilities incrementally without rebuilding the foundation.

| Phase | Name | Duration | End-of-phase milestone |
|-------|------|----------|------------------------|
| 1 | Dashboard (single user) | 10 weeks | Owner logs in, sees all 100 brands, adds new brands via self-service onboarding, drills into any brand's daily metrics. |
| 1.5 | Team & permissions (RBAC) | 3 weeks | Owner invites internal team and brand-side users; per-brand access scoping enforced everywhere. |
| 2 | Deep analytics | 4 weeks | Per-brand ad performance drill-in (campaign → ad set → ad), product performance, store audit cards. |
| 3a | Ticketing | 4 weeks | Brand users raise tickets, internal team triages, comment threads with internal notes. |
| 3b | External task tool sync | 2 weeks | Tickets flow to ClickUp / Linear / Asana (one tool, picked before this phase). |

Total: 22 weeks. A two-week buffer is folded in for unplanned API changes, rate limits, and auth complications. Buffer is **not** a license to add scope.

## Core architectural principle

All ad and commerce platforms (Shopify, Meta, Google, TikTok, and any future platform) plug into the system through a single `PlatformAdapter` interface. This is the most important design decision in the project. Read [01-architecture/platform-adapter.md](../01-architecture/platform-adapter.md) before writing any code.

## Auth-model simplification

The agency manages all client ad accounts through manager-level structures: one Meta Business Manager, one Google Ads MCC, one TikTok Business Center. This means three platform-level tokens cover all 100+ brands, rather than 300 individual OAuth flows. Only Shopify requires per-store installation (one custom unlisted app, installed by each store).

## Scope

### In scope — Phase 1
- Single-user dashboard for the agency owner showing yesterday, day-before, last 7 days, MTD, QTD, and custom date ranges.
- Per-brand row: Shopify revenue (gross and net of refunds), Meta spend, Google spend, TikTok spend, total spend, blended ROAS, with delta percentages versus the previous period.
- Self-service brand onboarding (Shopify + Meta + Google + TikTok in a guided flow, no developer involvement).
- Automatic daily sync with rolling 7-day backfill.
- Hourly sync of high-spend brands during business hours.
- Sync health visibility — failed syncs render amber, never as zero.
- Per-brand drill-in page with revenue trend chart and platform breakdown.
- Native currency stored, USD column computed via daily FX rates.
- Timezone-aware date ranges per brand.

### In scope — Phase 1.5 (RBAC)
- Four user roles: master admin, manager, team member, brand user.
- Per-brand access scoping for team members and brand users.
- Email invitation flow with token-based acceptance.
- Audit log of sensitive actions.
- Optional MFA (TOTP), mandatory for master admin.

### In scope — Phase 2 (deep analytics)
- Per-brand ad performance breakdown: campaign → ad set → ad.
- Automated rule-based flagging (scale candidates, underperformers).
- Per-brand product performance (revenue and refunds by SKU).
- Per-brand store audit cards (site speed, broken events, checkout funnel).

### In scope — Phase 3 (ticketing)
- Brand-side ticketing with attachments and categories.
- Internal triage inbox with internal-only comment notes.
- Two-way sync between platform tickets and one external task tool.

### Explicitly out of scope — Phase 1
- Email marketing data (Klaviyo, Mailchimp). Deferred.
- Mobile native app. Web-only and responsive.
- White-label / multi-tenant SaaS distribution.
- Predictive ML features (forecast revenue, predict churn). Not in any planned phase.
- Inventory or fulfillment data from Shopify.
- Billing / subscription / invoicing for brands.

## Phase 1 weekly breakdown

| Week | Deliverable |
|------|-------------|
| 1–2 | Foundations: repo setup, CI/CD, Postgres schema, Sanctum auth, brand CRUD. PlatformAdapter interface skeleton. |
| 3–4 | Shopify integration end-to-end: custom unlisted app, OAuth flow, RevenueFetcher with refund-aware logic, SyncBrandDayJob skeleton, 7-day rolling backfill. |
| 4 (end) | Internal demo: 5 test stores connected. Owner uses it daily from here. |
| 5 | Onboard remaining ~95 Shopify stores. Fix bugs surfaced by real volume. |
| 6–7 | Meta integration: System User token, available-accounts lister, InsightsFetcher, ROAS calc. |
| 8 | Google Ads integration: MCC OAuth, customer lister, daily spend sync. |
| 9 | TikTok integration: Business Center setup, advertiser lister, daily spend sync. |
| 10 | Date range picker, drill-in pages, sync health UI, polish, hand-off documentation. |

## Glossary

| Term | Meaning |
|------|---------|
| Brand | A single Shopify store and its associated ad accounts on Meta/Google/TikTok. |
| Connection | A `platform_connections` row linking one brand to one platform with stored credentials and an external account ID. |
| Snapshot | A `MetricSnapshot` DTO returned by `PlatformAdapter::fetchDay()`. |
| Manager-level token | A single agency-owned token (Meta System User, Google MCC refresh token, TikTok BC token) that can query any client account under it. |
| Blended ROAS | `revenue_net / total_ad_spend`, across Meta + Google + TikTok. |
| Hot brand | Top-20 by spend; syncs hourly during business hours, not just daily. |
| 7-day rolling backfill | Daily sync re-pulls yesterday plus the 6 prior days to capture late refunds. |
| Master admin | Sole top-level user. The agency owner. Cannot be impersonated. |

## Project decisions already made

See the [docs index](../README.md#non-negotiables) for the locked-in set.
