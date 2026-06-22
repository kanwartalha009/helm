# Helm — structure audit (2026-06-20)

Scope: the project's documentation, spec, testing, and audit *structure* — not a
code-correctness pass (that is `AUDIT_PROMPT.md`). Verdict first, then findings,
the recommended target structure, and the sequence to get there.

## Verdict

The bones are good. `docs/` is a genuinely well-organised numbered spec with an
index, locked non-negotiables, per-phase acceptance, phased DB schemas, and a
clean platform-adapter contract — better organised than most internal builds.
The problems are not structural collapse; they are three things: **drift** (the
docs no longer describe the live system), **sprawl** (five overlapping doc
locations), and **two missing spines** — a testing/quality section and a
per-platform contract that ties code, diagnose, and tests together. None is hard
to fix. Left alone, each compounds every time the build moves and the docs do not.

## 1. Drift — docs vs the live system (highest-leverage issue)

| Area | Doc says | Reality | Sev | Fix |
|------|----------|---------|-----|-----|
| Stack | `docs/README.md:48`, `AGENTS.md:13` PostgreSQL 16 | MySQL (ratified) | High | Rebase the stack lock to MySQL; record the deviation + date. |
| Build status | `docs/README.md:49` "not yet started" | Live, ~80 brands | High | Replace with a living status line + changelog. |
| Hosting | `AGENTS.md:16` Hetzner CCX22 + Forge | Cloudways (`DEPLOY_CLOUDWAYS.md`) | High | Fold Cloudways into `11-deployment`; fix `AGENTS.md`. |
| Revenue model | `00-overview` "Blended ROAS = revenue_net / spend"; `12` gross/net toggle | Default = Total revenue (Shopify `total_sales`); net sales hidden; ROAS follows the metric | High | Rebase `00`, `10`, `12` to the ratified revenue definition. |
| Adapter contract | `01-architecture/platform-adapter.md:38` DTO missing `netSales`, `totalSales`, `refundsAmount`, `refundedOrders`, `isComplete` | Real `MetricSnapshot` has all of them | High | Regenerate the block from `MetricSnapshot.php`. |
| Sync schedule | `12` "13:00 UTC daily" | 12h `sync:daily` + per-brand + master manual | Med | Reconcile `06-sync` + `12` with the ratified sync decision. |
| Recent features | YoY comparison, ad-spend backfill, RBAC assignment, MFA, Roasdriven UI rename | none in `docs/` (live in memory only) | Med | Capture in the rebased sections + a decisions log. |

Root cause: there is **no mechanism to rebase a doc when a decision is ratified**.
Decisions land in chat, memory, and `specs/CHANGE_REQUEST_*` and never flow back
into `docs/`. Fixing that mechanism is worth more than any single doc edit.

## 2. Sprawl — five places for "documents about the system"

| Location | Holds | Issue | Recommendation |
|----------|-------|-------|----------------|
| `docs/` | canonical numbered spec | healthy | Keep as the one source of truth. |
| `specs/` | `CHANGE_REQUEST_*`, `DEPLOY_*` notes | purpose overlaps `docs/`; ad-hoc | Rename to `decisions/`; make it the dated ADR stream that *triggers* doc rebases. |
| `audits/` | 2 audits (May 31, Jun 1) | no cadence; none since Meta/Google/TikTok/YoY shipped | Keep; run after each milestone (§6). |
| `prompts/` | Claude Code handoff prompts | fine | Keep. |
| root `*.md` | `AGENTS`, `AUDIT_PROMPT`, `DEPLOY_CLOUDWAYS`, `DESIGN_SYSTEM_PROMPT`, `SHOPIFY_INTEGRATION_PROMPT` | 3 of these duplicate `docs/` sections and drift independently | Fold `DEPLOY_CLOUDWAYS`→`11`, `DESIGN_SYSTEM_PROMPT`→`07`, `SHOPIFY_INTEGRATION_PROMPT`→`05/shopify`; keep only `AGENTS` + `AUDIT_PROMPT` at root. |

## 3. Missing spine #1 — no testing / quality section

There is no `docs/` section for testing. Quality today = 4 phpunit files
(`api/tests/`: `RbacAccessTest`, `MetaInsightsMapperTest`, `MetricSnapshotTest`,
`SyncFailureClassifierTest`), a CI workflow, and the manual `AUDIT_PROMPT.md`.
No documented strategy, no per-layer definition-of-done, no coverage target, no
fixtures convention. For a system doing money/ROAS math across 80 live brands,
this is the riskiest gap. Currently untested: `DashboardQuery` (including the new
comparison), both backfill commands, the `fetchRange` methods, the Google/TikTok
mappers, `FxService`, and every controller.
Fix: add `docs/14-testing/` — strategy, what each layer must cover, fixtures
convention, CI gates, and a per-platform test contract.

