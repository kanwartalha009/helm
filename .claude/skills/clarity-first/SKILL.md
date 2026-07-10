---
name: clarity-first
description: Use at the START of every non-trivial task — feature, bug fix, migration, refactor, integration — before any code is written. Forces goal restatement, ADR/spec lookup, ambiguity check, and a named proof step. Triggers - "build", "add", "fix", "change", "implement", "optimize", any pasted Claude Code prompt from Kanwar.
---

# Clarity first — no code before context

Run these five steps in order. They take two minutes and prevent the two failure
modes Kanwar cares most about: building the wrong thing, and building on stale
assumptions.

## 1. Restate

One sentence: what will exist when this task is done that does not exist now.
If you cannot write that sentence, the task is ambiguous — go to step 4.

## 2. Locate the governing truth (in this order)

1. `docs/decisions/README.md` — is there a ratified ADR touching this area?
   ADRs override the spec (MySQL over Postgres per D-001, Cloudways per D-002,
   total-revenue default per D-005…).
2. `docs/AS-BUILT.md` — what is actually live today.
3. The numbered spec section (`docs/00–13`) — the contracted target.

Name the section/ADR that governs the task in your first reply. If NOTHING
governs it (new ground), say so explicitly — that is a signal Kanwar may need
to ratify a new decision, not a license to improvise.

## 3. Contradiction check

If Kanwar's request contradicts a spec section or an ADR, flag the contradiction
FIRST and ask which one wins. Do not silently follow either side. (This is rule
2 of AGENTS.md — it applies to prompts pasted into Claude Code too.)

## 4. One focused question — or none

If two materially different implementations both satisfy the request, ask ONE
question that discriminates between them. Not a list of questions, not "does
this look right?" at the end. If the request is unambiguous, ask nothing and
proceed.

## 5. Declare the plan and the proof

Before editing: list the files you will touch and the proof step you will run
at the end (`php artisan test`, `npx tsc --noEmit`, `php artisan route:list`,
a curl against a local endpoint…). A task with no runnable proof step is not
ready to start.

## Non-negotiables while executing

- Migrations: additive and non-destructive only — production is live.
- All external HTTP through `api/app/Platforms/` adapters.
- Dates in the brand's timezone; money with `fx_rate_to_usd` snapshotted.
- Missing data is NOT zero — `is_complete=false` renders amber, never €0.
- Shared type changed → `web/src/lib/mockApi.ts` updated → `npx tsc --noEmit`.
