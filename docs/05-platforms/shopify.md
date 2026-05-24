# Shopify

Per-brand OAuth. One custom unlisted app installed by each store.

## One-time setup

1. Create one **unlisted custom public app** in the agency's Shopify Partner dashboard.
2. Required scopes (Phase 1): `read_orders`, `read_products`, `read_customers`, `read_reports`. Add scopes only as needed for later phases — every added scope re-prompts the merchant.
3. App URL: `https://APP_DOMAIN/connections/shopify/install`
4. Redirect URI: `https://APP_DOMAIN/connections/shopify/callback`
5. Use Shopify Admin **GraphQL** Admin API version 2025-01 (or latest stable). Do **not** use REST.

## Per-brand install

Each brand installs by clicking the generated install URL. The flow is:

1. Frontend calls `POST /api/brands/{id}/connections/shopify/auth-url` with the shop domain.
2. Backend returns the install URL.
3. Frontend opens the install URL in a new tab.
4. Store owner approves in Shopify.
5. Shopify redirects to `/connections/shopify/callback?code=…&hmac=…&shop=…`.
6. `ConnectionController` verifies HMAC, exchanges code for access token, creates the `PlatformConnection` row, posts a message to the parent window to close the popup.
7. Access token stored encrypted in `platform_connections.credentials.access_token`.

## Mass-onboarding 95 stores in Phase 1 week 5

Two options, both supported by the same install URL.

- **Best:** the agency is a Shopify Partner and has collaborator access on all stores. Install on behalf of clients from the Partner dashboard. Fastest.
- **Acceptable:** send the install URL to each store owner, ask them to click and approve. Coordinate over 1–2 days.

## Adapter responsibilities

`app/Platforms/Shopify/ShopifyAdapter.php` implements `PlatformAdapter`. The interesting work lives in:

- `ShopifyClient` — HTTP, retry, GraphQL cost limiting. Honors `extensions.cost.throttleStatus` on every response; sleeps when `currentlyAvailable < 200`.
- `RevenueFetcher` — pulls `orders` and `refunds` for a given day in the brand's timezone, returns a `MetricSnapshot` with `revenue` (gross) and `revenue_net` (gross minus refunds dated to that day's orders). See [10-edge-cases / refunds](../10-edge-cases/README.md#refunds).
- `OAuthService` — HMAC verification on callback, token exchange, scope validation.

## Currency

The shop's currency comes from the GraphQL `shop { currencyCode }` query at first install. Stored in `platform_connections.metadata.currency`. The adapter passes this through into every `MetricSnapshot`; conversion to USD happens at upsert time using `currency_rates`.
