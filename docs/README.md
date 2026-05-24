# Helm — documentation

Multi-brand analytics and operations platform. Internal Nova Solution build for a retainer agency client managing 100+ Shopify stores across EU, GCC, and US markets.

This folder is the structured source of truth. The original signed spec (`Spec_MultiBrand_Platform.docx`) is the contract; these pages are its working form, split so each topic can be edited and linked without scrolling a 30-page document.

## How to read these docs

Start at [00-overview](./00-overview/README.md) for what Helm is and what each phase ships. Then jump to whichever section the work in front of you needs. Every section's `README.md` is its own table of contents.

## Index

| # | Section | What lives here |
|---|---------|----------------|
| 00 | [Overview](./00-overview/README.md) | Executive summary, scope (in/out), glossary, phased roadmap |
| 01 | [Architecture](./01-architecture/README.md) | Layer diagram, read/write paths, **platform adapter contract** |
| 02 | [Tech stack](./02-tech-stack/README.md) | Locked stack, required PHP / Composer / npm packages |
| 03 | [Database](./03-database/README.md) | Phase-by-phase schema, indexes, JSONB columns |
| 04 | [API](./04-api/README.md) | Endpoint reference grouped by domain, auth contract |
| 05 | [Platforms](./05-platforms/README.md) | Per-platform auth setup (Shopify, Meta, Google, TikTok) |
| 06 | [Sync](./06-sync/README.md) | Schedules, queues, `SyncBrandDayJob`, rate limits |
| 07 | [Frontend](./07-frontend/README.md) | Folder structure, routes, design tokens |
| 08 | [RBAC](./08-rbac/README.md) | Roles, policies, audit log (Phase 1.5) |
| 09 | [Onboarding](./09-onboarding/README.md) | Self-service brand onboarding flow |
| 10 | [Edge cases](./10-edge-cases/README.md) | Timezones, refunds, currency, attribution, missing data |
| 11 | [Deployment](./11-deployment/README.md) | Hetzner, Forge, backups, monitoring |
| 12 | [Acceptance](./12-acceptance/README.md) | Per-phase acceptance criteria |
| 13 | [Open questions](./13-open-questions/README.md) | Pre-kickoff confirmations needed from the client |

## Non-negotiables

These are decisions the spec has already made. Treat them as fixed and don't re-litigate without a written change-request.

- **Platform adapter pattern.** Every ad and commerce platform (current and future) plugs in through one PHP interface. See [01-architecture](./01-architecture/platform-adapter.md).
- **Polymorphic `daily_metrics` table.** One table, all platforms. Not per-platform tables. See [03-database](./03-database/phase-1.md).
- **One `SyncBrandDayJob`.** Platform-agnostic, resolves the right adapter at runtime. See [06-sync](./06-sync/README.md).
- **Phase 1.5 (RBAC) goes before Phase 2.** Retrofitting authorization is multiple times harder than building it in front of one feature.
- **Manager-level auth model.** One Meta System User, one Google MCC, one TikTok BC, per-store Shopify OAuth. Three platform-level tokens cover all 100+ brands.
- **Native currency stored AND `fx_rate_to_usd` snapshotted at sync time.** Never convert at read time without storing the rate used.
- **Brand timezone is authoritative** for every `daily_metrics.date`. Never UTC.
- **Missing data ≠ zero.** Failed syncs leave `is_complete=false` and render amber, never as €0.
- **Meta default attribution window:** 7-day click only.
- **Sweden excluded** from EUR aggregations.
- **Phase 3b integrates one external task tool** (ClickUp, Linear, or Asana). Never builds an internal one.

## Status

- Spec version: 1.0 (May 2026)
- Build: not yet started
- Total scope: 22 working weeks across 5 phases
