# Claude Code prompt — Shopify integration with custom-app-per-store CRUD

Copy everything below this line into Claude Code.

---

## Context

Build the Shopify side of Helm's platform integration layer end-to-end: backend scaffold, models, Phase 1 migrations, the PlatformAdapter contract, the ShopifyAdapter implementation, a connection CRUD against brands, a bulk-import artisan command, and the frontend wiring on the existing AddBrandStep2 page.

Treat `docs/` as the source of truth. Specifically read these before you write a single line:
- `docs/00-overview/README.md`
- `docs/01-architecture/README.md` and `docs/01-architecture/platform-adapter.md`
- `docs/02-tech-stack/README.md`
- `docs/03-database/phase-1.md`
- `docs/04-api/README.md`
- `docs/05-platforms/shopify.md` and `docs/05-platforms/shopify-store-onboarding.md`
- `CLAUDE.md` at project root (it overrides defaults; obey it)

## Decision this prompt encodes (deviation from the original spec)

Helm uses **store-admin custom apps**, not OAuth public/unlisted apps. Each Shopify store generates its own Admin API access token from inside the store admin (Settings → Develop apps), and we paste that token into Helm. No OAuth, no Shopify review, no install URL, no callback. The PlatformAdapter contract stays; only the connection-establishment side changes.

This means: no `authUrl()`/`handleCallback()` flow for Shopify (the methods exist to satisfy the interface but throw `LogicException`), no `/connections/shopify/callback` route, no HMAC verification. Instead, a single endpoint accepts `{ shop_domain, access_token, api_key?, api_secret? }`, validates by hitting the Admin GraphQL `{ shop { ... } }` query, and persists.

Before writing code, update three spec docs to reflect this. Then build.

## 1. Spec doc updates (do these first)

**`docs/05-platforms/shopify.md`** — rewrite end-to-end. Drop the OAuth flow section. Document the custom-app-per-store model: prerequisite (staff/collaborator access on each store), per-store admin creation in store admin, the four scopes (`read_orders`, `read_products`, `read_customers`, `read_reports` — never `read_all_orders` without explicit approval), token storage shape in `platform_connections.credentials`, shop info fetched on create. Cross-link to `docs/05-platforms/shopify-store-onboarding.md` (the intern SOP — leave that file alone).

**`docs/03-database/phase-1.md`** — append a "Shopify credentials shape" section. Document: `platform_connections.credentials` for Shopify is `{ access_token: string, api_key: string|null, api_secret: string|null }`. The whole JSONB column uses Laravel's `encrypted` cast.

**`docs/09-onboarding/README.md`** — replace the Shopify card description in Step 2. New behavior: an inline form on the card with shop_domain field + access_token field + collapsed "Advanced" section for api_key/api_secret. Submit validates the token against the Admin API; success flips the card to green Connected.

## 2. Backend — Laravel scaffold in `/api/`

Currently `/api/` does not exist. Create it with Laravel 11, PHP 8.3.

Install Composer packages exactly as listed in `docs/02-tech-stack/README.md`: `laravel/framework ^11.0`, `laravel/sanctum ^4.0`, `laravel/horizon ^5.0`, `predis/predis ^2.0`, `guzzlehttp/guzzle ^7.0`, `sentry/sentry-laravel ^4.0`.

Configure `.env.example` with: `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT=5432`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST`, `REDIS_PORT=6379`, `APP_DOMAIN`, `MASTER_ADMIN_EMAIL`, `MASTER_ADMIN_PASSWORD`, `SENTRY_LARAVEL_DSN`. No Shopify-wide client_id/secret — credentials are per-store now.

## 3. Migrations and models (Phase 1)

Write all six migrations exactly per `docs/03-database/phase-1.md`. Match every column, every index, every unique constraint. Use `timestamptz`, never `timestamp`. Use JSONB for `credentials` and `metadata`.

Models: `App\Models\User`, `Brand`, `PlatformConnection`, `DailyMetric`, `SyncLog`, `CurrencyRate`.

`PlatformConnection::$casts` includes `'credentials' => 'encrypted:array'` and `'metadata' => 'array'`. `User::$casts` includes `'mfa_secret' => 'encrypted'`.

`Brand` global scope per `docs/08-rbac/README.md`: short-circuits to no filter for `master_admin` or `manager` roles. Phase 1.5 will add the `brand_user_access` lookup for limited roles — leave that branch as `$q->whereRaw('false')` for now so non-admins see zero brands, and add a `// Phase 1.5: replace with accessibleBrandIds lookup` comment marking the spot.

