# Google Ads

One MCC OAuth covers all client accounts.

## One-time setup

1. The agency must have a Google Ads **Manager Account (MCC)** with all client ad accounts linked and accepted.
2. Apply for a Google Ads **Developer Token** at the MCC level. Basic access is sufficient for Phase 1.
3. In Google Cloud Console, create an **OAuth 2.0 client** (web application). Authorized redirect URI: `https://APP_DOMAIN/connections/google/callback`.
4. Run a one-time OAuth flow with the MCC owner's Google account.
5. Store the resulting values in env:
   - `GOOGLE_ADS_REFRESH_TOKEN`
   - `GOOGLE_ADS_CLIENT_ID`
   - `GOOGLE_ADS_CLIENT_SECRET`
   - `GOOGLE_ADS_DEVELOPER_TOKEN`
   - `GOOGLE_ADS_LOGIN_CUSTOMER_ID` (the MCC customer ID, no dashes)

## Per-brand attach

Same pattern as Meta — no OAuth round-trip per brand.

1. User clicks **Connect** on the Google card.
2. Frontend calls `GET /api/brands/{id}/connections/google/available`.
3. Backend calls `GoogleAdsAdapter::listAvailableAccounts()` which queries `customers:listAccessibleCustomers` and returns every customer under the MCC.
4. Dialog shows `Account name — 123-456-7890 — currency`. User picks one.
5. `POST /api/brands/{id}/connections/google/attach` with the chosen customer ID.
6. `PlatformConnection` row stores the customer ID; credentials reference the env values.

## Query convention

All queries set the `login-customer-id` header to the MCC, and the `customer-id` of the child account being queried. The official SDK handles this when configured correctly.

## Adapter responsibilities

- `GoogleAdsClient` — wraps the official SDK. Configures `login-customer-id`. Handles `QuotaError` with exponential backoff (max 3 retries).
- `ReportsFetcher` — runs the GAQL query for `customer.id`, `metrics.cost_micros`, `metrics.impressions`, `metrics.clicks`, `metrics.conversions`, `metrics.conversions_value` for the target date. Converts `cost_micros` to native currency.

## Required confirmation before kickoff

All 100+ client accounts must be linked **and accepted** under the MCC. Unlinked accounts will silently fail. See [13-open-questions](../13-open-questions/README.md).
