# 05 — Platforms

Each platform requires a one-time setup by the agency before any brand can connect. These steps are **not** part of user-facing onboarding. The developer performs them once, stores credentials in env (not in the database, except per-store Shopify tokens), and the self-service onboarding flow takes over from there.

## Contents

- [Shopify](./shopify.md) — per-brand OAuth via one custom unlisted app
- [Meta](./meta.md) — one System User token covers all ad accounts
- [Google](./google.md) — one MCC OAuth refresh token
- [TikTok](./tiktok.md) — one Business Center long-lived token

## Where credentials live

**Updated 2026-05-16.** Platform-level credentials moved from `.env` to the `platform_credentials` DB table to enable in-app rotation when accounts change. Encryption via Laravel's `encrypted` cast (AES-256, key in `APP_KEY`).

| Type | Storage |
|------|---------|
| Platform-level tokens (Meta System User, Google MCC refresh, TikTok BC) | `platform_credentials` table, encrypted column. Managed from **Settings → Platform keys**. |
| Per-store Shopify access tokens | `platform_connections.credentials.access_token`, encrypted via Laravel's `encrypted` cast. |
| OAuth client IDs / secrets | `platform_credentials` table. |
| Developer tokens (Google, TikTok) | `platform_credentials` table. |
| `APP_KEY` (master encryption key) | `.env` only — never in the DB. This is the one true secret. |

The app reads credentials through `PlatformCredentialService::get(platform, key)`. The service checks the DB first; if absent it falls back to env (so first-time setup before the UI is touched still works). Every read / write to a credential writes an entry to `audit_logs` with action `credential.{read|created|updated|deleted|rotated}`.

## Adapter rule

Every external API call goes through its `PlatformAdapter`. No direct Guzzle calls outside `app/Platforms/`. See [01-architecture / platform-adapter](../01-architecture/platform-adapter.md).
