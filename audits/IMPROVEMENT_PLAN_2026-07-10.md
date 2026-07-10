# Helm — development improvement plan — 2026-07-10

Companion to `AUDIT_2026-07-10.md` (platform findings) and `PRODUCTIZATION_GAP_2026-07-10.md` (multi-tenant path). This file covers the development PROCESS: how work gets built, verified, and shipped. Every fact cited was measured on 2026-07-10.

## Why Claude Code has felt slow — diagnosis

Measured state of the repo before today: 42,001 files on disk (189M api/vendor + 196M web/node_modules), 484 actual code/doc files, and ZERO Claude Code configuration — no CLAUDE.md, no .claude/ directory, no settings, no skills. `.gitignore` is solid, so file search was never the problem. The latency came from three places:

1. **Permission prompts.** With no `.claude/settings.json` allowlist, every shell command a session runs waits on manual approval. Ten commands = ten waits on you. This is the single biggest "commands take long" factor in attended sessions.
2. **Cold-start re-exploration.** With no CLAUDE.md, every session re-derives the repo layout, the ADR-overrides-spec rule, and the verification commands by reading files — multi-minute turns before any real work starts, and re-reading 33 spec docs burns context that then slows every later turn.
3. **Session-level context bloat.** Long sessions accumulate stale file reads; without a repo map to jump straight to the right file, exploration compounds.

## Shipped today (already in the repo once you commit)

- `CLAUDE.md` — repo map, ADR-before-spec rule, zero-hallucination number protocol, definition-of-done proof steps, commit convention. Kills cause 2.
- `.claude/settings.json` — pre-approves read-only/test/build commands (git status/diff/log, grep, php artisan test, npx tsc, npm run build); denies .env reads, `migrate:fresh/reset`, `db:wipe`, force-push. Kills cause 1 for ~80% of commands while keeping writes gated.
- `.claude/skills/clarity-first/` — restate goal → ADR log → AS-BUILT → spec → ONE clarifying question max → declare files + proof step. Run at the start of every non-trivial task.
- `.claude/skills/verified-numbers/` — three number classes: repo facts (measure in-session), production facts (never estimate — emit the exact command for Kanwar), judgment calls (label "estimate", show numerator/denominator).
- `.claude/skills/helm-audit/` — "audit" runs AUDIT_PROMPT.md with ADR-aware anomaly rules.

Also check on your side (not fixable from the repo): run `claude mcp list` — any MCP server configured globally on your Mac loads into EVERY Claude Code session and adds startup latency; scope servers to the projects that need them. And prefer plan mode for large tasks — one approved plan beats twenty approval prompts.

## P0 — stop the bleeding (this week)

| # | Action | Why now | Effort (estimate) |
|---|---|---|---|
| 1 | Rotate every credential in `.env.production.recovered`, then `git rm --cached .env.production.recovered` + add to `.gitignore` | Live production secrets (APP_KEY, DB, Redis, AWS, Sentry) are tracked in git and on origin. APP_KEY rotation requires re-encrypting TokenVault rows — plan that step, don't skip it | S–M |
| 2 | Fix the `ilike` → `like` bug (BrandController.php:31) | Latent 500 on MySQL brand search; one-line fix | S |
| 3 | Answer the Horizon vs `queue:work` question on the server (`ps aux | grep -E 'horizon|queue:work'` on Cloudways) | Decides whether the 18-worker concurrency model is real; everything in the scale plan depends on it | S |
| 4 | Schedule `sync:shopify-rolling` (or ratify its removal); decide `sync:hourly` | "Today" tiles are stale by design right now; CR 2026-05-31 says both should run | S |
| 5 | Get Cloudways to NTP-sync the clock, flip `HELM_REQUIRE_ADMIN_MFA=true` | Admin of a revenue system has had no MFA in production since 2026-06-01 | S |

## P1 — guardrails (next 2 weeks)

| # | Action | Why | Effort (estimate) |
|---|---|---|---|
| 1 | Adopt the commit convention from CLAUDE.md (`type: imperative summary`) | 134 of 146 commits are titled "a" — history is unusable for bisecting or auditing a live system | S (habit) |
| 2 | Extend CI: add a web job (`npx tsc --noEmit` + `npm run build`) beside api-ci.yml | deploy.sh aborts on tsc errors and silently serves the old bundle; CI should catch it before deploy | S |
| 3 | Feature tests for `DashboardQuery` math (missing-data-not-zero, FX, refund add-back) and `SyncBrandDayJob` lifecycle | 29 test methods guard a live product; the two highest-stakes rules have zero explicit tests | M |
| 4 | Add @sentry/react to the SPA | Client-side breakage is currently invisible unless Bosco reports it | S |
| 5 | Fix the USD FX fallback: un-backfilled rows should render amber/pending, not `COALESCE(fx_rate_to_usd, 1)` | A pending AED brand shows 3.67× overstated USD revenue with no warning — a wrong-number surface in front of the client | S–M |

## P2 — scale + debt (this month)

| # | Action | Why | Effort (estimate) |
|---|---|---|---|
| 1 | Rewrite `DashboardQuery::run()` set-based (GROUP BY brand_id, conditional aggregates) and re-test against the 800ms/100-brands bar | ~12 queries × brand per load (~960 at 80 brands) is the platform's biggest scale cliff; also relieves /reports and /inventory | M |
| 2 | Delete the mockApi hook chain (useDashboardData.ts) — port products/audit pages to real endpoints or gate them behind honest Phase-2 empty states; fix BrandAdsPage's mock `useBrand` | Three pages can show fabricated data to a paying client today | M |
| 3 | Wrap the app shell in ErrorBoundary (App.tsx) | One render error currently white-screens the dashboard | S |
| 4 | Invitation email (needs the email-provider decision — open question 7) | Invite links travel by copy-paste; blocks clean RBAC onboarding | S–M |
| 5 | Move adapter throttle waits from `sleep()` to job `release()` with delay | Sleeping workers block queue slots; matters as brand count grows | M |

## P3 — productization foundations (next month, 4–6 eng-wk)

Execute T0 from `PRODUCTIZATION_GAP_2026-07-10.md`: `agencies` table, `agency_id` on the 5 root tables, non-bypassable TenantScope dark-launched behind a flag, isolation test suite, tenant-aware credential service, per-tenant settings rows. Exit: scope enforcing in prod, Bosco notices nothing. The six open decisions in that file (Shopify app model, Google dev token, topology, pricing dimensions, D-016 LLM privacy, isolation guarantee) need answers before T1 code starts.

## Production numbers still pending (run these, paste outputs)

The audit could not see the production database. These three values complete the scale picture:

```bash
php artisan tinker --execute="
echo 'brands: '.\App\Models\Brand::where('status','active')->count().PHP_EOL;
echo 'active connections: '.\App\Models\PlatformConnection::where('status','active')->count().PHP_EOL;
echo 'daily_metrics rows: '.\Illuminate\Support\Facades\DB::table('daily_metrics')->count().PHP_EOL;"
```

And the last sync-run duration (first and last log of one run):

```sql
SELECT MIN(created_at), MAX(finished_at), COUNT(*) FROM sync_logs
WHERE created_at >= NOW() - INTERVAL 1 DAY AND status IN ('success','failed')
GROUP BY DATE(created_at), HOUR(created_at) ORDER BY 1 DESC LIMIT 4;
```
