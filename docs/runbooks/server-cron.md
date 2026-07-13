# Server cron — the two entries production cannot run without

Discovered 2026-07-13, on a LIVE system that had been running without either of these:

```
$ crontab -l | grep schedule:run
no crontab for master_ejehnpheby

$ php artisan horizon:status
ERROR  Horizon is inactive.
```

Neither is in the repo. Both are server config. Without them Helm looks healthy and quietly does
nothing: the scheduled sync never fires, and queued jobs are never consumed. The only symptom was
Bosco manually clicking "Sync now" every morning for months.

## 1. The scheduler

`php artisan schedule:list` shows every entry correctly — `sync:daily` twice a day, catalog,
thumbnails, anomalies, digests. **The schedule is fine. Nothing was calling it.** Laravel's
scheduler only runs when something invokes `schedule:run` every minute.

## 2. Horizon keep-alive

`scripts/deploy.sh` runs `php artisan horizon:terminate`, which tells Horizon to finish its current
job and exit — on the assumption that a supervisor restarts it. Nothing did. So **every deploy
killed the queue workers permanently.**

Cloudways has no Supervisor UI, so the keep-alive is a cron: it starts Horizon if, and only if, it
isn't already running.

## Step 1 — the scheduler cron (Cloudways → Application → Cron Job Management)

Replace `<app>` with the real application directory.

```cron
# Laravel scheduler — every minute. Without this, NOTHING scheduled ever runs.
* * * * * cd /home/master/applications/<app>/public_html/api && php artisan schedule:run >> /dev/null 2>&1
```

## Step 2 — keep Horizon alive. Two options.

### Option A — Supervisor (RECOMMENDED)

Cloudways ships a Supervisord integration, but the package is not installed by default. The
**Application Settings → Supervisord Jobs** tab will say so:

> "Supervisord is not installed on your server. You can install it from Settings and Packages
> section of your Server."

1. **Server** (not Application) → **Settings & Packages** → **Packages** → install **Supervisor**.
2. Back to **Application → Application Settings → Supervisord Jobs** → add a job:

   - **Command:** `php artisan horizon`
   - **Directory / working dir:** `/home/master/applications/<app>/public_html/api`
   - **Processes:** `1` — Horizon spawns its own workers per `config/horizon.php`. Running two
     Horizon masters double-processes every job.
   - **Autostart / Autorestart:** on. This is the whole point: it brings Horizon back after a
     crash, an OOM kill, and after `deploy.sh` calls `horizon:terminate`.

### Option B — cron keep-alive (no install needed)

If you can't or won't install Supervisor, this achieves the same thing with the cron panel you
already have. `pgrep` makes it idempotent — it starts Horizon only when it isn't already running,
so it never spawns a second copy.

```cron
* * * * * cd /home/master/applications/<app>/public_html/api && pgrep -f "artisan horizon" > /dev/null || php artisan horizon >> storage/logs/horizon.log 2>&1 &
```

Weaker than Supervisor in one way: a Horizon that hangs (rather than exits) is still "running" to
`pgrep`, so it won't be restarted. Supervisor handles that; this doesn't. Good enough to unblock,
not the end state.

## Verify

```bash
crontab -l                                  # both lines present
php artisan horizon:status                  # "Horizon is running."
tail -20 storage/logs/schedule.log          # entries appearing each run
php artisan about | grep -i -A1 queue       # MUST say redis, never sync
```

## ⚠️ If the queue driver says `sync`

Every `dispatch()` runs INLINE in the calling process. Two consequences:

1. The daily sync's fast-dashboard split (phase 1 writes the headline number, phase 2 enriches in
   the background) **does nothing** — the enrichment runs inline exactly where it used to.
2. A manual "Sync now" runs the entire sync inside the HTTP request.

Set `QUEUE_CONNECTION=redis` in `.env`, `php artisan config:cache`, and make sure Horizon is up.
`deploy.sh` now hard-fails on `sync` rather than shipping a deployment where the queue is a lie.
