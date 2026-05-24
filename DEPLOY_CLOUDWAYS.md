# Helm — Cloudways + GitHub deploy runbook

This runbook deploys the Helm monorepo (/api Laravel + /web React SPA) to a
single Cloudways application, with a managed Postgres database, Redis from
Cloudways' built-in service, and Horizon running as a worker. End state:
`https://helm.your-domain.com` serves the SPA, `/api/*` serves the Laravel
API, and daily syncs run automatically via the Laravel scheduler.

This is a one-time setup. Every subsequent deploy is `git push` + a 3-command
SSH redeploy script (last section).

---

## 0. Spec status

Project spec says Hetzner CCX22 via Forge. This runbook uses Cloudways
instead. The two material differences from spec:

- Postgres is provisioned via DigitalOcean Managed Database (or Neon),
  not the application server. Cloudways doesn't offer first-class Postgres.
- Horizon runs via Cloudways' Supervisord (or `queue:work` via cron — see §10).

Nothing else in the spec changes. App code is identical.

---

## 1. Repo prep (before you touch Cloudways)

Make sure your `main` branch on GitHub has these in order. Skip steps you
already did.

### 1.1 .gitignore must exclude

In repo root and in `/api` and `/web`:

```
# repo root
/api/vendor/
/api/node_modules/
/api/.env
/api/storage/*.key
/api/storage/logs/*
/api/storage/framework/cache/*
/api/storage/framework/sessions/*
/api/storage/framework/views/*
/api/public/build/
/api/public/app/
/web/node_modules/
/web/dist/
.DS_Store
```

`/api/public/app/` is excluded because the frontend build output gets
written there during deploy, and we don't want it tracked in git.

### 1.2 Commit `.env.example` for /api

Verify `/api/.env.example` has every variable the app reads. Most important:

```
APP_NAME=Helm
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://helm.your-domain.com

DB_CONNECTION=pgsql
DB_HOST=
DB_PORT=25060
DB_DATABASE=helm
DB_USERNAME=helm
DB_PASSWORD=
DB_SSLMODE=require

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis

HORIZON_PREFIX=helm

# Sanctum / SPA
SANCTUM_STATEFUL_DOMAINS=helm.your-domain.com
SESSION_DOMAIN=.your-domain.com

# Shopify master Partner app credentials live in platform_credentials,
# not here — but the master app's HTTP host and scopes do live in .env.
SHOPIFY_API_VERSION=2025-01
```

### 1.3 Build script in /api/composer.json

Add a `post-install-cmd` and a `post-deploy` script section so a fresh
SSH pull only needs `composer install --no-dev && composer run deploy`:

```json
"scripts": {
  "deploy": [
    "php artisan migrate --force",
    "php artisan config:cache",
    "php artisan route:cache",
    "php artisan view:cache",
    "php artisan horizon:terminate"
  ]
}
```

`horizon:terminate` triggers a graceful restart of the worker on the next
Supervisord/cron tick — Horizon reads the new code.

### 1.4 Push

```bash
git add .gitignore api/.env.example api/composer.json
git commit -m "chore: deploy prep"
git push origin main
```

---

## 2. Provision the Cloudways server

Sign in at `cloudways.com`. Add a new server with these choices:

- **Application**: Laravel 11 (Cloudways auto-detects the version from
  composer.json, but pick Laravel from the dropdown)
- **App name**: `helm`
- **Provider**: DigitalOcean (cheapest with Managed Postgres in same region) or Vultr High Frequency (faster CPU)
- **Server size**: 2 GB RAM minimum for dev, 4 GB recommended for prod with Horizon. Bump later if you breach 80% sustained.
- **Server location**: closest to most of your brands' timezones. EU brands → Frankfurt or Amsterdam. US → NYC.

Wait ~7 min for provisioning. Cloudways will email you when ready.

---

## 3. Provision Managed Postgres (DigitalOcean)

Skip if you've already done this.

1. In DigitalOcean (not Cloudways), Databases → Create → Postgres 16
2. Same region as the Cloudways server
3. Smallest plan ($15/mo) is fine for Phase 1 — bump when daily_metrics exceeds 5M rows
4. After creation, go to **Settings → Trusted Sources** → add the
   Cloudways server's public IP (Cloudways shows this on the server detail page)
5. Note these from the **Connection Details** tab — you'll paste them into `.env` later:
   - `host`
   - `port` (usually 25060)
   - `database` (default `defaultdb` — create a `helm` DB via psql instead)
   - `username` / `password`
   - **Download the CA certificate** — save as `ca-certificate.crt`

Create the helm DB:

```bash
psql "postgresql://doadmin:PASSWORD@HOST:25060/defaultdb?sslmode=require"
CREATE DATABASE helm;
\q
```

