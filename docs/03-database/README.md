# 03 — Database

Postgres 16. Six tables in Phase 1, three more in Phase 1.5, three more in Phase 2, three more in Phase 3a. All defined as Laravel migrations.

## Contents

- [Phase 1 schema](./phase-1.md) — users, brands, platform_connections, daily_metrics, sync_logs, currency_rates
- [Phase 1.5 schema](./phase-1-5.md) — brand_user_access, invitations, audit_logs
- [Phase 2 schema](./phase-2.md) — ad_performance_daily, product_performance_daily, audit_findings
- [Phase 3a schema](./phase-3a.md) — tickets, ticket_comments, ticket_attachments

## Conventions

- **Migrations match the spec's schema exactly.** Any schema change beyond the spec requires written approval. See [docs/README.md](../README.md#non-negotiables).
- **Timestamps:** `timestamptz`, never `timestamp` without timezone.
- **Dates in `daily_metrics`** are in the **brand's timezone**, never UTC. The conversion happens in `DateRangeResolver` before the query runs.
- **JSONB** for any structured-but-platform-specific data: `credentials`, `metadata`. Never store flat JSON strings in `text`.
- **Encrypted-at-application-layer:** `platform_connections.credentials` and `users.mfa_secret` use Laravel's `encrypted` cast. Database column is plain `jsonb` / `varchar`.
- **Soft deletes** for `brands` (`status = 'archived'`) and `users` (`status = 'disabled'`). Never hard delete a brand — historical metrics rely on it.
- **Unique constraint on `(brand_id, platform, date)`** in `daily_metrics`. The upsert in `SyncBrandDayJob` depends on this.
- **Partial index** is acceptable where it materially helps (e.g. `WHERE status = 'active'` on brands). Use sparingly.

## ER diagram (conceptual)

```
users ────┬───< invitations (Phase 1.5)
          ├───< brand_user_access >──── brands (Phase 1.5)
          └───< audit_logs (Phase 1.5)

brands ───┬───< platform_connections
          ├───< daily_metrics
          ├───< sync_logs
          ├───< ad_performance_daily (Phase 2)
          ├───< product_performance_daily (Phase 2)
          ├───< audit_findings (Phase 2)
          └───< tickets (Phase 3a)

tickets ──┬───< ticket_comments
          └───< ticket_attachments

currency_rates  (standalone — keyed by date + base + target)
```

## Indexes — quick reference

The hot read query is `SELECT … FROM daily_metrics WHERE brand_id IN (...) AND date BETWEEN ? AND ?`. Index `(date, brand_id)` and `(brand_id, date, platform)` cover both the dashboard and the per-brand drill-in.

For the sync health view, `sync_logs` is indexed on `(status, created_at)` and `(brand_id, target_date)`.

For audit log search, `audit_logs` is indexed on `(actor_user_id, created_at)`, `(target_type, target_id)`, and `(action, created_at)`.
