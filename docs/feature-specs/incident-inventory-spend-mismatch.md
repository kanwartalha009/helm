# INCIDENT — Inventory Intelligence spend ≠ Ads Manager (client-reported, 2026-07-21)

**Severity: P0-trust.** Client (Bruna Jewellery) compared: Ads Manager, account BRUNA_DACH (act_1690557571077141), ad name contains "amboise-stud", Jul 1–20 2026 → **€33,421.55 / 127 ads / ~70 active**. Helm Inventory Intelligence, product "Amboise Studs", MTD (1–20 Jul) → **€26,475 / 42 active ads**. Executor: Opus. This is an AUDIT + FIX + SYSTEMIC-SWEEP task; guardrails §0 as always.

## Verified facts (Fable, 2026-07-21, live prod)
1. Brand `bruna-jewellery` Meta connection has BOTH accounts selected: `act_1690557571077141` (DACH — the client's screenshot) + `act_178078850772509`. Multi-account config is NOT the cause.
2. All Meta fetchers iterate `metadata.ad_account_ids` (AdProductFetcher::accountIdsFor, InsightsFetcher, AdSetFetcher, MetaCreativeFetcher). Account iteration in code is NOT the cause.
3. Helm's own header banner that day: €10,386 unattributed (~8% of €136,377 brand spend), "~92% attributed".
4. NOTE: the client's number is ONE account only — if the second account also runs amboise ads, the true Meta-side total is HIGHER than €33.4k and the gap is bigger than it looks.

## Ranked hypotheses — verify in this order, quantify each, no guessing

**H1 — Paid Partnership / influencer whitelisted ads lose their landing URL.** The client's screenshot is dominated by `Paid_Partnership-sarah.gierer…` / `…jaynebrand…` ads (partnership ads run from creator handles). Helm attributes spend→product via the ad creative's link URL (`/products/{handle}` regex). Partnership/dark-post creatives frequently return no usable `link_url`/`object_story_spec` via the API → those euros fall to `__other` (unattributed) or vanish if the creative fetch fails silently. If Bruna's amboise spend is heavily partnership ads, this alone explains most of the €7k+. **Verify:** for account 1690557… Jul 1–20, pull level=ad insights + creative link fields for the 127 name-matched ads; classify each ad: landed-on-amboise / landed-elsewhere / NO-URL-RETURNED; sum spend per class; compare against ad_product_daily rows for the same days.
**Fix if confirmed:** extend AdProductFetcher URL resolution: fall back through `creative{link_url, object_story_spec{link_data{link}}, asset_feed_spec{link_urls}}`, and effective_object_story link; partnership ads with genuinely no URL stay unattributed BUT must be COUNTED in `__other` (never dropped) — assert totals: Σ(ad_product_daily incl. __collection/__other) per account-day == account-day spend from daily_metrics within 0.5%.

**H2 — Pagination/completeness in the ad-level pull.** 127 ads on one account for one product name; the account has far more ads total. Verify AdProductFetcher paginates level=ad insights to exhaustion per account-day (cursor loop, no page cap, no silent stop on throttle release). Any truncation = dropped spend. **Fix:** full pagination + the same per-account-day totals assertion as H1 (that invariant catches EVERY dropping bug forever).

**H3 — Definitional gap (not a bug, but must be labelled).** Client filters by ad NAME; Helm attributes by LANDING page. Ads named amboise landing on collections/home count under Store-wide in Helm; unnamed ads landing on /products/amboise count toward the product. Quantify the two-way delta in the H1 classification. **Fix:** tooltip on the product Ad-spend column: "Attributed by ad landing page — differs from filtering Ads Manager by ad name" + the existing unattributed banner stays.

**H4 — 'Active ads' definition (70 vs 42).** Helm's number = MAX per-day count of ads WITH SPEND landing on the product (ad_product_daily.ads_count); Meta's = current delivery-status Active among name-matched ads incl. €0.19-spend learning ads. Different populations AND different definitions. **Fix:** rename column header to "Ads (peak day)" with tooltip defining it; optionally add a status-based count later. Not a data bug.

## The systemic remedy (build after the fix — this is the trust feature)
**Nightly reconciliation self-check** (`recon:ads-spend` command + Sync-health card): per brand × meta account × last-7-days: Σ ad_product_daily (attributed + __collection + __other) vs daily_metrics account spend vs (weekly) one direct account-level insights call. Drift > 1% → amber Sync-health alert with the per-day diff; drift > 5% → red + audit-page card. Same pattern for ad_campaign_daily_metrics Σ vs daily_metrics, and ad_creative_daily Σ vs campaigns. **Helm should have caught this before the client did — this makes that structural.** Ledger-log the alerts so the GO-3 track record includes data-quality catches.

## Sweep the same class of bug everywhere (the "other features" ask)
For ONE multi-account brand (Bruna) over the same window, assert in prod: Σ ad_campaign_daily_metrics spend == daily_metrics meta spend (±1%); Σ ad_creative_daily == campaigns for meta; ad_set_daily_metrics == campaigns; google/tiktok equivalents for a connected brand. Any failing pair gets the H1/H2 treatment. Report a table of measured drifts in the tracker — numbers, not adjectives.

## Client comms (Kanwar/Bosco, after root cause is measured)
Honest line: "Two definitions (ad-name filter vs landing-page attribution) plus an attribution gap on influencer partnership ads we've now fixed; here's the ad-by-ad reconciliation." Send the classification table — turning the complaint into a trust win is the whole game.
