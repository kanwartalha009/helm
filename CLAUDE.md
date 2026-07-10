# Helm — Claude Code instructions

@AGENTS.md

## Current truth vs contracted target

1. Current reality = `docs/AS-BUILT.md` + `docs/decisions/README.md` (ADR log D-001+).
   The numbered spec (`docs/00–13`) is the contracted target, NOT what runs today.
   Never claim "spec says X so the code is wrong" without checking the ADR log first
   (example: MySQL is correct per D-001, even though the spec says PostgreSQL).
2. Production is LIVE (since 2026-06-01, Cloudways + MySQL, ~80 real brands, used
   daily). Database migrations must be additive and non-destructive. No destructive
   resets, ever.
3. An ambiguous request gets ONE focused clarifying question before code is written.
   A clear request gets a one-line restatement + the governing spec/ADR section named,
   then the build. Use the `clarity-first` skill.

## Repo map — start here, do not re-explore

- `api/` — Laravel 11 backend.
  - `app/Platforms/{Shopify,Meta,Google,TikTok}/` — ALL external HTTP lives here
    (adapters). No Guzzle calls anywhere else.
  - `app/Jobs/` — sync jobs (SyncBrandDayJob is the platform-agnostic unit).
  - `app/Http/Controllers/Api/` — API controllers. `routes/api.php` — all routes.
  - `app/Services/`, `app/Reports/`, `app/Support/` — domain logic.
  - `database/migrations/` — every table lives here.
- `web/` — React 18 + Vite + TypeScript SPA.
  - `src/routes/` — one file per page. `src/components/` — shared UI.
  - `src/lib/` — api client. (`mockApi.ts` was deleted 2026-07-10 — every page
    reads the real API; do not reintroduce fixture data on shipped pages.)
  - `src/types/domain.ts` — shared domain types.
- `docs/` — spec `00–13`, `AS-BUILT.md`, `decisions/` (ADRs), `feature-specs/`.
- `audits/` — dated audit reports. `scripts/deploy.sh` — the only deploy path.
- Root prompts (`AUDIT_PROMPT.md`, `SHOPIFY_INTEGRATION_PROMPT.md`, …) are operator
  playbooks, not app code.

## Numbers and facts — zero-hallucination protocol

- Any count, metric, or line number stated about this repo must come from a command
  run in THIS session (`grep -c`, `wc -l`, `php artisan route:list`, …). Show the
  command next to any number that matters. Use the `verified-numbers` skill.
- Production/DB numbers are not visible from this machine. Never estimate them —
  give Kanwar the exact SQL or artisan command to run and wait for the output.
- Percentages must decompose into a counted numerator and denominator.
- A number that cannot be verified is written as "unverified", not guessed.

## Definition of done — proof step is mandatory

- Backend change → `cd api && php artisan test` (or the targeted test) passes.
- Any frontend or shared-type change → `cd web && npx tsc --noEmit` passes.
  deploy.sh HARD-ABORTS on tsc errors and silently keeps serving the OLD bundle —
  a tsc failure means the client sees stale code with no error anywhere.
- Dashboard-engine changes → `php artisan test --filter=DashboardEnginesTest`
  must pass, and `php artisan helm:dashboard-parity` must report PARITY OK
  before HELM_DASHBOARD_ENGINE=set is flipped or changed.
- Never report a task complete without naming the proof command and its result.

## Git hygiene

- Commit messages: `type: imperative summary` (feat / fix / chore / docs / refactor).
  One logical change per commit. Single-letter messages are banned.
- Never commit `.env*` files or credentials. Never force-push.

## Fast verification commands

```bash
cd api && php artisan test            # backend tests
cd api && php artisan route:list      # real route inventory
cd web && npx tsc --noEmit            # type gate (deploy blocker)
cd web && npm run build               # production bundle
```
