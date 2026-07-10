# Helm productization audit — D-017 vs the code (2026-07-10)

Goal per Kanwar 2026-07-10: make Helm the single window for ANY ads agency (multi-tenant SaaS), per D-017 (Proposed) and the 2026-07-02 white-label decision (buyer = other agencies; build the tenant seam opportunistically; defer domains/billing until a second agency signs).

Basis: 23 tables counted from `Schema::create` across 35 migrations; every count below re-verified against the repo on 2026-07-10. Production-only values (e.g. active connections per brand) are marked estimate with the exact command to get the real number.

## A. Verdict on D-017's claims vs code

| Claim | Holds? | Evidence |
|---|---|---|
| Stack is Laravel/MySQL/Redis/Horizon/React; no rewrite needed | Holds | api/config/horizon.php, api/config/platforms.php, web/ (Vite/TS/TanStack) |
| `PlatformCredentialService` is the single credential chokepoint; adapters never call `env()` | Holds | All 6 Meta/Google/TikTok clients+adapters inject it; env fallback lives inside the service (api/app/Services/PlatformCredentialService.php:27-42, 55-87) |
| "We already do row-level scoping" — tenancy is the same pattern one level up | Partial | Scope exists (api/app/Models/Brand.php:121-131) but master_admin AND manager bypass it entirely; a tenant boundary can never have a bypass role, and 9 of 23 tables carry no scoping column at all |
| `platform_credentials` "already keyed by (platform, key) — add tenant_id" | Partial | Keyed as claimed, but unique index `platform_credentials_active_unique` permits ONE active row per (platform, key) install-wide (migrations/2026_01_01_000007:38-50) — it must be replaced, not extended; the one non-additive schema step |
| "Rate limits are per-tenant-token — no shared API ceiling" | Not today | One active Meta system_user_token for the whole install; backoff is in-process `sleep()` (api/app/Platforms/Meta/MetaClient.php:113-161), no cross-worker limiter |
| "~70–80% aligned" | Misleading | Grep for `tenant_id\|workspace_id\|agency_id\|organization_id` across migrations + app: 0 hits. No tenants table, 0 billing code, no signup route. As stack alignment it holds; as distance-to-sellable the gaps are categorical (absent), not percentage-shaped |
| Test coverage "smoke-level" (honest gap) | Holds | 6 test files, 29 test methods total (api/tests) |
| Phase 0 "73 brands" | Stale | AS-BUILT.md says ~80; minor, but D-017 should track it |

## B. Tenancy gap inventory

| Area | Current state (cited) | Multi-tenant needs | Effort |
|---|---|---|---|
| Tenant entity | None. `brands` is the root object (no owner column); `workspace_settings` is a key/value singleton whose migration says "shared across the whole tenant since Helm is single-tenant by design" (migrations/2026_01_02_000001) | `agencies` table + `agency_id` on the 5 root tables (brands, users, platform_credentials, audit_logs, invitations) + per-tenant settings; the brand_id-scoped metric tables (daily_metrics, commerce/ad metric tables, sync_logs, report_shares…) scope through brand→agency, avoiding retrofit of high-volume tables | M |
| Scope enforcement | Brand `access` global scope filters only team_member/brand_user via `brand_user_access`; master_admin and manager see everything (Brand.php:121-131) | Non-bypassable TenantScope on every root model + first-class isolation tests (currently zero) | M |
| Credentials | Meta/Google/TikTok are install-global singletons with `env()` fallback (META_SYSTEM_USER_TOKEN etc., PlatformCredentialService.php:27-42); Shopify partner app key/secret global, store tokens per-brand in `platform_connections.credentials` (migrations/2026_01_01_000003) | Per-tenant credential rows + per-tenant connect flows; kill env fallback beyond tenant #1; `get(platform, key)` gains a tenant argument | M–L |
| Queues / rate limits | 18 fixed workers (4 default / 8 shopify / 4 ads / 2 aggregation, horizon.php:110-115); `sync:daily` at 01:00 + 13:00 UTC dispatches brands × active connections × 7 days (RunDailySyncCommand.php:69-83). Jobs per run = brands × avg active connections × 7: at 80 brands and ~2 active connections each that is ~1,120 jobs (estimate — real value: `SELECT COUNT(*) FROM platform_connections WHERE status='active'` × 7). Single FIFO queues; `POST sync/all` fans out the entire install (routes/api.php:155). At 5 agencies × 100 brands with the same ~2 connections, ~7,000 jobs per run (linear in connections; 4 platforms connected → ~14,000) into the same 12 sync workers — one agency's backfill starves everyone, and today they'd share one Meta token's rate ceiling | Per-tenant queue fairness (tagged queues or Laravel job rate-limiting keyed by tenant+token), Redis token bucket per (tenant, platform), worker fleet sizing | M |
| White-label | 38 "Roasdriven" hits across 24 web files incl. index.html title, Wordmark.tsx, login/MFA pages; report theme exists but is one `report_branding` settings row with hardcoded fallback (WorkspaceSetting.php:30, ReportController.php:112) | Tenant theme covering app shell + reports + custom domains; the reporting slice 2.0 theme object is the right vehicle | S–M |
| Billing / metering | Zero. Greps for stripe/cashier/subscription return only false positives (`billing_country`, "action plan") | Cashier + Stripe, plan limits (brands/seats/cadence), metering + enforcement | M |
| Onboarding | No register route — auth group is login/MFA/password/invitation-accept only (routes/api.php:26-40); first admin via artisan `CreateAdminCommand`; OnboardingPage.tsx is a user-profile wizard, not agency signup | Agency signup → provisioning → guided platform-connect (Meta SU, Google MCC + dev token, TikTok BC, Shopify install) → first brand | M–L |
| Global surfaces | Audit log and sync health query install-wide with no tenant scoping (AuditLogController.php:34-38, SyncStatusController::index) | Tenant-scoped queries + per-tenant sync health; Sentry-class error tracking | S–M |