## 4. Missing spine #2 — platform docs are auth-only

`05-platforms/{shopify,meta,google,tiktok}.md` document **connection setup only**.
They do not capture, per platform: the data contract (which snapshot fields it
fills), the `*:diagnose` command, the unit test + captured fixture, known error
codes / rate limits, range/backfill behaviour, or the acceptance rows. So "is this
integration correct and how do I verify it" is scattered across code comments,
memory, and four diagnose commands. The new `fetchRange` + `ads:backfill-spend`
work is documented nowhere.
Fix: one **per-platform contract template** (§5) — this is the "clean platform-level
spec structure with its audit and testing mechanism" you asked for.

## 5. Recommended target structure

```
docs/
  00-overview/ … 13-open-questions/      existing — rebased to reality (§1)
  05-platforms/<platform>.md             rewritten to the contract template (below)
  14-testing/                    NEW     strategy · fixtures · CI gates · per-platform test contract
  decisions/   (was specs/)      NEW     dated ADRs; the trigger for doc rebases
  CHANGELOG.md                   NEW     one line per ratified change → which doc it touched
root:
  AGENTS.md, AUDIT_PROMPT.md             keep only these; fold the other root prompts into docs/
```

Per-platform contract template — every `05-platforms/<platform>.md` gets the same
seven headings:

1. **Contract** — adapter key, which `MetricSnapshot` fields it fills, native currency, metadata keys.
2. **Auth & setup** — manager token / OAuth, least-privilege scopes, where credentials live.
3. **Sync** — `fetchDay` + `fetchRange`/backfill behaviour, attribution window, pagination & rate limits.
4. **Diagnose** — `php artisan <platform>:diagnose`, and what green output looks like.
5. **Tests & fixtures** — the mapper unit test + the captured payload path.
6. **Failure modes** — error codes, what each means, what the UI shows.
7. **Acceptance** — the platform's rows lifted from `12-acceptance`.

This unifies contract + auth + sync + audit + testing in one place per platform,
and gives the validator skills (§8.5) a fixed shape to emit.

## 6. Audit + testing mechanism — unify them, two tiers

Today audit = one heavy manual LLM pass; testing = a few unit tests. Make quality
two explicit tiers, both documented in `docs/14-testing/`:

- **Gate** (every PR / platform change): `vendor/bin/phpunit` + `tsc --noEmit` +
  the relevant `*:diagnose` returns green. Cheap, automatable, objective.
- **Milestone audit**: the full `AUDIT_PROMPT.md` after each phase or major
  feature, output to `audits/`. Re-establish the cadence — the last audit (Jun 1)
  pre-dates Meta, Google, TikTok, RBAC, and the whole YoY/backfill line.

## 7. Alarming

- `.env.production.recovered` sits in the repo root (3.2 KB). Even if gitignored,
  a recovered production env in the working tree is a secrets-leak risk — confirm
  it is ignored, move it out of the tree, and rotate anything it held.
- The core contract doc (`platform-adapter.md`) is stale against the real DTO —
  anyone scaffolding a new adapter from the doc builds it wrong on day one.
- `design-reference/` (32 mockup directories) is superseded by the React app but
  still present — archive it so it is not mistaken for current truth.

## 8. Sequence (each gated on your greenlight)

1. **Rebase the spec to reality** (§1–2) — fix drift, fold the root prompts, stand
   up `decisions/` + `CHANGELOG.md`. Documentation-only, zero code risk, unblocks
   everything else.
2. **Add `docs/14-testing/`** (§3, §6) — strategy + per-platform test contract +
   the two-tier gate/audit mechanism.
3. **Rewrite `05-platforms/*` to the contract template** (§4–5) — Meta first (most
   complete), then Google, TikTok, Shopify.
4. **Phase 2 plan** — first decide what "Phase 2" means *now*: `00-overview` scopes
   it as deep analytics (campaign→ad-set→ad, product perf, store audit), but Phase 1
   has since absorbed YoY, ad-spend backfill, and RBAC. Written against the rebased
   structure with exit gates per `12`.
5. **Validator skills** — two Helm-specific skills that turn a chat-researched idea
   into a spec doc on this structure: a **platform-integration validator** (new
   `PlatformAdapter` → `05-platforms/<x>.md` on the template) and a **feature
   validator** (new dashboard capability → feature spec + acceptance rows). Both
   emit docs that match the rebased structure, so they stay useful as you iterate.

Recommended start: step 1. It is the cheapest, it removes the drift that is
actively misleading, and the validator skills in step 5 can only emit a correct
spec once the structure they target is itself correct.
