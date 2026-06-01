# Deploy: USD toggle + Meta ads — release 2026-06-02

A **redeploy of the already-live app** (your server, MySQL, domain, Horizon and
cron are already set up — this is the DEPLOY_CLOUDWAYS.md §13 flow), plus three
release-specific steps: enable USD on existing rows, and connect Meta.

`{APP_ID}` = your Cloudways application id; paths follow DEPLOY_CLOUDWAYS.md.

## What's in this release

- USD currency: fx snapshotted at sync, dashboard Native/USD toggle, `fx:apply`
  backfill for existing rows, nightly `fx:fetch` + `fx:rebackfill` scheduled.
- Meta ads: adapter + client + insights fetcher, org token via Settings, brand
  multi-account picker, blended spend + ROAS columns on the dashboard.
- Scheduler collapsed to one source of truth in `bootstrap/app.php`; dead
  `app/Console/Kernel.php` neutralised.
- First test suite (`vendor/bin/phpunit`) + GitHub Actions CI.

**No new database migrations.** USD reuses the existing nullable `fx_rate_to_usd`
column; Meta adds no tables. `migrate --force` will report nothing to migrate —
the 73 brands' data is not touched by the deploy itself. The only step that
writes to existing rows is `fx:apply` (step 3), and it is additive + dry-runnable.

## 1. Pre-flight (local, before you push)

1. Review the diff: `git status && git diff`.
2. Remove the dead scheduler file: `git rm api/app/Console/Kernel.php`.
3. Fail fast locally (the server build typechecks too, but catch it here):
   - `cd web && npm run build`  (runs `tsc --noEmit` then `vite build`)
   - `cd ../api && vendor/bin/phpunit`
4. Commit + push:
   `git add -A && git commit -m "USD toggle + Meta ads (blended) + test net + scheduler cleanup" && git push origin main`

## 2. Deploy the code (SSH to the server)

```bash
ssh master_user@SERVER_IP
cd applications/{APP_ID}/public_html
git pull origin main

cd api
composer install --no-dev --optimize-autoloader --no-interaction
composer run deploy          # migrate --force (no-op) + config:cache + route:cache + view:cache + horizon:terminate

cd ../web
nvm use 20
npm ci
npm run build                # FAILS if any TS error — fix before continuing
rm -rf ../api/public/app
cp -r dist/* ../api/public/app/
```

`config:cache` picks up the new `config/services.php` (Meta) and the restored
`fx` block in `config/sync.php`. `horizon:terminate` reloads the worker so the
new sync/Meta job code runs. Then load `https://helm.your-domain.com` and
confirm the dashboard renders and the Native/USD toggle appears next to
Gross/Net.

## 3. Enable USD on existing data (run once, on the server)

Existing rows still carry `fx_rate_to_usd = 1.0` from before USD was wired, so
non-USD brands would read 1:1 until backfilled.

```bash
cd applications/{APP_ID}/public_html/api
php artisan fx:fetch                       # pull the latest day's rates now (also runs nightly)
php artisan fx:backfill --since=2024-01-01 # fill currency_rates history (one call per currency)
php artisan fx:apply --dry                 # PREVIEW — writes nothing; review the per-currency counts
php artisan fx:apply                        # apply the corrected rates
```

Flip the dashboard to **USD** — non-USD brands should now show `$` values (e.g.
a EUR brand at roughly 1.08x its EUR figure). `fx:apply` only writes
`fx_rate_to_usd` and clears pending flags; native revenue is never touched, and
re-running is safe (idempotent).

## 4. Connect Meta

1. **Set the org token (DB-backed — required).** Settings → Platform keys →
   Meta → paste the System User token → Save. Do this in the UI, NOT `.env`:
   because the deploy ran `config:cache`, `env('META_SYSTEM_USER_TOKEN')` would
   return null, but the Settings UI stores the token in `platform_credentials`,
   which is read at runtime regardless.
2. **Test it.** Settings → Platform keys → Meta → Test (calls Meta `/me`).
   Expect "Connection successful."
3. **Attach accounts per brand.** Open a brand → Connections tab → Meta Ads card
   → Connect → the searchable list loads every ad account under your Business
   Manager → tick the account(s) that belong to this brand → Save. (Multiple
   accounts blend into one brand row.)
4. **Pull spend.** Hit Sync now on the brand (or the master Sync now), or wait
   for the 13:00 UTC cron. Meta spend lands in `daily_metrics`.

## 5. Verify

```bash
# Meta rows landed (one blended row per brand/day):
php artisan tinker --execute="echo \App\Models\DailyMetric::where('platform','meta')->latest('date')->take(5)->get(['brand_id','date','spend','conversions','conversion_value','currency'])->toJson();"
```

Then on the dashboard: a brand with a Meta connection now shows **Meta spend**,
**Total spend**, and **ROAS** columns (ROAS = gross revenue ÷ ad spend, computed
USD-normalized so it's correct in either currency mode).

## 6. Rollback

- Code: `git revert HEAD && git push`, then re-run step 2.
- Data: the only write to existing rows is `fx:apply` (sets `fx_rate_to_usd`).
  To undo, re-run with corrected rates, or restore from the Cloudways/DB backup.
- Meta: detach is just removing the connection (Connections tab) — it stops
  future syncs; existing meta rows remain until pruned.

## Unrelated but still open (flagged earlier, not part of this release)

- Rotate the DB password committed in `.env.production.recovered` and purge it
  from git history — it guards live client data.