## C. Phased plan (does not stall Phase 2 reporting)

| Phase | Goal | Work items | Exit criteria | Client-phase dependency | Effort (estimate) |
|---|---|---|---|---|---|
| T0 — tenant-ready foundations | Prod becomes "tenant #1" with zero behavior change | Additive `agencies` table; nullable `agency_id` on 5 root tables, backfilled; non-bypassable TenantScope (dark-launched behind flag); isolation test suite (the first real harness); tenant-aware `PlatformCredentialService` signature; convert settings singleton to per-tenant rows | Scope flag ON in prod; Bosco notices nothing; cross-tenant read tests green in CI | Land before reporting slices 2.2–2.4 add more tables (brand-derived scoping keeps new metric tables cheap); reuse slice 2.0's per-agency theme object as the tenant theme | 4–6 eng-wk |
| T1 — first paying external agency | One design-partner agency on its own tokens | Per-tenant credential UI + connect flows; replace `platform_credentials_active_unique`; de-hardcode the 24 branded SPA files behind tenant theme; agency provisioning (invite-only, founder-assisted); per-tenant queue tags + Redis rate limiter; Sentry; tenant-scoped audit/sync health; manual invoicing (no Stripe yet) | External agency syncing on own Meta/Google/TikTok/Shopify creds, paying (manually), zero leakage incidents | D-016 LLM privacy terms signed before their data flows; reporting engine (2.0–2.2) is the demo | 6–8 eng-wk |
| T2 — scale hardening / self-serve | Stranger agencies without founder touch | Cashier + Stripe plans + metering/enforcement; public signup + agency onboarding wizard; move off single Cloudways box (autoscaling, managed MySQL/Redis); shared token-bucket limiter; metric-table partitioning; SOC 2 track | Self-serve signup→connect→pay→sync end to end; 5 agencies concurrent without sync degradation | None — client roadmap done or parallel | 8–12 eng-wk |

Riskiest migration step: flipping the TenantScope to enforcing on the live 80-brand DB plus swapping the `platform_credentials` one-active-row unique index — the only place D-001's additive-only rule cannot hold (index replacement). Mitigate: dark-launch scope with logging-only mode, snapshot backup, staged index swap in one maintenance window.

## D. Open decisions before any productization code

- Shopify app model: one shared Helm partner app (app-review, embedded) vs each agency bringing its own custom app credentials.
- Google Ads developer token: per-agency tokens (each applies, weeks of lead time) vs Helm applying for platform-level standard access.
- Deployment topology: external tenants on the same prod DB as Bosco's live data, or a separate SaaS install with Roasdriven migrating in later.
- Pricing dimensions (per brand / per seat / flat) — determines what T0 must put in the schema and T2 must meter.
- D-016 LLM provider + per-tenant data-privacy terms — must be contractual before external tenant #1 syncs.
- Confirm row-level single-DB isolation (D-017's choice) as the sellable guarantee, or DB-per-tenant if design partners demand physical isolation — decides the T0 schema.
