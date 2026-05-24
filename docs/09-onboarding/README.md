# 09 — Self-service onboarding

Adding a new brand never requires developer involvement. The master admin can fully onboard a Shopify store with all four platforms attached in **under 3 minutes**.

## Step 1 — Create brand

A modal or wizard step with five fields:

| Field | Notes |
|-------|-------|
| `name` | Free text, 120 char max |
| `slug` | Auto-generated from name, editable. Unique. |
| `timezone` | IANA picker. Defaults to the user's browser timezone. |
| `base_currency` | 3-letter ISO code dropdown. |
| `group_tag` | Optional. Free text or pick existing. Used for EU / GCC / fashion / etc. groupings. |

Submit creates a `brands` row with no connections.

## Step 2 — Connect platforms

The brand detail page shows four cards, one per platform. Each card has independent state. Cards can be connected in any order; the dashboard starts showing data as soon as any one is connected.

### Shopify card

1. Card shows an input field for the shop domain (e.g. `meller.myshopify.com`).
2. Clicking **Connect** calls `POST /api/brands/{id}/connections/shopify/auth-url`, which returns the install URL.
3. Frontend opens the install URL in a new tab.
4. Store owner approves the install in Shopify.
5. Shopify redirects to `/connections/shopify/callback` with a `code` parameter.
6. `ConnectionController` exchanges the code for an access token, creates the `PlatformConnection` row, posts a message to the parent window to close the popup.
7. Card flips to green **Connected** state.

### Meta / Google / TikTok cards (identical pattern, differs only in API)

1. Card initially shows a **Connect** button. The agency's platform-level token is already in env, so no per-brand OAuth is needed.
2. Clicking **Connect** calls `GET /api/brands/{id}/connections/{platform}/available`, which calls the adapter's `listAvailableAccounts()` and returns every account assigned to the agency's manager-level structure (BM / MCC / BC).
3. A searchable dialog opens showing `Account name — external_id — currency`.
4. User searches for the right account, picks it, clicks Save.
5. Frontend calls `POST /api/brands/{id}/connections/{platform}/attach` with the chosen `external_id`.
6. Backend creates the `PlatformConnection` row referencing the env token plus the chosen account ID.
7. Card flips to green.

## Step 3 — Initial sync

Once at least one platform is connected, a **Sync now** button on the brand page dispatches `BackfillBrandRangeJob` for the last 30 days. The page polls `GET /api/sync/status` every 5 seconds and renders progress. Brand appears in the dashboard with real numbers within **2–5 minutes**.

## Failure modes during onboarding

| Symptom | Cause | Fix |
|---------|-------|-----|
| Shopify "scope mismatch" | Existing install with fewer scopes | Uninstall + re-install. |
| Meta "ad account not found" | Account not yet in the BM | Move into BM, retry. |
| Google "USER_PERMISSION_DENIED" | Account not linked / not accepted under MCC | Send link request, wait for acceptance. |
| TikTok "advertiser not visible" | Advertiser not under BC | Add to BC, retry. |

The Connections page surfaces each failure with the platform's exact error text and a **Retry** button.
