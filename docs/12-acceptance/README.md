# 12 — Acceptance criteria

Each phase has a written, demonstrable acceptance bar. The phase is not done until every criterion passes in a staging environment with realistic data.

## Phase 1 — Dashboard (single user)

- Master admin can log in via email + password.
- Master admin can create a new brand (name, timezone, currency, group_tag).
- Master admin can install the Shopify custom app on a new store via the in-app **Connect** button.
- Master admin can attach a Meta ad account from the BM via the in-app account picker.
- Master admin can attach a Google Ads customer from the MCC via the in-app account picker.
- Master admin can attach a TikTok advertiser from the BC via the in-app account picker.
- Daily sync runs automatically at **13:00 UTC** and fills `daily_metrics` for every active brand × platform.
- Hourly sync runs for the top-20 brands by spend during business hours.
- Dashboard renders **100 brands in a single table within 800ms** (cold cache).
- Dashboard supports yesterday, day-before, last 7 days, MTD, QTD, last quarter, custom range filters.
- Delta percentage column shows the comparison vs the equivalent prior period, never vs a stale or undefined baseline.
- Returns toggle switches between gross revenue and net-of-refunds revenue across the table.
- Currency toggle switches between native currency and USD.
- Failed sync renders an amber warning, **never a zero or a wild negative delta**.
- Per-brand drill-in page shows daily revenue trend (Recharts) and a list of recent syncs with success/failure.
- All 100 production stores connected and syncing successfully for **5 consecutive days** before sign-off.

## Phase 1.5 — Team & permissions

- Master admin can invite a new user by email, choosing role and (for limited roles) accessible brands.
- Invited user receives an email with an accept link, sets password, lands logged in.
- Team member sees only their assigned brands in the dashboard. The API returns `403` on direct access to other brands' data.
- Brand user sees only their one brand. They cannot see the user list, the team page, or any other brand.
- Master admin can grant or revoke a user's access to a specific brand without affecting other access.
- MFA setup flow works (TOTP via Google Authenticator, Authy, 1Password, etc.). MFA enforced for `master_admin` on next login after rollout.
- Audit log records every user invitation, role change, brand access change, MFA enable/disable, and impersonation event.
- Master admin can impersonate any user for support. Impersonation banner is visible at all times. Every impersonated action is logged.

## Phase 2 — Deep analytics

- Per-brand ad performance page lists every campaign, ad set, and ad with spend, revenue, ROAS, CTR, CPC, frequency for the selected date range.
- **Underperformer flag** fires for any ad with `spend > €500 AND ROAS < 1.0` over the trailing 14 days.
- **Scale candidate flag** fires for any ad with `ROAS > 3.0 AND frequency < 2.5` over the trailing 7 days.
- Per-brand product performance page lists every product with units sold, revenue, refund rate.
- Store audit card refreshes weekly and shows site speed, broken Meta events, checkout drop-off rate.

## Phase 3a — Ticketing

- Brand user can raise a ticket on their brand with title, description, category, optional attachments.
- Internal team sees new tickets in a triage inbox sorted by priority and age.
- Internal team can add internal-note comments invisible to brand user, plus public comments visible to brand user.
- Ticket status transitions: `open → triaged → in_progress → done` (or `blocked`, `wont_do`).
- Brand user receives email notification on every public status change or public comment on their tickets.

## Phase 3b — External task tool sync

- Tickets moved to `in_progress` create a corresponding task in the chosen external tool (ClickUp / Linear / Asana).
- Status changes in the external tool sync back to the platform within **5 minutes**.
- The external task URL is shown on the ticket detail page.
