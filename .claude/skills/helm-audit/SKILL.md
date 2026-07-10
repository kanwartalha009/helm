---
name: helm-audit
description: Run the canonical Helm platform audit. Trigger when Kanwar says "audit", "run the audit", or asks for a platform health/anomaly check.
---

# Helm audit

1. Read `AUDIT_PROMPT.md` at the repo root. It is the audit contract — all 7
   sections, three roles, conventions (tabular, file:line citations, no emojis,
   ≤1,500 words).
2. Read `docs/AS-BUILT.md` and `docs/decisions/README.md` BEFORE judging
   anomalies. A spec deviation covered by a ratified ADR is accepted reality,
   not an anomaly. Only report: code contradicting the spec with no covering
   ADR, or code contradicting its own ADR — and say which case each row is.
3. Re-read the spec and code from scratch — never reuse a prior audit's
   findings or numbers. The project moves fast; stale findings are
   indistinguishable from hallucinated ones.
4. Apply the `verified-numbers` skill to every count in the report.
5. Save the report as `audits/AUDIT_YYYY-MM-DD.md` (today's date) and relay it
   in full — no summarizing.
