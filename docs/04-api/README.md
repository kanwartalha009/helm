# 04 — API

All endpoints prefixed with `/api`. JSON request/response. Auth via Sanctum bearer token in the `Authorization` header (except `/api/auth/*` and the OAuth callbacks).

## Contract

| Code | Meaning |
|------|---------|
| 200 | Success with body |
| 201 | Created |
| 204 | Success, no body |
| 400 | Bad request (malformed JSON, etc.) |
| 401 | Unauthenticated |
| 403 | Authenticated but unauthorized for this resource |
| 422 | Validation failure — body contains `{ errors: { field: [msg, ...] } }` |
| 429 | Rate limited |
| 500 | Server error — logged to Sentry |

Every endpoint that touches a brand is gated by `EnsureUserCanAccessBrand` middleware **and** a policy call. Belt and suspenders.

## Auth

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/auth/login` | Email + password → returns user + Sanctum token |
| POST | `/api/auth/logout` | Revokes current token |
| GET | `/api/auth/me` | Current user profile + role + accessible brand IDs |
| POST | `/api/auth/mfa/setup` | Generates TOTP secret, returns QR data |
| POST | `/api/auth/mfa/verify` | Confirms TOTP code, enables MFA |
| POST | `/api/invitations/accept` | Accept invite via token, set password (Phase 1.5) |

## Dashboard

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/dashboard` | Main table data. Query: `date_range`, `currency`, `include_returns`, `group_tag` |
| GET | `/api/dashboard/summary` | Totals row across all accessible brands |
| GET | `/api/brands/{id}/trend` | Daily series for one brand. Query: `from`, `to`, `platforms` |

## Brands

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/brands` | List accessible brands. Query: `status`, `group_tag`, `search` |
| POST | `/api/brands` | Create a new brand. Body: `name`, `timezone`, `base_currency`, `group_tag` |
| GET | `/api/brands/{id}` | Brand detail incl. connection statuses |
| PATCH | `/api/brands/{id}` | Update brand |
| DELETE | `/api/brands/{id}` | Soft delete / archive a brand |

## Platform connections

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/brands/{id}/connections` | List connections for a brand |
| POST | `/api/brands/{id}/connections/{platform}/auth-url` | Build OAuth URL for the platform |
| GET | `/connections/{platform}/callback` | OAuth callback (web route, not /api) |
| GET | `/api/brands/{id}/connections/{platform}/available` | List accounts the agency can attach (Meta/Google/TikTok) |
| POST | `/api/brands/{id}/connections/{platform}/attach` | Attach a specific external_id |
| DELETE | `/api/connections/{id}` | Disconnect a platform from a brand |

## Sync

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/sync/status` | Last sync per brand × platform, success/fail flags |
| POST | `/api/brands/{id}/sync` | Manual trigger sync for a brand (rate-limited) |
| POST | `/api/brands/{id}/backfill` | Backfill a date range. Body: `from`, `to` |

## Users & access — Phase 1.5

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/users` | List users (master_admin and manager only) |
| POST | `/api/invitations` | Invite a user: `email`, `role`, `brand_ids` |
| GET | `/api/invitations` | List pending invitations |
| DELETE | `/api/invitations/{id}` | Revoke an invitation |
| PATCH | `/api/users/{id}` | Update user role and brand_ids |
| DELETE | `/api/users/{id}` | Disable a user (soft delete) |

## Conventions

- **Pagination:** cursor-based when result sets can grow large (`/api/sync/status`, `/api/users`). Page-based for stable lists.
- **Filtering:** query params, never POST bodies for GET requests.
- **Date inputs:** ISO 8601 strings. The API resolves them to the requesting user's accessible brands using each brand's own timezone.
- **Currency:** every monetary response field includes a sibling `_currency` field. The dashboard never displays a number without its currency.
- **Rate limiting:** Sanctum's `throttle:60,1` on most endpoints. Manual sync endpoints throttled at `5,1` per user to prevent stampede.
