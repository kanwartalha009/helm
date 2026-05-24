# 13 — Open questions

These should be resolved with the client/owner **before kick-off**. The developer is expected to surface any further questions at the end of each weekly check-in, not at the end of the phase.

## Blocking — Phase 1 cannot start without these

1. **Shopify Partner status.** Is the agency a Shopify Partner with collaborator access on all 100+ stores? If yes, mass-install plan is straightforward. If no, plan a coordination window with each store owner.
2. **Meta Business Manager coverage.** Does the BM already own or have access to all 100+ ad accounts? Any stragglers must be moved into the BM before sync can work.
3. **Google Ads MCC linkage.** Does the MCC have all 100+ client accounts linked **and accepted**? Unlinked accounts will silently fail.
4. **TikTok Business Center coverage.** Does the BC own all 100+ advertisers? Same caveat as Google.
5. **Production domain.** SSL setup blocks on this.

## Decide before Phase 1 week 6

6. **Meta attribution window.** Recommended `7d_click`. Locked default unless a different one is preferred.

## Decide before Phase 1.5 week 1

7. **Transactional email provider.** For invitation emails and notifications. Recommended **Postmark** or **Resend**.

## Decide before Phase 3a week 1

8. **Phase 3b target tool.** ClickUp, Linear, or Asana. Schema needs the right external ID fields.

## Defaults if not decided

9. **Backup retention.** 30 days assumed. Anything regulatory longer?
10. **Primary "blended" currency.** Recommended **USD** as the lingua franca for ad ROAS reporting across EU + GCC + US brands. EUR is an alternative if the agency books in EUR.

## How to log decisions

When a question is resolved, update this file with the decision and date inline. Don't delete the question — keep the resolution as the historical record. Example:

```markdown
6. **Meta attribution window.** Recommended `7d_click`.
   - **Decided 2026-06-10:** `7d_click` confirmed. Stored on every row in `daily_metrics.metadata.attribution_window`.
```