---

## 4. Cloudways: enable Redis

In the Cloudways server detail page → **Settings & Packages → Packages**:
toggle Redis ON. Wait for the install (1 min).

Default Redis password is empty (private to the box). Don't expose Redis publicly.

---

## 5. Connect GitHub to Cloudways

In the Cloudways **Application** (not Server) detail page:

1. **Deployment via Git** → **Generate SSH key**. Copy it.
2. In GitHub → repo Settings → **Deploy keys** → Add. Paste, give it read access (no write needed for deploy).
3. Back in Cloudways → **Branch**: `main`, **Deployment path**: leave blank to deploy into the application's public_html.
4. Click **Start Deployment**.

This copies your repo to `/home/master/applications/{APP_ID}/public_html`.
The /api and /web folders sit alongside each other inside public_html.

---

## 6. Point the webroot at /api/public

In Application → **Application Settings → General**:

- **Webroot**: `/api/public`

Save. This makes Apache/Nginx serve from the Laravel public dir even
though the monorepo sits one level up.

---

## 7. SSH in and install dependencies

Get SSH credentials from Cloudways server detail → **Master Credentials**.

```bash
ssh master_user@SERVER_IP
cd applications/{APP_ID}/public_html
```

### 7.1 PHP 8.3

Confirm PHP is 8.3+:

```bash
php -v
```

If lower, switch in Cloudways → Application Settings → **PHP Settings →
PHP version**.

### 7.2 Composer install

```bash
cd api
composer install --no-dev --optimize-autoloader --no-interaction
```

### 7.3 Configure .env

```bash
cp .env.example .env
nano .env
```

Fill in every value. The Postgres block:

```
DB_CONNECTION=pgsql
DB_HOST=YOUR_DO_POSTGRES_HOST
DB_PORT=25060
DB_DATABASE=helm
DB_USERNAME=doadmin
DB_PASSWORD=YOUR_DO_POSTGRES_PASSWORD
DB_SSLMODE=require
```

Upload the DO Postgres CA cert:

```bash
mkdir -p storage/certs
# from your local machine:
scp ca-certificate.crt master_user@SERVER_IP:applications/{APP_ID}/public_html/api/storage/certs/
```

Tell Laravel to trust it. In `/api/config/database.php` (one-time edit if
not already there) the pgsql block should have:

```php
'sslmode' => env('DB_SSLMODE', 'prefer'),
'sslrootcert' => env('DB_SSLROOTCERT', storage_path('certs/ca-certificate.crt')),
```

### 7.4 App key + migrate

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link
```

If migrate errors with "could not find driver", install the pgsql PHP
extension via Cloudways → Application Settings → **PHP Extensions** →
toggle `pgsql` and `pdo_pgsql` on, then retry.

### 7.5 Cache configs

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 8. Build the frontend

Cloudways doesn't ship Node by default. Install nvm + Node 20 in the
master user's home:

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install 20
nvm use 20
node -v   # should print v20.x.x
```

Build:

```bash
cd ~/applications/{APP_ID}/public_html/web
npm ci
npm run build
```

This emits `/web/dist/`. Copy it to where Laravel can serve it:

```bash
rm -rf ../api/public/app
mkdir -p ../api/public/app
cp -r dist/* ../api/public/app/
```

Add a Laravel catch-all route that returns `/api/public/app/index.html`
for any non-`/api` path (so React Router owns client-side routing):

In `/api/routes/web.php`:

```php
Route::fallback(function () {
    $indexPath = public_path('app/index.html');
    if (! file_exists($indexPath)) {
        abort(404, 'Frontend build missing. Run `npm run build` and copy /web/dist to /api/public/app.');
    }
    return response()->file($indexPath);
});
```

Commit this fallback route, push, re-pull on the server. Once it's in,
visiting `https://helm.your-domain.com/dashboard` serves the SPA
and the SPA hits `/api/*` for data.

---

## 9. Domain + SSL

In Cloudways application page → **Domain Management**:

1. Add `helm.your-domain.com` as the primary domain
2. Point your DNS A record at the Cloudways server IP
3. Wait for DNS propagation (`dig helm.your-domain.com` returns the IP)
4. Under **SSL Certificate** → choose **Let's Encrypt**, enter `admin@your-domain.com`, request

SSL provisions in ~2 min. Visit `https://helm.your-domain.com` — you
should hit the login page.

---

## 10. Horizon (the queue worker)

Choose ONE of the two paths. Path A is correct, Path B is the workaround
if you can't get Supervisord access.

### Path A — Cloudways Supervisord (preferred)

In application page → **Application Settings → Supervisord**:

If you see the panel, add a new program:

