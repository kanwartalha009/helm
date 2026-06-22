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
| D-016 | 2026-06-21 | Introduce an **LLM for report strategy/narrative** (rules own the numbers, LLM owns the narrative, edited before send) | §02 (outside the locked stack) | Per-brand strategy to improve conversions. LLM gets a read-only scoped query layer, never writes figures | OPEN — provider + cost + data-privacy decision pending |

## When to split this into files

One table is enough today. When a single decision needs real depth (context,
options weighed, consequences), give it its own `D-0XX-slug.md` and leave the row
here as the index. Keep the table as the at-a-glance map either way.
