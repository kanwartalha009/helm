# Fix the queue + scheduler — step by step

**Why:** production has been running with no scheduler, no Horizon, and (almost certainly)
`QUEUE_CONNECTION=sync` — so every job runs *inline*, nothing is scheduled, and Bosco syncs by hand
every morning. This is the single change that makes the dashboard fast.

**Order matters.** Do not skip ahead. Each step has a check; if a check fails, STOP and fix it
before moving on — a half-applied change here means nothing syncs at all.

Throughout, the app directory is:

```
/home/master/applications/tdtaputtdu/public_html/api
```

---

## STEP 0 — Record the starting state (2 min)

So you can prove what changed, and roll back if needed.

```bash
cd /home/master/applications/tdtaputtdu/public_html/api

php artisan about | grep -i -A2 queue     # ← the important one
crontab -l                                # I already added 2 lines via the panel — verify
php artisan horizon:status
php artisan queue:failed | tail -5
```

**Write down what `about` says the queue connection is.** Everything below assumes it says `sync`.
If it already says `redis`, skip to Step 3.

---

## STEP 1 — Confirm the cron is in place

I added these through Cloudways → Cron Job Management → Advanced. Confirm they survived:

```bash
crontab -l
```

You must see exactly these two lines:

```cron
* * * * * cd /home/master/applications/tdtaputtdu/public_html/api && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/master/applications/tdtaputtdu/public_html/api && pgrep -f "artisan horizon" > /dev/null || php artisan horizon >> storage/logs/horizon.log 2>&1 &
```

If they're missing, re-add them: **Cloudways → Application → Cron Job Management → Advanced tab**,
paste both lines, Save Changes.

**Check:** wait 60 seconds, then:

```bash
php artisan horizon:status      # → "Horizon is running."
tail -20 storage/logs/horizon.log
```

If Horizon still says inactive after 2 minutes, run it once by hand to see the real error:

```bash
php artisan horizon
```

(Ctrl-C once you've read the output. A common cause: Redis isn't reachable — see Step 2.)

---

## STEP 2 — Verify Redis actually works

Horizon is now running, but nothing is being *dispatched* to it yet (the app is still on `sync`).
Before we flip the switch, prove Redis is healthy — otherwise Step 3 stops all syncing.

```bash
php artisan tinker
```

If tinker isn't installed (it's a dev dependency — likely absent), use this instead:

```bash
redis-cli ping        # → PONG
```

Also confirm the app's Redis settings exist:

```bash
grep -E '^REDIS_|^QUEUE_CONNECTION' .env
```

You want a `REDIS_HOST` (usually `127.0.0.1`) and a port. Cloudways shows Redis under
**Application → Access Details → Redis**.

**If Redis is NOT running or not configured — STOP.** Do not do Step 3. Tell me and we'll use the
`database` queue driver instead, which needs no Redis.

---

## STEP 3 — Switch the app to the real queue ⚠️ THE BIG ONE

This is the moment behaviour changes: jobs stop running inline and start going to Redis.

```bash
cd /home/master/applications/tdtaputtdu/public_html/api

cp .env .env.backup-$(date +%F)      # ← rollback insurance. Do not skip.

nano .env
```

Find the line:

```
QUEUE_CONNECTION=sync
```

Change it to:

```
QUEUE_CONNECTION=redis
```

Save (Ctrl-O, Enter, Ctrl-X). Then:

```bash
php artisan config:clear
php artisan config:cache
php artisan queue:restart
```

**Check:**

```bash
php artisan about | grep -i -A2 queue     # → redis
php artisan horizon:status                # → running
```

### Rollback (if anything goes wrong in the next 10 minutes)

```bash
cp .env.backup-$(date +%F) .env
php artisan config:clear && php artisan config:cache
```

You're back to inline syncing — slow, but working.

---

## STEP 4 — Delete the broken Supervisord job

**Cloudways → Application Settings → Supervisord Jobs → `Job_1` → trash icon.**

It listens on the `default` queue with a **60-second timeout**. Two problems: Horizon now covers
`default` too (so they'd double-process every job), and 60s would kill Meller's sync, which takes
74s. It was never processing our `shopify-sync` / `ads-sync` jobs anyway.

**Check:** the Active Jobs table is empty. Horizon is your only worker now.

---

## STEP 5 — Prove the queue is alive end to end

Push one real job through and watch it get picked up.

```bash
# Terminal 1 — watch Horizon
php artisan horizon:list

# Sync ONE brand for yesterday
php artisan sync:daily
```

Then in the app: **Sync health** should show jobs moving `queued → running → success`.

```bash
php artisan queue:failed        # should be empty
```

**If jobs sit in `queued` forever:** Horizon isn't consuming. Check `storage/logs/horizon.log`.

---

## STEP 6 — Deploy the code

Only now. The new code assumes a real queue.

```bash
cd /home/master/applications/tdtaputtdu/public_html
./scripts/deploy.sh
```

`deploy.sh` now:
- **hard-fails** if `QUEUE_CONNECTION=sync` (so this can never silently regress)
- runs `php artisan queue:restart` so workers reload the new code instead of running yesterday's
- warns if Horizon isn't back up 65s after `horizon:terminate`

What ships:
- **Meller: 74s → ~1-2s.** The Shopify daily fetch was paging every order of the day (~3,500 for
  Meller). Every number the dashboard shows already comes from one ShopifyQL call — the scan was
  redundant on the hot path and now runs during enrichment.
- **Phase 1 / phase 2 split.** Phase 1 writes revenue + spend and stops. Enrichment (campaigns,
  creatives, breakdowns, sessions, commerce, Klaviyo) is queued behind *every* brand's phase 1, so
  the dashboard fills completely before the slow work starts.

---

## STEP 7 — Verify the win

Run a full manual sync and time it.

```bash
php artisan sync:daily
```

Then open **Sync health**:

- Meller / Shopify duration should now be **~1-2s**, not 74s.
- The dashboard should be fully populated within ~2 minutes.
- Enrichment jobs keep running afterwards — that's correct and expected.

Tomorrow morning, confirm the scheduler fired on its own:

```bash
tail -50 storage/logs/schedule.log        # sync:daily at 01:00 and 13:00 UTC
```

If that's there, **Bosco never has to press Sync again.**

---

## If something breaks

| Symptom | Cause | Fix |
|---|---|---|
| Nothing syncs at all after Step 3 | Horizon not consuming | `php artisan horizon:status`; check `storage/logs/horizon.log`; worst case roll back `.env` |
| Jobs stuck in `queued` | No worker | Cron keep-alive not firing — `crontab -l`, then run `php artisan horizon` by hand to see the error |
| Jobs run twice | `Job_1` still exists alongside Horizon | Delete `Job_1` (Step 4) |
| Deploy aborts: "QUEUE_CONNECTION=sync" | Step 3 not done or `.env` reverted | Redo Step 3 |
| Horizon dies after every deploy | Keep-alive cron missing | Re-add the second cron line (Step 1) |