## 4. PlatformAdapter contract

Per `docs/01-architecture/platform-adapter.md`:

- `app/Platforms/Contracts/PlatformAdapter.php` — the interface, exact signature.
- `app/Platforms/Contracts/MetricSnapshot.php` — the DTO. Add `toRow(float $fxRateToUsd, bool $isComplete = true): array` and `updateableFields(): array` helpers used by `DailyMetric::upsert` later.
- `app/Platforms/PlatformRegistry.php` — singleton in container, `for(string $key): PlatformAdapter`, `all(): array<PlatformAdapter>`. Throws `InvalidArgumentException` for unknown platform.
- `config/platforms.php` — `['shopify' => \App\Platforms\Shopify\ShopifyAdapter::class]`. Other platforms left absent for now.
- Register `PlatformRegistry` as singleton in `AppServiceProvider::register()`.

## 5. Shopify adapter

`app/Platforms/Shopify/ShopifyAdapter.php` implements `PlatformAdapter`:

- `key()` → `'shopify'`, `label()` → `'Shopify'`.
- `authUrl()`, `handleCallback()` → throw `LogicException("Shopify in Helm uses custom apps, not OAuth")`. Documented in a class-level comment.
- `listAvailableAccounts()` → returns `[]`. Shopify connections have one shop per connection — there's nothing to pick.
- `attachAccount()` → throw `LogicException` for the same reason.
- `fetchDay(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot` → delegates to `RevenueFetcher::fetch($conn, $date)`.
- `healthCheck(PlatformConnection $conn): bool` → calls `ConnectionValidator::validate($conn->external_id, $conn->credentials['access_token'])`, returns true if the call succeeds.