- **Program name**: `helm-horizon`
- **Command**: `php /home/master/applications/{APP_ID}/public_html/api/artisan horizon`
- **User**: master_user (whatever your SSH user is)
- **Auto-restart**: yes
- **Stdout log**: `/home/master/applications/{APP_ID}/logs/horizon.log`
- **Stderr log**: same

Save → Start.

Verify:

```bash
supervisorctl status helm-horizon
# should show: RUNNING
```

Open `https://helm.your-domain.com/horizon` (Horizon's built-in UI,
gated by your HorizonServiceProvider — only master_admin sees it).

### Path B — cron-polled queue:work (fallback)

If Cloudways tier doesn't expose Supervisord, run `queue:work` from cron
every minute with `--max-time=55` so it dies before the next tick:

In Cloudways → **Application Management → Cron Job Management** → add:

```
* * * * * cd /home/master/applications/{APP_ID}/public_html/api && php artisan queue:work --tries=3 --timeout=300 --max-time=55 >> storage/logs/worker.log 2>&1
```

You lose the Horizon UI but jobs run. Spec says Horizon — this path is
non-spec. Use it only if you can't get Supervisord.

---

## 11. Scheduler cron

Cloudways → **Application Management → Cron Job Management** → add:

```
* * * * * php /home/master/applications/{APP_ID}/public_html/api/artisan schedule:run >> /dev/null 2>&1
```

This runs every minute. The schedule itself decides when to actually fire
each command (sync:daily at 13:00 UTC, sync:hourly etc.).

Verify after waiting 5 min:

```bash
tail -f /home/master/applications/{APP_ID}/public_html/api/storage/logs/laravel.log
```

You should see the schedule kicking in.

---

## 12. Final sanity checks

```bash
# 1. App boots
curl -sf https://helm.your-domain.com/api/health || echo FAIL

# 2. DB is reachable, migrations are up
php artisan migrate:status

# 3. Redis works
php artisan tinker --execute="cache()->put('hi', 1, 60); echo cache('hi');"

# 4. Horizon is running
php artisan horizon:status   # "Horizon is running."

# 5. Custom diagnostic
php artisan helm:encryption:check
```

Open `https://helm.your-domain.com` → log in → dashboard renders.

---

## 13. Subsequent deploys

After the first-time setup, every deploy is:

```bash
ssh master_user@SERVER_IP
cd applications/{APP_ID}/public_html
git pull origin main

cd api
composer install --no-dev --optimize-autoloader --no-interaction
composer run deploy   # runs migrate + cache + horizon:terminate

cd ../web
nvm use 20
npm ci
npm run build
rm -rf ../api/public/app
cp -r dist/* ../api/public/app/
```

Wrap it in a shell script committed to the repo (`scripts/deploy.sh`)
and just run `bash scripts/deploy.sh` after pulling.

You can also use Cloudways' **Auto Deploy** toggle in the Git panel —
turn it on and pushes to `main` trigger the git pull automatically.
You still need to SSH in to run composer + npm because those aren't
in their auto-deploy hooks.

---

## 14. What to do when something breaks

| Symptom                             | First place to look                                   |
|-------------------------------------|-------------------------------------------------------|
| 500 on every API call               | `storage/logs/laravel.log`                            |
| Horizon dead, jobs piling up        | `supervisorctl status helm-horizon` + horizon.log     |
| Sync jobs queued but never run      | Same as above. Worker isn't drinking from the queue.  |
| Frontend serves stale JS            | Forgot `npm run build` + the cp step                  |
| 419 / CSRF errors on login          | `SANCTUM_STATEFUL_DOMAINS` doesn't match your domain  |
| DB connection refused               | DO Postgres trusted sources — Cloudways IP missing    |
| `Connection [pgsql] not configured` | pdo_pgsql PHP extension not enabled in Cloudways UI   |

---

## 15. Rollback

Cloudways has **Application Management → Backup** — set Daily backup
retention to 7+ days. Restore is a click. For DB, DO Managed Postgres
keeps 7 days of PITR snapshots.

For code: `git revert HEAD && git push` and re-run the deploy script.

---

## 16. The friction you signed up for

- Cloudways defaults are tuned for WordPress, not Laravel. If you breach
  their default opcache or PHP memory limits, you're emailing support.
- DigitalOcean Managed Postgres adds $15+/mo. Worth it for backups.
- Horizon's autoscaling is real but only works under Supervisord.
- Two-folder monorepo → frontend build is a manual cp step. Vercel or
  Netlify for /web would be cleaner — separate concerns, separate
  deploys — but adds a second hosting bill and CORS config. Trade-off.

If at any point this feels like fighting the tooling: Forge + Hetzner is
~$15/mo for a CCX22, has Postgres + Horizon as one-click, and ships your
spec'd stack as-is. The migration is a weekend.
