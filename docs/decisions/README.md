# Decisions

Ratified decisions that deviate from, or extend, the signed spec (`docs/00–13`).
The spec stays **intact** as the contracted vision and the architecture we build
toward; this log records where the running build has knowingly diverged, and why.

Rule: a new deviation gets a row here the same day it is ratified. The spec is
never silently overwritten — if reality and a spec section disagree, this log is
the tiebreaker for "what is true today," and the spec remains the tiebreaker for
"what we are building toward."

| ID | Date | Decision | Deviates from / extends | Why | Status |
|----|------|----------|-------------------------|-----|--------|
| D-001 | 2026-06-01 | Database is **MySQL**, not PostgreSQL 16 | §02 | Cloudways host has no managed Postgres. JSONB → JSON columns; window-function deltas done in PHP where needed | Live. DB changes additive / non-destructive only |
| D-002 | 2026-06-01 | Hosting is **Cloudways**, not Hetzner + Forge | §02, §11 | Operational choice. Deploy via `scripts/deploy.sh`; see `DEPLOY_CLOUDWAYS.md` | Live |
| D-003 | 2026-05-31 | Sync = **12h `sync:daily` + per-brand manual + master manual**, all kept | §06 (was "13:00 UTC daily") | Operators need on-demand re-sync; queued `sync_log` row written at dispatch | Live — `specs/CHANGE_REQUEST_2026-05-31_sync.md` |
| D-004 | 2026-06-01 | **USD view stays in Phase 1** | §00 scope | Client needs the blended USD column from day one | Live |
| D-005 | 2026-06-19 | Default revenue metric = **Total revenue** (Shopify `total_sales`, via ShopifyQL) | §00, §10, §12 (was gross/net, order-based) | Bosco-approved; matches Shopify Admin "Total sales" to the cent | Live |
| D-006 | 2026-06-20 | **Net sales hidden** in the UI everywhere (reversible, commented) | §00, §12 | Bosco doesn't use it; sync still pulls + stores it | Live |
| D-007 | 2026-06-19 | **Blended ROAS follows the selected metric** (net / total) | §00 glossary | Bosco-approved | Live |
| D-008 | 2026-06-19 | Dashboard **soft-defaults to "my brands"** + brand-manager filter | §08 | Better default for a multi-manager agency than "all brands" | Live |
| D-009 | 2026-06-19 | **MFA enforced via the SPA AuthGate** (not the login flow); master_admin mandatory | §08 | Enforce without touching the token path. Currently gated OFF pending server NTP clock sync | Built, disabled |
| D-010 | 2026-06-19 | **Year-over-year comparison** (revenue; extended to + spend + ROAS on 2026-06-20) | extends §12 | Bosco pain point: this period vs the same dates last year | Live; needs `shopify:backfill-sales` + `ads:backfill-spend` |
| D-011 | 2026-06-20 | **Historical ad-spend backfill** (`ads:backfill-spend`, Meta + Google, monthly-chunked) | new | Powers YoY spend/ROAS; no last-year ad data existed in-system | Live |
| D-012 | 2026-06-20 | **UI brand name = "Roasdriven"** (client-facing); internals stay "Helm" | new | Client rebrand. `api/`, repo, namespaces untouched | Live |
| D-013 | 2026-06-20 | **Delivery = incremental, client-validated feature releases** targeting agency pain points | changes the *delivery method*, not the §00 architecture | Ship one feature → Bosco feedback → next. The spec phases stay the target; sequence/pace is now feedback-driven | Active |
| D-014 | 2026-06-21 | **Build reporting + creative-intelligence in-platform, replacing Motion** — all figures inside Helm | extends §00 Phase 2 | Kill the €3k/mo + 5-person Motion cost; agency generates branded reports in minutes | Active — spec `feature-specs/reporting-and-creative-intelligence.md` |
| D-015 | 2026-06-21 | Creative **video: embed + refresh URL on expiry; store thumbnails only** (no video storage) | new | Video storage too costly at scale. Live dashboard refreshes the source; already-sent PDFs/links fall back to the thumbnail | Ratified |
| D-016 | 2026-06-21 | Introduce an **LLM for report strategy/narrative** (rules own the numbers, LLM owns the narrative, edited before send) | §02 (outside the locked stack) | Per-brand strategy to improve conversions. LLM gets a read-only scoped query layer, never writes figures | **Ratified 2026-07-10**: provider-agnostic (Anthropic default, OpenAI selectable via `HELM_LLM_PROVIDER`; plain Guzzle, no SDK — §02 lock holds); privacy = **aggregates only** through the single `BrandDataScope` boundary (no customer rows exist in-schema; scope shape is CI-tested); both surfaces shipped (report narrative blocks + per-brand chat), operator-triggered only, admin/manager-gated, always edited before send; keys in `platform_credentials` (Settings UI) with env fallback |
| D-017 | 2026-06-29 | **Productize Helm as a multi-tenant SaaS for other agencies** — keep the stack (Laravel/PHP/MySQL/Redis/React), evolve the architecture for tenancy | extends §00, §01, §02 | Strategic: sell to other agencies. Stack stays; the work is tenant scoping + per-tenant creds + billing + onboarding, and add-not-replace at scale. Includes the canonical tech-stack reference | Proposed — `D-017-productization-multi-tenant.md` |
| D-018 | 2026-07-10 | **Sync schedule as-built ratified**: `sync:shopify-rolling` (today+yesterday) scheduled twice daily at **03:00 + 15:00 UTC**; `sync:hourly` (top-20 hot brands) **retired** | CR 2026-05-31, §06 | Rolling was approved but never wired when the Laravel 11 scheduler landed — twice-daily `sync:daily` silently took the 01:00/13:00 slots and "today" tiles stayed stale. 03:00/15:00 (not the CR's 01:00/13:00) because both commands skip brands with queued/running sync_logs (30-min idempotency): co-firing starves one of them. Hourly retired: 12h + manual proved sufficient since 2026-06-01 and no top-20-by-spend ranking source was ever defined | Ratified 2026-07-10 |
| D-019 | 2026-07-10 | **Spec §12 date-range picker (MTD/QTD/custom) superseded** by rolling 7/30/90-day windows + YoY comparison (comparison already covers MTD and last month) | §12 | Bosco-driven evolution shipped under D-013 (see D-008/D-010 and the 2026-07-02 rolling-interval change); this row makes the supersession explicit — the spec ranges are dead, not deferred | Ratified 2026-07-10 |
| D-020 | 2026-07-10 | **Stub endpoints deleted**: `GET /api/dashboard/summary` (hardcoded zeros) and `GET /api/brands/{id}/trend` (empty series) removed with their controller/service methods | §04 | Live routes returning placeholder data with zero SPA consumers are a wrong-number surface. Re-add with real `daily_metrics` implementations when a feature needs them (D-013) | Ratified 2026-07-10 |

## When to split this into files

One table is enough today. When a single decision needs real depth (context,
options weighed, consequences), give it its own `D-0XX-slug.md` and leave the row
here as the index. Keep the table as the at-a-glance map either way.
