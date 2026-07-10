---
name: verified-numbers
description: Use before stating ANY number about this repo, its database, or production — counts, revenue, row totals, route counts, percentages, line numbers, completion estimates. Enforces measure-don't-recall. Triggers - reporting metrics, audit findings, "how many", status summaries, phase completion percentages.
---

# Verified numbers — measure, never recall

Every number in your output falls into exactly one of three classes. Decide the
class BEFORE writing the number.

## 1. Repo facts — must be measured in this session

Counts of files, routes, migrations, tables, endpoints, components, TODO markers,
line numbers, test counts. Rule: run the command, then state the number. If the
number matters to a decision, show the command beside it.

```bash
ls api/database/migrations/*.php | wc -l          # migration count
php artisan route:list --json | jq length          # real route count (not a grep guess)
grep -rn "TODO" api/app web/src --include='*.php' --include='*.ts*' | wc -l
```

Never state a repo number from memory of a previous session — the repo moves fast
and stale numbers are indistinguishable from hallucinated ones.

## 2. Production/DB facts — not visible from this machine

Brand counts, daily_metrics row counts, sync durations, revenue figures, queue
depth. You CANNOT see these. Never estimate them. Instead output the exact
command for Kanwar to run and label the value as pending:

```bash
php artisan tinker --execute="echo \App\Models\Brand::count();"
```

Write "pending — run the command above" where the number would go. Fill it in
only after Kanwar pastes the output.

## 3. Judgment calls — allowed, but labelled

Effort sizes (S/M/L), completion percentages, risk ratings. These are opinions,
not measurements. Label them "estimate" and decompose any percentage into a
counted numerator and denominator (e.g. "9 of 12 acceptance criteria met — the
list follows"), never a bare "75% done".

## Hard bans

- "approximately", "roughly", "around", "should be" attached to a class-1 or
  class-2 number.
- Percentages with no visible numerator/denominator.
- Copying a number from an old audit, an old chat, or the spec and presenting it
  as current.
- Marking a number-bearing task complete without the measuring command in the
  transcript.

If a number cannot be verified right now, write "unverified" — an admitted gap is
useful, a confident wrong number is poison.
