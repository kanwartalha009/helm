# 11 — Deployment & infrastructure

## Server

One **Hetzner CCX22** dedicated CPU VPS (€32/month, 4 vCPU, 16 GB RAM, 160 GB SSD, Frankfurt or Helsinki). Sized for the full Phase 1–3 product. Scales to ~500 brands before needing a second server.

## Stack on the box

- Ubuntu 24.04 LTS
- Nginx — reverse proxy, static SPA serving, SSL termination
- PHP-FPM 8.3
- PostgreSQL 16
- Redis 7
- Supervisor running Horizon (`php artisan horizon`)
- Cron triggering the Laravel scheduler every minute:
  ```
  * * * * * php /var/www/api/artisan schedule:run
  ```

## Deployment pipeline

- **Laravel Forge** provisions and manages the server.
- Monorepo with `/api` and `/web` folders.
- Forge deploys the API on push to `main`:
  ```
  git pull
  composer install --no-dev --optimize-autoloader
  php artisan migrate --force
  php artisan config:cache
  php artisan route:cache
  php artisan horizon:terminate
  php-fpm reload
  ```
- Frontend builds in CI (GitHub Actions): `npm run build` outputs to `web/dist/`, rsynced to the server at `/var/www/web/`.
- Nginx serves `/var/www/web/` at root, proxies `/api/*`, `/horizon`, `/connections/*/callback` to PHP-FPM.

## SSL

Cloudflare DNS + Let's Encrypt via Forge. Force HTTPS. HSTS enabled after a stable rollout (don't enable on day one — irreversible for 6 months on subdomains).

## Backups

| What | How | Retention |
|------|-----|-----------|
| Postgres | Nightly dump to S3 via Forge | 30 days |
| Redis | Ephemeral. Loss = pending sync jobs lost. Next scheduled run picks them up. | None |
| Application code | Git is the backup. | Forever |
| S3 attachments (Phase 3) | S3 versioning enabled | Per S3 policy |

**Quarterly:** test restore from backup to a staging box. Unverified backups don't count.

## Monitoring

- **Sentry** — backend and frontend errors. PII scrubbed via `before_send` hook.
- **Horizon dashboard** at `/horizon`, gated to `master_admin` only.
- **UptimeRobot** or **Better Stack** hitting `GET /api/health` every 5 minutes.
- **Optional Phase 2+:** Grafana + Prometheus for deeper metrics. Not needed Phase 1.

## Environments

| Environment | Domain | Purpose |
|-------------|--------|---------|
| Production | `prod.NOVA-DOMAIN.com` (or client's preferred domain) | Real data, real users |
| Staging | `staging.NOVA-DOMAIN.com` (same box, different DB) | Demos, pre-release verification |
| Local | `docker-compose` (php-fpm + postgres + redis + nginx) | Developer workstations |

## Secrets

- All platform tokens in `.env` on the server, never in Git.
- `.env` rotated via Forge's environment editor, not committed.
- Encrypted database columns (`platform_connections.credentials`, `users.mfa_secret`) use Laravel's `encrypted` cast; the `APP_KEY` lives in `.env` and is never rotated without re-encrypting affected rows.

## Health check

`GET /api/health` returns `200` with:

```json
{
  "status": "ok",
  "checks": {
    "database": "ok",
    "redis": "ok",
    "horizon": "running",
    "version": "1.0.0"
  }
}
```

Any sub-check failure returns `503`. UptimeRobot pages on 5 minutes of failure.