`app/Platforms/Shopify/ShopifyClient.php` — Guzzle wrapper. POST to `https://{shop_domain}/admin/api/2025-01/graphql.json`. Headers: `X-Shopify-Access-Token`, `Content-Type: application/json`. After every response, read `extensions.cost.throttleStatus`; if `currentlyAvailable < 200`, sleep until `restoreRate` refills the bucket past 400 (compute the sleep duration, don't hard-code). Surface non-200 responses as exceptions with a clear message including the Shopify error code.

`app/Platforms/Shopify/RevenueFetcher.php` — two GraphQL queries:

1. Orders created within `[date 00:00, date 23:59:59]` in the brand's timezone. Paginate with `pageInfo { hasNextPage endCursor }`. Sum `totalPriceSet.shopMoney.amount` into gross revenue; count orders.
2. Refunds whose `createdAt` falls in the same window (regardless of which order they belong to). Sum into `refunds_amount`. Count distinct refunded orders.

`revenue_net = revenue - refunds_amount`. Returns a `MetricSnapshot` with `revenue`, `revenueNet`, `orders`, `refundsAmount`, `refundedOrders`, `currency` (from connection metadata), `metadata: { 'attribution': null, 'shop_id': ... }`.

`app/Platforms/Shopify/ConnectionValidator.php` — single method `validate(string $shopDomain, string $accessToken): array`. Runs `{ shop { id name currencyCode myshopifyDomain ianaTimezone } }`. Returns the `shop` payload on success. Throws `InvalidShopifyCredentialsException` on 401/403. Throws `ShopifyConnectionException` on 5xx or network errors.

## 6. Auth (minimal — full RBAC comes in Phase 1.5)

Configure Sanctum SPA auth. Seed one `master_admin` user from `MASTER_ADMIN_EMAIL` and `MASTER_ADMIN_PASSWORD` env vars via a seeder that runs idempotently.

Routes (in `routes/api.php`):

- `POST /api/auth/login` — body `{ email, password }`. Returns `{ user, token }`. Rate-limited at `5,1`.
- `GET /api/auth/me` — returns user with `role` and `accessibleBrandIds` (master_admin gets all active brand IDs; others empty for now).
- `POST /api/auth/logout` — revokes current token.

Everything except `/api/auth/login` is gated by `auth:sanctum`.

## 7. Brands CRUD

Per `docs/04-api/README.md`:

- `GET /api/brands` — supports `?status`, `?group_tag`, `?search` (name LIKE).
- `POST /api/brands` — body: `{ name, slug?, timezone, base_currency, group_tag? }`. Slug auto-generated and uniquified if absent. 422 on duplicate slug.
- `GET /api/brands/{id}` — returns the brand with `connections` (array) nested.
- `PATCH /api/brands/{id}` — partial update of name, timezone, base_currency, group_tag, status.
- `DELETE /api/brands/{id}` — soft archive (`status = 'archived'`). Never hard-delete.

Use Form Requests for validation. Use API Resources for serialization. Use `BrandPolicy` (master_admin and manager can do everything; others read-only on their own brands — Phase 1.5 will extend).

## 8. Connection CRUD — Shopify

- `GET /api/brands/{brand}/connections` — list all connections for the brand. Returns `id, platform, external_id, display_name, status, last_sync_at, last_error, metadata` (credentials stripped).

- `POST /api/brands/{brand}/connections/shopify` — body: `{ shop_domain, access_token, api_key?, api_secret? }`.
  1. Validate `shop_domain` against `/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/`.
  2. Call `ConnectionValidator::validate($shop_domain, $access_token)`.
  3. On `InvalidShopifyCredentialsException`, return 422 with `{ errors: { access_token: ["Invalid token or insufficient scopes."] } }`.
  4. On success, create the `PlatformConnection`: `platform='shopify'`, `external_id={shop_domain}`, `display_name={shop.name}`, `credentials={access_token, api_key, api_secret}`, `metadata={currency: shop.currencyCode, timezone: shop.ianaTimezone, shop_id: shop.id}`, `status='active'`.
  5. If `brand.base_currency` is the default 'USD' and the shop returns a different currency, update the brand (only if it's still on the default). Same for timezone.
  6. Return 201 with the new connection. Credentials are stripped from the response payload.

- `PATCH /api/connections/{id}` — body can include any of `access_token`, `api_key`, `api_secret`, `status`. If `access_token` is present, re-run validation before persisting. 422 on validation failure.

- `DELETE /api/connections/{id}` — hard-delete the connection row. `daily_metrics` rows remain (they cascade only on brand delete).

All routes gated by `auth:sanctum` and `BrandPolicy::manage` (Phase 1 — master_admin only; manager allowed; Phase 1.5 will expand).

Implement uniformly across platforms even though only Shopify works today: a generic `POST /api/brands/{brand}/connections/{platform}` controller dispatch that resolves the adapter via `PlatformRegistry` and calls a `createConnection(array $payload)` adapter method. For Shopify, that method does the validate-and-persist. For other platforms (Meta/Google/TikTok — not in this prompt), the method throws "not implemented" so the route exists for the future.

## 9. Bulk-import artisan command

`php artisan brands:import-shopify {csv}` — reads a CSV with columns `brand_name, shop_domain, access_token, api_key, api_secret, currency, timezone, group_tag, status, notes` (per `docs/05-platforms/shopify-store-onboarding.md`).

For each row where `status == 'done'`:
1. Validate the access_token via ConnectionValidator. If validation fails, print `[ERR] {brand_name} — {reason}` and continue.
2. Upsert the brand by slug (slug = Str::slug(brand_name)). Fields: name, timezone (from CSV), base_currency (from CSV), group_tag.
3. Upsert the platform_connection by (platform='shopify', external_id=shop_domain). Update credentials and metadata.
4. Print `[OK] {brand_name} — connected ({shop.name})`.

Idempotent. Exit code 0 if all rows succeeded, 1 if any failed. No partial writes per row — wrap each row in a transaction.

## 10. Frontend wiring (in `/web/src`)

**`src/lib/api.ts`** — add real axios functions:
```
login(email, password): Promise<{ user: User; token: string }>
me(): Promise<User>
logout(): Promise<void>
listBrands(filters?): Promise<Brand[]>
createBrand(payload): Promise<Brand>
getBrand(id): Promise<BrandWithConnections>
listConnections(brandId): Promise<PlatformConnection[]>
createShopifyConnection(brandId, { shop_domain, access_token, api_key?, api_secret? }): Promise<PlatformConnection>
updateConnection(connectionId, patch): Promise<PlatformConnection>
deleteConnection(connectionId): Promise<void>
```

Save token from login response into `localStorage` under `helm.auth.token`. The existing axios interceptor in `api.ts` already reads it.

**`src/hooks/`** — swap these from mockApi to real api:
- `useAuth` → call `api.me()`.
- `useConnections` → call `api.listConnections(brandId)`.
- Leave `useDashboardData`, `useAdRows`, etc. on mockApi for now (those land in later prompts).

**`src/routes/auth/LoginPage.tsx`** — wire the form to `api.login()`. On success, store token + redirect to `/dashboard`. On 422, show field errors.

**`src/routes/AddBrandStep1Page.tsx`** — wire form to `api.createBrand()`. On success, redirect to `/add-brand/connect?brand={id}`.

**`src/routes/AddBrandStep2Page.tsx`** — Shopify card changes:
- Replace the existing modal-trigger Connect button with an inline form rendered directly inside the card.
- Three controlled fields: `shop_domain` (placeholder `meller.myshopify.com`), `access_token` (placeholder `shpat_...`), and a collapsed `<details>` "Advanced (webhook signing)" section containing `api_key` and `api_secret`.
- React Hook Form + Zod schema. shop_domain validated client-side against the same regex as the server. access_token validated as non-empty starting with `shpat_`.
- On submit, call `api.createShopifyConnection(brandId, form)`. Loading state shown on button. On 201, flip the card to a green "Connected — {display_name}" state showing the shop name and a small "Disconnect" link. On 422, show field-level errors inline. On 5xx, show a non-field error at the top of the form with the Shopify error code.

Leave Meta/Google/TikTok cards on their current mock behavior — those get wired in later prompts.

## 11. Hard rules (from `CLAUDE.md` — do not violate)

- Production-grade. No TODOs. No placeholder values for real data — use named env variables and tell Kanwar to set them.
- Folder structure exactly per spec. `/api` for Laravel, `/web` for React. `app/Platforms/Shopify/` for all Shopify code. No new top-level directories.
- Every external API call inside `app/Platforms/Shopify/`. No direct Guzzle anywhere else.
- Migrations match `docs/03-database/phase-1.md` exactly. Any deviation, stop and ask.
- Currency: stored as the native value on every row; `fx_rate_to_usd` snapshotted at sync time. For now (no FX job yet), set `fx_rate_to_usd = 1.0` if `currency = 'USD'`, else default to 1.0 and add a `// TODO(post-FX-job)` comment ONLY on this single line — this is the one allowed TODO, because the FX job is a separate prompt.
- Timezones: every date in daily_metrics is in the brand's timezone, never UTC. Use Carbon's `->timezone($brand->timezone)` chain in RevenueFetcher.
- Missing data ≠ zero. ConnectionValidator failures throw, never return a stub. The sync job (separate prompt) will respect `is_complete=false` when adapters throw.

## 12. Acceptance criteria

Kanwar will verify by running:

1. `cd api && php artisan migrate:fresh --seed` — all six tables created, master_admin user seeded from env.
2. `curl -X POST localhost:8000/api/auth/login -d '{"email":"...","password":"..."}'` — returns user + token.
3. `curl -X POST localhost:8000/api/brands -H "Authorization: Bearer {token}" -d '{"name":"Meller","timezone":"Europe/Madrid","base_currency":"EUR"}'` — creates brand, returns 201.
4. `curl -X POST localhost:8000/api/brands/1/connections/shopify -H "Authorization: Bearer {token}" -d '{"shop_domain":"meller.myshopify.com","access_token":"shpat_REAL"}'` with a real valid token — returns 201; with an invalid token returns 422 with field error.
5. `curl localhost:8000/api/brands/1 -H "Authorization: Bearer {token}"` — returns brand with the Shopify connection nested.
6. `php artisan brands:import-shopify storage/fixtures/sample.csv` (a 3-row CSV) — creates 3 brands + 3 connections. Re-running prints the same output and creates no duplicates.
7. Open `/web` dev server, log in, navigate to Add brand → Step 2, fill the Shopify card form with a real token, see the card flip to green Connected.
8. Confirm `docs/05-platforms/shopify.md`, `docs/03-database/phase-1.md`, and `docs/09-onboarding/README.md` reflect the custom-app-per-store decision.

## 13. Out of scope (do NOT build in this prompt)

- `SyncBrandDayJob`, Horizon queue config, `RunDailySyncCommand` — separate prompt.
- Meta, Google, TikTok adapters — separate prompts, one each.
- `GET /api/dashboard` and `GET /api/brands/{id}/trend` — separate prompt.
- RBAC tables (`brand_user_access`, `invitations`, `audit_logs`) — Phase 1.5.
- MFA flow, invitation flow, audit log — Phase 1.5.
- FX rate job and `currency_rates` population — separate prompt.
- Sync health UI — separate prompt.

If anything in this prompt contradicts `CLAUDE.md` or the `docs/` spec files, **stop and surface the contradiction before deciding**. Do not silently improvise.
