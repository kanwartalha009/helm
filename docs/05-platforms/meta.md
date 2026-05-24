# Meta

One System User token covers all brands. No per-brand OAuth.

## One-time setup

1. In the agency's Business Manager, navigate to **Business Settings → Users → System Users → Add**.
2. Create a System User with role **Admin** (so it can be granted access to ad accounts as they're added to the BM).
3. Generate a token with scopes: `ads_read`, `ads_management` (read-only is sufficient for Phase 1), `read_insights`, `business_management`.
4. Token has **no expiry**. Store in env as `META_SYSTEM_USER_TOKEN`.
5. Use Marketing API **v19.0+**. The token can query insights for any ad account assigned to the BM.

## Per-brand attach

No OAuth round-trip. The agency's token already has access to every ad account in the BM, so the flow is just account discovery + selection.

1. User clicks **Connect** on the Meta card on the brand detail page.
2. Frontend calls `GET /api/brands/{id}/connections/meta/available`.
3. Backend calls `MetaAdapter::listAvailableAccounts()` which queries `me/businesses/{BM_ID}/owned_ad_accounts` and returns every account.
4. A searchable dialog opens showing **`Account name — act_xxx — currency`**.
5. User picks the right account, clicks Save.
6. Frontend calls `POST /api/brands/{id}/connections/meta/attach` with the chosen `external_id` (`act_xxx`).
7. Backend creates the `PlatformConnection` row referencing the env token plus the chosen ad account ID.

## Attribution window

**Default: 7-day click only** (`'7d_click'`). This is the cleanest, most defensible attribution model for blended ROAS reporting.

Stored on every row in `daily_metrics.metadata.attribution_window`. The Phase 1 dashboard ships with this default only; a UI toggle is deferred to a later phase. See [10-edge-cases / attribution](../10-edge-cases/README.md#attribution).

## Adapter responsibilities

- `MetaClient` — HTTP, retry, watches `X-Business-Use-Case-Usage` header, backs off on error codes 17 and 4.
- `InsightsFetcher` — calls `act_{id}/insights` with `time_range`, `level=account`, `action_attribution_windows=['7d_click']`. Pulls spend, impressions, clicks, conversions, conversion_value.

## Required confirmation before kickoff

The Business Manager must already own or have access to all 100+ ad accounts. Any stragglers must be moved into the BM before sync can work. See [13-open-questions](../13-open-questions/README.md).
