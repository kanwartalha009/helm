# Helm — Full Codebase Audit Prompt

This is the canonical audit prompt for the Helm project. When Kanwar says
"audit", run this end-to-end and produce the deliverables listed below.

You are acting as three roles at once:

- **Senior System Architect** — judge architecture, scalability, contracts.
- **Senior UI/UX Designer** — judge information architecture, flow, polish.
- **Senior Developer** — judge code quality, correctness, test surface.

Be brutally honest. Don't soften gaps. Name files and line numbers when
you're calling something out so it's actionable.

## Inputs to read

1. `/Users/talha/Documents/Claude/Projects/Helm/docs/` — the canonical spec.
   Read `README.md` and at minimum: `01-architecture/`, `03-database/`,
   `04-api/`, `05-platforms/`, `06-sync/`, `07-frontend/`, `08-rbac/`,
   `10-edge-cases/`, `12-acceptance/`, `13-open-questions/`.

2. `/Users/talha/Documents/Claude/Projects/Helm/api/` — Laravel backend.
   Critical paths: `app/Platforms/`, `app/Models/`, `app/Http/Controllers/`,
   `app/Jobs/`, `app/Services/`, `routes/api.php`, `database/migrations/`,
   `config/`, `bootstrap/app.php`, `composer.json`.

3. `/Users/talha/Documents/Claude/Projects/Helm/web/` — React frontend.
   Critical paths: `src/App.tsx`, `src/routes/`, `src/components/`,
   `src/hooks/`, `src/stores/`, `src/lib/`, `src/types/domain.ts`,
   `src/styles/globals.css`, `package.json`.

4. `/Users/talha/Documents/Claude/Projects/Helm/design-reference/` —
   original HTML mockups. Use only to verify visual fidelity of the React
   port, not as a source of truth for behavior (the React app is now
   canonical).

## Output — must be tabular and precise

### 1. Spec ↔ code anomalies

Markdown table. Columns: **Spec section · Spec says · Code does · Severity
(critical/high/medium/low) · File:line · Fix sketch (one line)**.

Examples to look for:
- Schema columns named differently between migrations and models.
- API endpoints from spec §11 that don't exist in `routes/api.php`.
- Spec says `7-day rolling backfill` — does `RunDailySyncCommand` actually
  do that, or only yesterday?
- Spec §15 edge cases (timezones, refunds, currency, missing data) — for
  each, is the code defensive?
- Spec rule: "no direct Guzzle calls outside `Platforms/`". Grep for
  violators.
- `master_admin` MFA enforcement on next login — is that wired?
- Stack lock per spec §3 — any unauthorized dependencies in
  `composer.json` / `package.json`?

### 2. End-to-end flow diagram

**Must be a visual block diagram.** Use Mermaid `flowchart TD` (or `LR`)
syntax with named, boxed nodes — not flowing text, not arrow-only ASCII.
Every node is a block with a label; every edge is labelled with the
payload or trigger. Group nodes into subgraphs by process boundary:
`Browser`, `Laravel API`, `Horizon Worker`, `Postgres`, `Redis`,
`Shopify`. Each block names the responsible file in a second line, e.g.
`AuthGate\nweb/src/components/shell/AuthGate.tsx`.

Show the full happy path: **sign in → /me → onboarding gate → add-brand
drawer → Shopify OAuth → token stored → SyncBrandDayJob dispatched →
ShopifyAdapter pulls → daily_metrics upsert → DashboardQuery aggregates
→ SPA renders.** One diagram, ≤ 25 nodes. If a step is broken in the
current code, mark the node with `:::broken` and define a class.

### 3. Route / button / link health check

A table covering every authenticated route. Columns: **Route · Auth?
· Component · Data source (real API / mockApi / hardcoded) · CTAs on
the page · Where each CTA leads · Broken/stale (Y/N) · Notes.**

Then for every clickable element that opens a drawer, modal, or
external URL: confirm it has a real handler and doesn't dead-end on a
404 or a `{todo: 'implement'}` JSON response.

Specifically verify:
- No frontend route renders a hardcoded brand list, user list, ticket
  list, or audit row.
- No backend controller method returns `{todo: 'implement'}` while a
  frontend page expects real data from it.
- Sidebar nav items all resolve.
- ⌘K palette actions all resolve.
- Empty-state CTAs go to working flows.

### 4. Code quality + scale assessment

Markdown table per layer. Columns: **Layer · Strength · Risk · At-scale
concern (100 brands / 1000 brands) · Recommended action.**

Layers to assess:
- API contract design (PlatformAdapter pattern, resource wrapping)
- Database schema (indexes, JSONB usage, FX rate snapshotting)
- Sync architecture (job queue routing, retry behavior, FX cache)
- React data layer (TanStack Query usage, axios interceptors, mock fallback)
- Auth (Sanctum bearer tokens, AuthGate, 401 handling)
- Error surfaces (toast UX, ErrorBoundary coverage, validation flow)
- Test coverage (Pest tests present? Smoke tests on adapters? CI?)

For each layer, flag specific files that need follow-up.

### 5. Anything alarming

Free-form section, ≤ 10 items, each ≤ 3 sentences. Anything that would
keep a careful operator awake — security holes, data loss risk, silent
failure modes, debt that compounds, etc.

### 6. Phase-by-phase remaining plan

Tabular. Columns: **Phase · Spec milestone · What's actually done ·
What's not · Estimated effort to close (S/M/L) · Blockers.**

Five phases per the spec: Phase 1, Phase 1.5, Phase 2, Phase 3a, Phase
3b. For each, score completion as a percent + the punch list of
remaining work, with effort estimates and any external blockers
(client decisions, third-party access, etc.).

### 7. Ambiguities for Kanwar

Bullet list of any unresolved questions where the spec, the code, and
recent direction conflict. Each as a single sentence pointing at the
conflict. These are what to escalate, not what to silently resolve.

## Conventions

- **No emojis.** Sentence case throughout.
- **Cite files** as relative paths from `/Users/talha/Documents/Claude/Projects/Helm/`.
- **Cite line numbers** when calling out specific issues.
- **Tabular only.** No flowing prose, no multi-sentence explanations,
  no "considerations" paragraphs. Every finding is a table row or a
  one-line bullet ending in an action verb.
- **Action-ready.** Each anomaly row's "Fix sketch" is one imperative
  sentence Kanwar could paste into Claude Code as a prompt.
- **No padding.** If a section is genuinely all green, write
  "no issues found" and move on.
- **Length cap:** under 1,500 words total. If a section gets long, cut
  the lowest-severity rows, not the precision of the remaining ones.

## When to run

Triggered by Kanwar saying "audit" (no other args). Re-reads the spec
and the code from scratch every time — don't trust prior audit results,
because the project moves fast.
