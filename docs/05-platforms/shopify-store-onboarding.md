# Shopify store onboarding SOP

A step-by-step procedure for onboarding one Shopify store into Helm. Intended to be followed by an intern with staff or collaborator access to the store. Each store takes 3–5 minutes once the rhythm is in.

**Output:** one row in the onboarding spreadsheet with the store's shop domain, Admin API access token, API key, API secret, currency, and timezone. These rows will be bulk-imported into Helm's `platform_connections` table when the backend is ready.

## Prerequisites

- Staff or collaborator access on the store. Confirm by logging into `https://{shop_domain}/admin` without being prompted.
- Access to the shared onboarding spreadsheet.
- A terminal with `curl`, or a tool like Postman, for the validation step.

## Spreadsheet columns

Before starting, the spreadsheet should have these columns. The intern fills the first six; Kanwar fills `group_tag`.

| Column | Example | Filled by |
|--------|---------|-----------|
| `brand_name` | Meller | Pre-filled |
| `shop_domain` | meller.myshopify.com | Intern |
| `access_token` | shpat_a1b2c3... | Intern |
| `api_key` | 314dbc4c604a225... | Intern |
| `api_secret` | shpss_abc123... | Intern |
| `currency` | EUR | Intern |
| `timezone` | Europe/Madrid | Intern |
| `group_tag` | EU | Kanwar |
| `status` | done / todo / error | Intern |
| `notes` | one-liner if anything weird | Intern |

## Per-store procedure

### 1. Open the store admin

Go to `https://{shop_domain}/admin`. You should be logged in directly without an extra login screen. If you're prompted for credentials, you don't have staff access — flag it on the spreadsheet with `status = error, notes = no staff access` and move on.

### 2. Enable custom app development (first time only per store)

Bottom-left of the admin → **Settings** → **Apps and sales channels** → **Develop apps**.

If you see a button **"Allow custom app development"**, click it and confirm. This is a one-time toggle per store. If the section already shows a "Create an app" button, the toggle is already on.

If the store owner has disabled custom app development at the org level (rare, but happens on some Plus orgs), you'll see a notice instead of the button. Flag it on the spreadsheet with `status = error, notes = custom apps disabled` and move on.

### 3. Create the app

Click **Create an app**.

- **App name:** `Helm Analytics`
- **App developer:** select your own staff account from the dropdown.

Click **Create app**.

### 4. Configure scopes

In the new app, click the **Configuration** tab → **Admin API integration** → **Configure**.

Tick exactly these four scopes, nothing else:

- `read_orders`
- `read_products`
- `read_customers`
- `read_reports`

**Do not tick `read_all_orders`**. It triggers an extra approval form and we don't need orders older than 60 days for Phase 1.

**Do not tick anything else.** Extra scopes can trigger protected customer data reviews and delay onboarding.

Leave **Storefront API integration** untouched (we don't use it).

Leave **Webhook subscriptions** empty (we don't use webhooks yet).

Click **Save** in the top-right.

### 5. Install the app

Click **Install app** in the top-right of the app page → confirm in the dialog.

After install, you'll be redirected to the **API credentials** tab.

### 6. Copy the credentials

You'll see three things on the API credentials page:

**Admin API access token** — at the top, hidden by default. Click **"Reveal token once"**. The token starts with `shpat_`.

> **Critical:** the token is shown exactly once. If you close the page or click away, you have to uninstall the app and reinstall to get a new one. Copy it into the spreadsheet immediately.

**API key** — visible in the lower section, ~30 characters.

**API secret key** — directly below, click to reveal. Starts with `shpss_`.

Paste all three into the spreadsheet row for this store.

### 7. Validate the token before logging it

Open a terminal and run this curl, substituting the shop domain and access token from the row you just filled:

```bash
curl -X POST "https://{shop_domain}/admin/api/2025-01/graphql.json" \
  -H "X-Shopify-Access-Token: {access_token}" \
  -H "Content-Type: application/json" \
  -d '{"query":"{ shop { name currencyCode ianaTimezone myshopifyDomain } }"}'
```

A successful response looks like:

```json
{
  "data": {
    "shop": {
      "name": "Meller",
      "currencyCode": "EUR",
      "ianaTimezone": "Europe/Madrid",
      "myshopifyDomain": "meller.myshopify.com"
    }
  }
}
```

If you get `{"errors":[{"message":"Invalid API key or access token..."}]}`, the token was copied wrong. Go back to the API credentials tab, **uninstall and reinstall the app** to generate a new token, and retry.

If you get a 401 or 403, you may not have full staff permissions. Flag with `status = error, notes = 403 on validation` and move on.

### 8. Fill the spreadsheet

From the curl response, fill in:

- `currency` ← `currencyCode` (e.g. `EUR`)
- `timezone` ← `ianaTimezone` (e.g. `Europe/Madrid`)

Mark `status = done`. Move to the next store.

## What can go wrong

| Symptom | What to do |
|---------|------------|
| "Allow custom app development" button is missing | Check if you're an admin or just a staff user. Some scopes require admin. If you're admin and still don't see it, the store org has disabled custom apps — flag and skip. |
| Token starts with `shpca_` not `shpat_` | You copied the storefront access token, not the admin one. Go back and copy from the **Admin API access token** section at the top. |
| 401 on validation | Token was truncated or includes whitespace. Re-copy carefully. If still failing, uninstall + reinstall. |
| App name "Helm Analytics" already exists on the store | Someone tried this before. Delete the existing one and create fresh. |
| Store is on a paused plan | The Admin API returns 402 Payment Required. Flag with `status = error, notes = paused plan`. Kanwar will follow up with the store owner. |

## Throughput target

- First 5 stores: 8–10 minutes each (learning curve).
- After 5: 3 minutes each.
- 100 stores end-to-end: 1–2 working days.

## What happens next

Once the spreadsheet has all 100 rows with `status = done`, Kanwar runs a single bulk-import command (to be built as `php artisan brands:import-shopify spreadsheet.csv`) that reads the CSV and writes one `brands` row and one `platform_connections` row per store. From that point on, the daily sync picks them up automatically.
