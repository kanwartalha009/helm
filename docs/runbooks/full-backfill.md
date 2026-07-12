# Full backfill runbook

Goal: no blank fields anywhere — dashboard, reports, Inventory, Ads, Creatives.

**Run it in `screen` or `nohup`.** The whole sequence is many hours. A dropped SSH session used to
mean starting over; it no longer does (the resume-aware commands pick up where they stopped), but
you still don't want to babysit it.

```bash
screen -S backfill        # detach with Ctrl-A D, reattach with: screen -r backfill
cd ~/applications/<app>/public_html/api
```

## 0. Prerequisites (do these first or the run is wasted)

```bash
php artisan migrate                       # session_traffic_daily, backfill_coverage, brand_targets
php artisan backfill:seed-coverage --reset --dry-run   # sanity: read the GAP list at the bottom
php artisan backfill:seed-coverage --reset             # then commit it
```

`seed-coverage` is what makes the hours you already spent count. Without it the resume-aware
commands think nothing is done and re-pull everything.

## 1. The sequence

Cheapest first, heaviest last, so a failure late costs the least.

```bash
# ── Shopify: the revenue spine ────────────────────────────────────────────────
php artisan shopify:backfill-sales      --since=2025-01-01   # 1 API call per brand — fast
php artisan shopify:backfill-commerce   --since=2025-01-01   # product / country / category
php artisan shopify:backfill-funnel     --since=2025-01-01   # sessions → cart → checkout

# ── Ads: spend, campaigns, ad sets ────────────────────────────────────────────
php artisan ads:backfill-spend          --since=2025-01-01   # RESUMES
php artisan ads:backfill-campaigns      --since=2025-01-01   # RESUMES
php artisan ads:backfill-adsets         --since=2025-01-01   # RESUMES ← the big one, see below

# ── Product-level ad spend (feeds Inventory ROAS) ─────────────────────────────
php artisan meta:backfill-ad-products   --since=2025-01-01
php artisan ads:backfill-ad-products    --since=2025-01-01   # google + tiktok

# ── Demographic / placement breakdowns (monthly report) ───────────────────────
php artisan meta:backfill-breakdown     --since=2025-01-01 --type=all
php artisan google:backfill-breakdown   --since=2025-01-01 --type=all

# ── Creatives (Ads hub Creatives tab) ─────────────────────────────────────────
php artisan meta:backfill-creatives     --since=2025-01-01   # RESUMES
php artisan tiktok:backfill-creatives   --since=2025-01-01   # RESUMES

# ── Email revenue — ONLY works for brands with a Klaviyo key saved ────────────
php artisan klaviyo:backfill

# ── Sessions by traffic type (Inventory) — HEAVIEST, run last ─────────────────
php artisan shopify:backfill-session-traffic --since=2025-07-01   # RESUMES
```

## 2. What to expect

**`ads:backfill-adsets` is nearly a cold start.** The coverage seed measured 1,413 brand-days of
ad-set history against 66,303 for campaigns — ad sets were essentially never backfilled (the D-021
gap). This one will run long and pull a lot. That is correct, not a bug.

**`shopify:backfill-session-traffic` is the most expensive command we have** — roughly 4–6
ShopifyQL calls per brand-day, and it must go day by day (ShopifyQL's `LIMIT` applies to the whole
result set, so a month-ranged query would silently return only the busiest days). At ~1.5 s/day ×
88 brands × 365 days that is **12+ hours**. `--since=2025-07-01` gives a year, which is all the
history Shopify exposes anyway. Start there; widen later if you actually need it.

**The resume-aware commands print what they skip** (`already backfilled — skipped`, or
`resuming — 412 of 550 days already done`). If one dies, just re-run the same line.

**`--force` re-pulls a window** you want refreshed regardless of coverage.

## 3. Things a backfill CANNOT fix — these stay blank until you act

| Blank | Why | Fix |
|---|---|---|
| **Email revenue** (Klaviyo) | 0 rows on file — no brand has a private key saved | Brand → **Connections** → Klaviyo → paste each brand's private key (`campaigns:read flows:read metrics:read`, scopes are fixed at creation). Then `klaviyo:backfill`. |
| **TikTok creative previews** | Metrics sync fine; thumbnails are null. The asset field names in `CreativeFetcher::resolveCreatives` were never confirmed against a live account. | `php artisan tiktok:diagnose` → paste the "Probing creative assets" block. |
| **Meta creatives blurry / video won't play** | Card falls back to Meta's ~64px `thumbnail_url`; `GET /{video_id}?fields=source` returns 200 with no `source` for videos the account doesn't own. | `php artisan meta:diagnose-creatives nude-project --show=5` → prints the real pixel size + whether `source` is obtainable. |
| **Breakeven / margin flags** | `gross_margin_pct` and `target_cpa` are unset — Helm never guesses your numbers | Brand → Settings → Performance rules. |
| **Goal pacing cards** | No goal set → no cards (deliberately: a 0% bar reads as failure) | Brand → Settings → Goals. |

## 4. The one that will go stale again

**Creatives are NOT in the daily sync** (`grep -c Creative app/Jobs/SyncBrandDayJob.php` → 0). They
are written *only* by the two backfill commands, and `ad_creative_daily.thumbnail_url` is a
short-lived Meta CDN link. So the Creatives tab will drift stale and thumbnails will eventually
break. Re-run `meta:backfill-creatives --force --since=<recent>` periodically, or wire creative
sync into `SyncBrandDayJob` — the proper fix, and not yet done.

## 5. Verify when it finishes

```bash
php artisan backfill:seed-coverage --dry-run    # the GAP list at the bottom should be empty or tiny
```

Then open a brand's Inventory page: the Sessions column and the "Sessions by traffic type" strip
should render real numbers with no amber warning. An amber warning means a day in the window did
not reconcile against Shopify's own store total — which is the system telling the truth, not a bug.
