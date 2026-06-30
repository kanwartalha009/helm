# D-017 — Productizing Helm: multi-tenant SaaS for other agencies

**Status:** Proposed — for planning (not ratified; no build started)
**Date:** 2026-06-29
**Question:** If we finish Helm and sell it to *other* marketing agencies, does the
tech stack need to change, and what does the architecture have to become?

---

## TL;DR

- **Keep the stack.** Laravel 11 / PHP 8.3 / MySQL / Redis / Horizon / React + Vite
  is a mainstream, proven multi-tenant SaaS stack. A rewrite buys nothing and
  costs months. **No language, framework, or database change is warranted.**
- **Evolve the architecture for multi-tenancy.** The real work is a tenant
  boundary on every table, per-tenant credentials, per-tenant white-label, billing,
  and self-serve onboarding — all *extensions* of patterns we already have.
- **At real scale, ADD, don't replace.** A columnar analytics store for the metric
  time-series, and a move off Cloudways to autoscaling cloud infra. Neither is
  needed before the low thousands of brands.
- **Alignment today:** ~70–80% for a single large agency; **100% on stack choice**.
  The gap to "sellable" is tenancy + billing + onboarding + hardening, not a rewrite.

---

## Tech stack — what Helm actually runs on

> **Correction for the record:** Helm's backend is **PHP / Laravel**, *not* Python.
> There is no Python, FastAPI, Render, Supabase, or Snowflake anywhere in Helm. Any
> "FastAPI / Python 3.11 / Render / Supabase" diagram is a *different* architecture
> proposal and does not describe this product.

**Languages**
- **PHP 8.3** — backend
- **TypeScript** — frontend
- **SQL** (MySQL dialect) — queries, ShopifyQL for Shopify analytics
- Bash — deploy script

**Backend**
- **Laravel 11** — application framework
- **Laravel Horizon** — queue workers + monitoring (sync jobs run here)
- **Laravel Sanctum** — API token auth
- **Eloquent** — ORM
- **Guzzle** — HTTP client for every external platform call
- **google-ads-php** (v33, REST transport — no `ext-grpc`, runs on Cloudways)

**Frontend**
- **React 18** + **Vite** (build) + **TypeScript**
- **Tailwind CSS** — styling
- **TanStack Query** (server state) + **TanStack Table** (data grids)
- **Recharts** — charts
- **Axios** — API client
- White-label report rendering (Fraunces / Inter / JetBrains Mono), browser print → PDF

**Data + infra**
- **MySQL** — primary datastore (D-001; ratified over PostgreSQL 16)
- **Redis 7** — cache + queue backend
- **Cloudways** — managed hosting (D-002; ratified over Hetzner + Laravel Forge)
- Deploy: `scripts/deploy.sh` (build SPA → publish → `migrate` → cache → Horizon restart)

**External integrations** (all through the PlatformAdapter pattern)
- **Shopify** — Admin GraphQL + **ShopifyQL** (analytics) + REST
- **Meta Marketing API**
- **Google Ads API** (via google-ads-php, REST)
- **TikTok Marketing API v1.3** (REST)

**Open / planned**
- **LLM provider** for the report narrative layer — undecided (D-016)
- Multi-tenant additions for SaaS (see below): Laravel Cashier + Stripe (billing),
  and at scale a columnar analytics store (ClickHouse / Postgres + TimescaleDB)

## Why the stack stays

Three current decisions make the existing stack the right foundation rather than a
liability:

1. **The platform-adapter pattern** (`config/platforms.php` → `PlatformRegistry` →
   per-platform adapters). Adding a platform is one class + one config line —
   proven with TikTok. This is product-grade extensibility.
2. **`PlatformCredentialService` is the single credential chokepoint.** Every
   adapter resolves creds through it, never `env()` directly. Per-tenant
   credentials become a change in *one* place, not forty.
3. **We already do row-level scoping.** The RBAC brand-access global scope
   (`accessibleBrandIds`) means every query already filters by "who may see this".
   Multi-tenancy is the *same pattern one level up* — a `tenant_id` global scope.
   We are extending an existing, working isolation pattern, not inventing one.

The report engine (ReportType registry, white-label branding, freshness gate, share
tokens) and the granular data layer (commerce / campaign / inventory) are the
genuinely productizable assets — the part another agency pays for.

## Decision: tenancy model

**Single database, row-level multi-tenancy** — a top-level `tenant` (agency) entity,
a `tenant_id` on every table, enforced by a global scope (the same mechanism as the
brand-access scope).

Options weighed:

| Option | Verdict | Why |
|--------|---------|-----|
| **Single DB, row-level `tenant_id` + global scope** | **Chosen** | Simplest migrations + ops; reuses the scope pattern already in the codebase; one connection pool. |
| DB-per-tenant (e.g. `stancl/tenancy` multi-DB) | Rejected (for now) | Stronger isolation, but migrations across N databases, connection sprawl, and backup/restore complexity outweigh the benefit at our scale. Revisit only if a large customer demands physical isolation. |
| App-instance-per-client (today's de-facto: one deploy per agency) | Rejected | Doesn't scale operationally; every agency is a server + a deploy. This is exactly what productizing must end. |

**Consequence — this is the highest-blast-radius change.** A scoping bug that leaks
one agency's data into another's is catastrophic. Tenant scoping must be enforced by
a global scope *and* covered by first-class tests (a tenant can never read another
tenant's brands, metrics, reports, or credentials). Treat tenant isolation as a
security boundary, not a convenience filter.

## Migration: single-tenant → multi-tenant

Ordered by dependency. None of it is a stack change.

1. **Tenant entity + `tenant_id` everywhere.** Introduce `agencies` (tenants) above
   the current workspace. Backfill the existing data as tenant #1 (Nova/Roasdriven).
   Add a `tenant_id` global scope mirroring `accessibleBrandIds`.
2. **Per-tenant credentials.** `platform_credentials` is already keyed by
   `(platform, key)` — add `tenant_id`. Each agency connects their *own* Meta BM,
   Google MCC, TikTok BC, and Shopify app. The shared-token model (1 Meta SU / 1 MCC
   / 1 BC for the whole platform) is the single most single-tenant assumption to
   unwind, and the credential service is where it unwinds.
3. **Per-tenant white-label + custom domains.** Report branding is already a
   workspace setting feeding the report engine — make it tenant-scoped and add
   custom-domain routing (agency reports served on the agency's domain).
4. **Billing + plans** (Laravel Cashier + Stripe). Plan dimensions: brands cap, sync
   cadence, seats, feature flags (e.g. LLM narrative on/off). Meter + enforce.
5. **Self-serve onboarding.** Sign up → connect platforms → add brands, with no
   hand-holding. Today Nova provisions each agency by hand; that has to become a flow.

## Scale plan ("MVP-SaaS → scale") — add, don't replace

- **Metric tables are the one genuine scaling concern.** `daily_metrics` +
  `commerce_daily_metrics` + `ad_campaign_daily_metrics` grow per-brand-per-day;
  campaign-level daily across thousands of brands over years reaches billions of
  rows. MySQL carries this a long way with **partitioning + tight indexes** (into the
  low thousands of brands). When analytical aggregates strain, **add** a columnar
  store (ClickHouse, or Postgres + TimescaleDB) for the time-series and keep MySQL
  transactional. Do not do this preemptively — it's a "when you feel it" move.
- **Sync scales horizontally** (more Horizon workers), and rate limits are
  per-tenant-token — each agency's own tokens mean no shared API ceiling. Grow the
  worker fleet with load.
- **Hosting is what won't scale.** Cloudways is right for one agency, wrong for a
  SaaS. Move to AWS/GCP: autoscaling app servers, managed MySQL (Aurora/RDS), managed
  Redis (ElastiCache), a worker fleet, object storage for report PDFs/assets. Same
  stack, real infra.
- **Observability becomes mandatory.** Today we diagnose via artisan commands + logs.
  A product needs error tracking (Sentry) + sync-health alerting (Datadog or similar)
  — you cannot babysit each agency's sync over chat.
- **Compliance.** Tenant isolation tests, then SOC 2; GDPR + EU data residency for
  European brands (which Roasdriven's portfolio already is).

## Honest gaps before it's sellable

- Single-tenant in its bones (the shared-token model above).
- **Test coverage is smoke-level** — fine for a hand-held agency, not for self-serve
  customers. The integration layer especially needs a real test harness; we have
  been firefighting sync edge-cases (zero-day fills, ShopifyQL page caps, a dead
  TikTok endpoint, getaddrinfo exhaustion) one at a time.
- Observability is logs-and-artisan.
- **The LLM analyst layer (D-016) is still open** — and it is the headline value
  prop. Without it the product is a very good dashboard; with it, it's an analyst.
  It gates the product's *value*, not its feasibility. At multi-tenant scale it also
  becomes a per-tenant cost + data-privacy decision (zero-retention / no-training
  agreement, per-tenant spend caps).

## Phased roadmap

**Phase 0 — today.** Single-tenant, one agency (Roasdriven), 73 brands. ✓

**Phase A — MVP SaaS (productize).** Tenancy layer + `tenant_id` scoping (with
isolation tests), per-tenant credentials + connect flows, per-tenant white-label +
custom domains, billing (Cashier/Stripe), self-serve onboarding, and a real
integration test harness + error tracking. Stay on a beefier single cloud
environment. **Goal:** onboard a 2nd and 3rd agency entirely self-serve.

**Phase B — Scale.** Cloud infra (autoscaling, Aurora, ElastiCache, worker fleet),
columnar analytics store for the metric time-series, object storage, SOC 2, GDPR /
regional data residency. **Goal:** tens–hundreds of agencies, thousands of brands.

**Cross-cutting:** resolve D-016 (LLM) — it's the differentiator and should land
before or alongside Phase A so the product sells on "AI analyst," not "dashboard."

## Consequences

- No rewrite; the team keeps its velocity and the existing codebase.
- The big lift (tenancy) is bounded and reuses an existing pattern, but its
  correctness is a security boundary and must be tested as such.
- Cloudways and the smoke-level test suite are the two things that must change before
  a third party trusts the product with their ad accounts.
- The architecture decisions that age well — adapters, credential service, row-level
  scoping, report registry — are exactly the ones a product is built on. We are
  well-positioned; the distance is evolution, not reinvention.
