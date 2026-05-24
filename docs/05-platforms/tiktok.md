# TikTok Ads

One Business Center owner token covers all advertisers.

## One-time setup

1. The agency must own a **Business Center** with all client advertiser accounts linked under it.
2. Register a **TikTok for Business** developer app. Apply for the Marketing API. App type: **self-service** for agency use.
3. Run OAuth flow once with the BC owner's TikTok account.
4. Store the long-lived `access_token` in env as `TIKTOK_BC_TOKEN`.

## Per-brand attach

Same pattern as Meta and Google.

1. User clicks **Connect** on the TikTok card.
2. Frontend calls `GET /api/brands/{id}/connections/tiktok/available`.
3. Backend calls `TikTokAdapter::listAvailableAccounts()` which queries `/oauth2/advertiser/get/` and returns every advertiser under the BC.
4. Dialog shows `Advertiser name — advertiser_id — currency`. User picks one.
5. `POST /api/brands/{id}/connections/tiktok/attach` with the chosen `advertiser_id`.

## Rate limit

Documented limit: **10 QPS per advertiser**. The `TikTokClient` respects rate-limit headers and backs off on error code `40100`.

## Adapter responsibilities

- `TikTokClient` — HTTP, retry, rate-limit awareness.
- `ReportsFetcher` — calls `/reports/integrated/get/` with `data_level=AUCTION_ADVERTISER`, `dimensions=['advertiser_id']`, `metrics=['spend','impressions','clicks','conversion','conversion_value']`, `start_date=end_date=target_date`.

## Required confirmation before kickoff

The Business Center must own all 100+ advertisers. Any not yet linked must be added before sync can work. See [13-open-questions](../13-open-questions/README.md).
