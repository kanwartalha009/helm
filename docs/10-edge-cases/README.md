# 10 — Critical edge cases

These six issues are the difference between a dashboard that's trusted and one that's quietly wrong. Every one of them must be handled before Phase 1 ships.

## Timezones

Every metric date is the date in the **brand's timezone**, not UTC. "Yesterday" for a Madrid brand ends at 22:00 UTC; for a New York brand it ends at 04:00 or 05:00 UTC the next day. The 13:00 UTC daily sync runs late enough that yesterday is closed in every active timezone.

- When the user picks a date range in the UI, the API resolves it **per-brand** using each brand's `timezone` column.
- `DashboardController` calls `DateRangeResolver::resolve($brand, 'last_7')` and gets back two `CarbonImmutable` instances in the brand's tz. Those become the `WHERE` clause on `daily_metrics`.
- Use Carbon's `timezone()` chain. **Never `date()`, never `now()` without a tz argument** anywhere in metric code.

## Refunds

Shopify refunds attach to the **original order's date**. A refund issued today against an order from 3 days ago lowers `revenue_net` for 3 days ago. This is why the 7-day rolling backfill exists.

- `daily_metrics` has both `revenue` (gross) and `revenue_net` (gross minus refunds dated to that day's orders).
- Dashboard's "returns toggle" switches the table between `revenue` and `revenue_net` columns.
- UI shows a small info icon on the "yesterday" column explaining that the number can change for up to 7 days as refunds come in.

**Decision:** refunds attribute to the original sale date, not the refund date. Yesterday's net number is a moving target until ~7 days later.

## Currency

Brands sell in different currencies. The dashboard must support both "native currency per row" and "all converted to USD" modes (for blended ROAS to be meaningful across brands).

- `daily_metrics` stores native `currency` AND `fx_rate_to_usd` snapshotted at sync time.
- `currency_rates` table holds daily FX, fetched from exchangerate.host (free, no key).
- USD column in the dashboard = `revenue × fx_rate_to_usd`, computed in the SQL query, not in PHP.

**Sweden exclusion.** Per the agency's existing logic, Sweden is excluded from EUR aggregations. Implement as a hardcoded list in `config/sync.php → currency_groupings`. Hardcoded is fine — this isn't user-configurable and shouldn't be.

## Attribution

Meta defaults to 7-day click + 1-day view. ROAS can swing 30%+ depending on this setting. Pick one default and **store the window used on every row**.

- **Default attribution window: 7-day click only** (`'7d_click'`). Cleanest and most defensible.
- Store the window string in `daily_metrics.metadata.attribution_window`.
- Expose a toggle in a later phase, not Phase 1. Phase 1 ships with the default only.

If we ever change the default, do **not** retroactively re-pull. Add a new column or version field so historical comparisons stay valid.

## Missing data ≠ zero

A brand whose Shopify sync failed yesterday **must not** render as €0 revenue. €0 looks like a −100% drop and panics the user.

- `daily_metrics.is_complete = true` only when the sync succeeded cleanly.
- Dashboard cell renders an **amber dot + tooltip** if `is_complete = false` or no row exists for the date.
- The delta column shows **`—`** instead of a percentage in this case.
- Sync health page lists every failed sync, error message, and a manual retry button.

This is the highest-stakes rule in the system. Test it explicitly.

## Initial onboarding for 100 stores

During Phase 1 week 5, the agency installs the Shopify custom app on the remaining ~95 stores. Two acceptable paths:

- **Best:** the agency is a Shopify Partner with collaborator access on all stores → install from the Partner dashboard. Fastest.
- **Acceptable:** send the install URL to each store owner, ask them to click and approve. Coordinate over 1–2 days.

The platform supports both flows without code changes — Shopify's install URL works either way. See [13-open-questions](../13-open-questions/README.md) for the confirmation we need before week 5.
