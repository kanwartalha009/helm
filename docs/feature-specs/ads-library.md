# Instruction set — Helm Ads Library (internal winners + Meta market library)

**Date:** 2026-07-10 · **Author:** research pass ratified with Kanwar · **Executor:** Claude Opus in Claude Code
**Scope ratified 2026-07-10:** data source = OFFICIAL Meta Ad Library API first with a clean vendor seam for later; "plan ads" = boards + briefs workflow; BOTH an internal cross-brand winners library (ships first) AND the external market/competitor library.

**Rev 1.1 (same day, after adversarial double-check):** + own-ads `body_text` column (hook search was impossible — verified gap); + thumbnail persistence for boarded ads (CDN URLs expire — verified gap); + Rising sort via reach-velocity; top-100 collapses to concepts (anti variant-spam); concept-hash fallback chain for textless ads; media_type labeling sweeps + pagination budgeting + sleep-not-stop quota behavior; 100-char search UI limit; TikTok Creative Center parked as a future source.

Read `docs/feature-specs/product-audit-adset-underperformers.md` §0 first — every guardrail there (additive migrations, missing ≠ 0, adapters for all external HTTP, brand-tz dates, fx snapshots, no new deps without asking, sqlite test gotchas, tsc/build gate, verified numbers) applies verbatim to this build. Work the phases in order; each has a proof-of-done.

---

## 1. Why this feature wins (market context, researched 2026-07-10)

- **MagicBrief is shutting down 2026-07-31** ("MagicBrief will shut down on July 31, 2026" — https://magicbrief.com/, absorbed into Canva Grow). Its agency users ($249/mo tier) are stranded. Timing is favorable.
- The remaining tools are inspiration-only: Foreplay ($59–459/mo — https://www.foreplay.co/pricing), Atria ($129–959/mo annual — https://www.tryatria.com/pricing, Trustpilot 1.9/5), AdSpy ($149/mo, engagement-scraped, Trustpilot 2.4/5), BigSpy ($9–249/mo, stale-status complaints). **None of them can join competitor creative signals to verified first-party outcomes.** Helm has real ROAS/CPA per creative across ~88 brands plus Shopify product/stock/margin data — that join is the moat.
- Meta's own Ad Library website has **no ranking at all** ("You're looking at a list of ads with no way to rank them" — https://brandsearch.co/blog/meta-ad-library-eu-reach-chrome-extension) and buries the EU transparency data. Surfacing it well is already a product.

**Core product rule (non-negotiable, applies to every phase):** every metric badge is labelled either **`Verified — our data`** (from Helm's synced ad accounts) or **`Proxy — public signals`** (longevity, EU reach, variant count). No black-box scores, no scraped engagement, no "AI performance estimates" — the research shows exactly those undisclosed proxies earned competitors their 1.9–2.4/5 trust ratings. Helm's honesty IS the differentiator.

## 2. What the official Meta Ad Library API actually gives us (primary-sourced)

All verbatim-verified 2026-07-10 against Graph API v25.0 docs:

- **Coverage:** ALL commercial ads that reached the EU are queryable (DSA, since 2023-08-22 — https://about.fb.com/news/2023/08/new-features-and-additional-transparency-measures-as-the-digital-services-act-comes-into-effect/). "Ads that did not reach any location in the EU will only return if they are about social issues, elections or politics" (https://developers.facebook.com/docs/graph-api/reference/ads_archive/). Bosco's market is EU/ES → coverage fits. US-only competitor campaigns are invisible to the official API (vendor seam, Phase 5). EU ads stay in the archive ~1 year.
- **Endpoint:** `GET /ads_archive` — `ad_reached_countries` REQUIRED (array, e.g. `["ES"]`); `search_terms` max 100 chars; `search_type` = `KEYWORD_UNORDERED` (default) | `KEYWORD_EXACT_PHRASE`; `search_page_ids` ≤ **10 page ids per call**; `ad_active_status` ACTIVE|ALL|INACTIVE; `ad_delivery_date_min/max`; `media_type` ALL|IMAGE|MEME|VIDEO|NONE; `publisher_platforms`; `languages`; cursor pagination. (https://developers.facebook.com/docs/graph-api/reference/ads_archive/)
- **Fields for commercial EU ads** (https://developers.facebook.com/docs/graph-api/reference/archived-ad/): `id, page_id, page_name, ad_creation_time, ad_delivery_start_time, ad_delivery_stop_time, ad_creative_bodies[], ad_creative_link_titles[], ad_creative_link_captions[], ad_creative_link_descriptions[], languages, publisher_platforms, ad_snapshot_url, eu_total_reach, age_country_gender_reach_breakdown, target_ages, target_gender, target_locations, beneficiary_payers, total_reach_by_location`.
- **NOT available for commercial ads:** `spend`, `impressions`, `currency`, `demographic_distribution`, `estimated_audience_size` — all "Available only for POLITICAL_AND_ISSUE_ADS". **Therefore "best performing" for market ads is proxy-ranked, period** — the UI must never imply otherwise.
- **Media:** no image/video URL fields exist for commercial ads. `ad_snapshot_url` renders the ad but **embeds the caller's access token — NEVER store it raw or render it to users**; the public token-free permalink for any ad is `https://www.facebook.com/ads/library/?id={ad_archive_id}` (https://github.com/Lejo1/facebook_ad_library). Per-ad creative download is allowed "for analysis" only; batch download is not ("While you cannot currently download a batch of archived ads, you can download ad creative such as images and text for an individual ad… must comply with the data storage terms in our Terms of Service" — archived-ad reference). **v1 stores TEXT + metadata only, no media files.**
- **Access:** per-person identity verification at facebook.com/id (~48h — https://docs.adverity.com/guides/collecting-data/collecting-data-facebook-ad-library.html), then a Meta app + user token with `ads_read`. Rate limit unpublished; secondary sources report ~200 calls/hour (https://bookdown.org/paul/apis_for_social_scientists/facebook-ad-library-api.html). Budget all sync work to ≤150 calls/hr to stay clear.
- **HARD GATE:** the full Ad Library API Terms of Service could not be fetched (facebook.com/legal blocked to crawlers). **Kanwar must read the ToS presented during API onboarding before Phase 2 ships to any user** — specifically the data-storage and display clauses. Enforcement precedent exists: Meta forced a public archiver to restrict redistribution (https://github.com/Lejo1/facebook_ad_library). An internal agency tool is a materially safer posture than public redistribution, but the terms must be read, not assumed.

## 3. Ranking design (deterministic, disclosed)

**Internal winners (Verified):** rank by real window ROAS from `ad_creative_daily`, evidence-gated exactly like AdAudit — no verdict below $50 USD window spend, "early signal" badge below $150 (`AdAudit::MIN_SPEND` / `SOLID_SPEND` — single source of truth, never redefine).

**Market ads (Proxy) — "Helm Signal Score", fully disclosed in a tooltip:**
```
signal = 0.45 × longevity_pctl + 0.30 × reach_pctl + 0.25 × variants_pctl
```
A fourth disclosed component powers a separate **"Rising"** sort (not the default): `reach_velocity = eu_total_reach ÷ longevity_days` — young ads with unusually fast EU reach. Raw longevity punishes new ads; velocity surfaces them without pretending they're proven. Feed sorts: Signal (default) · Rising · Newest · Longest-running.

- `longevity_days` = ad_delivery_start_time → (stop_time or today). Industry-standard proxy: "If an ad has been running for six months, then you can assume it is performing within that brand's success metrics" (Foreplay — https://www.foreplay.co/discovery); Atria: "running for 90+ days? …probably the money-makers" (https://www.tryatria.com/blog/how-to-spy-on-meta-and-tik-tok-competitor-ads-the-complete-guide).
- `reach_pctl` = percentile of `eu_total_reach` within the same niche corpus (real Meta-disclosed data).
- `variants_pctl` = percentile of concept-variant count: ads from the same page whose normalized `ad_creative_bodies` first 120 chars hash equal are ONE concept; the count of live variants is a real investment signal (testing velocity — Atria's "20 → 100 active ads" scaling signal).
- Percentiles computed within (niche × country × last-90-days) corpus so scores compare like with like. Weights are constants in `config/adslibrary.php` [HELM DEFAULT — no published standard], shown verbatim in the UI tooltip. **The score is a sort key, never presented as performance.**

## 4. The phases

### Phase 0 — Access, settings, ratification gates (mostly Kanwar; code is small)

Kanwar (the doc executor reminds him, does not do): ① identity verification at facebook.com/id (~48h wait); ② create the Meta app, generate a long-lived `ads_read` user token; ③ **read the Ad Library API ToS at onboarding and confirm storage/display is acceptable — Phase 2+ is blocked on his explicit go**; ④ per-brand niche assignment (below).

Code:
1. Additive migration: `brands` + `niche` (string 48 null) — e.g. `fashion`, `footwear`, `jewelry`, `skincare`, `backpacks`. Settings UI input on the brand Settings tab (datalist of existing niches for consistency). Niche drives both libraries' filtering; null niche = brand appears under "Unassigned", never guessed.
2. `config/adslibrary.php`: score weights, corpus window (90d), refresh cadence, call budget (150/hr), countries default `['ES']` + workspace override, `retention_note` comment (EU archive ≈ 1 year).
3. Token storage: `platform_credentials` row (`platform='meta_adlib'`, key `access_token`) via the existing PlatformCredentialService + Settings → Platform keys card (same pattern as the LLM key CRUD). `test()` = one `ads_archive` call with `search_page_ids=[known page]`, `ad_reached_countries=['ES']`, limit 1. **Product lens (D-022): the token is a per-workspace credential BY DESIGN — when Helm sells to another agency, THAT agency completes its own Meta identity verification and supplies its own token.** This isolates ToS accountability and rate limits per tenant and means Helm never operates one shared scraping identity across customers. Default countries become a workspace setting for the same reason (a UK agency wants `["GB"]`).

Tests: settings CRUD + credential test-connection mock. **Proof:** credential card saves + "Test" passes against the real token once Kanwar's verification clears.

### Phase 1 — Internal winners library (real data, zero external dependency — ships first, ratified)

**Plain language:** a new "Ads Library" page whose first tab answers "what's actually working across OUR 88 brands" — the thing no competitor can sell. Top 100 creatives by REAL ROAS, filterable by niche/platform/format/brand, each card badged `Verified — our data`.

1. **Additive migration first (verified gap 2026-07-10):** `ad_creative_daily` stores `ad_name` + `thumbnail_url` but NO creative text — hook/copy search over our own winners is impossible today. Add `body_text` (text null) + extend the Meta/TikTok creative fetchers to store the primary body text (own-account Marketing API data — zero Ad Library ToS exposure). Existing rows stay null → "—" until the next creatives sync/backfill; say so in the UI hint.
2. Endpoint `GET /api/ads-library/winners?window=last30|last90&niche=&platform=&media_type=&brand=&sort=roas|spend|ctr&search=`. Source: `ad_creative_daily` joined to brands (niche, name, currency). Aggregate per ad over the window (USD for ranking, native for display per row's brand): spend, roas, ctr, cpa, thumbstop, hold, purchases + brand + thumbnail_url + media_type. Gates: rows below $50 USD spend excluded; $50–150 marked `confidence: 'early'`. Cap 100 rows (it's the product name). `search` matches ad name + brand name + `body_text`. Thumbnails: Meta CDN `thumbnail_url`s EXPIRE (documented in the migration comment) — render with an `onerror` placeholder (branded neutral card, never a broken-image glyph); persistent copies are Phase 4's job for boarded ads only.
2. RBAC: this is CROSS-BRAND data. `master_admin`/`manager` see all brands; `team_member` sees only their assigned brands' winners (reuse the accessible-brands scope — never leak a brand name through a thumbnail to an unassigned member).
3. UI: `/ads-library` route + sidebar entry (OPERATE, under Ads). Tab bar: **Winners** (this phase) · Market (Phase 3) · Boards (Phase 4). Card grid (thumbnail, media-type badge, brand chip, niche chip, ROAS/CTR/CPA row, `Verified — our data` badge, early-signal tag where applicable); filters bar + search; window selector; empty states honest ("No creatives cleared the $50 evidence floor in this window").
4. Freshness: `asOf` = max pulled_at of ad_creative_daily; amber caption when > 48h.

Tests: ranking + evidence gates (49/51/149/151 boundaries), RBAC (team_member sees only assigned; the DataCoverageTest attach-pattern), niche filter, cap-100, search. **Proof:** suite green; page screenshot for two niches with real data.

### Phase 2 — Market library data layer (official API adapter + storage)

**Plain language:** Helm starts pulling the public Meta Ad Library for tracked competitor pages and saved niche searches — every night, within quota — and keeps its own copy of the TEXT + metadata (creative text, dates, reach, targeting), because Meta deletes EU ads after ~1 year and tracked history is the value (Foreplay Spyder's pitch: "you will have access to all the ads forever").

1. Adapter `app/Platforms/MetaAdLibrary/` (guardrail: ALL external HTTP here):
   - `AdLibraryClient` — Graph GET wrapper, token from platform_credentials, per-call logging, Throttle + PlatformRateLimitedException reuse, hard budget counter (config, default 150/hr).
   - `ArchiveFetcher::byPages(array $pageIds ≤10, array $countries, array $filters, ?string $cursor)` and `::byTerms(string $terms ≤100, string $searchType, ...)` — returns typed rows; NEVER exposes `ad_snapshot_url` outward (strips token; stores the public permalink `facebook.com/ads/library/?id={id}` instead).
   - `PageResolver` — accepts a pasted Ad Library / Facebook URL or name; extracts `view_all_page_id` from URLs; falls back to `GET /pages/search` (500 calls/user/day documented cap — https://developers.facebook.com/docs/pages/searching); always confirms with the operator before tracking (name collisions are common).
2. Migrations (all new tables):
   - **Tenant seam on every new table (D-022):** `ad_library_pages`, `ad_library_searches`, `ad_boards`, `ad_briefs` each get `workspace_id` (foreignId nullable, no behavior today) at CREATION — a nullable column now costs nothing; retrofitting tenancy onto populated tables later costs a migration project. `ad_library_ads` stays global-keyed on ad_archive_id (public data, shareable across tenants) but tracked-page/search links are per-workspace.
   - `ad_library_pages` — id, workspace_id (nullable FK), page_id, page_name, niche (48), country_default (8), status(16) default 'active', added_by_user_id, last_refreshed_at, timestamps; unique (workspace_id, page_id).
   - `ad_library_ads` — id, ad_archive_id (string 32, unique), page_id (indexed), page_name, niche (denormalized from tracked page/search, indexed), countries (json), permalink, ad_created_at, delivery_start (date, indexed), delivery_stop (date null), is_active (bool, derived, indexed), creative_bodies (json), link_titles (json), link_captions (json), link_descriptions (json), languages (json), platforms (json), media_type (string 8 null — API doesn't return it on the node; derive from the SEARCH filter used, else null "—"), eu_total_reach (unsignedBigInteger null — null when absent, never 0), reach_breakdown (json null), target_ages (json null), target_gender (string 8 null), target_locations (json null), beneficiary_payers (json null), concept_hash (string 40, indexed — sha1 of page_id + normalized first 120 chars of first creative body; **fallback chain when body is empty: first link_title, else the ad_archive_id itself** — textless image ads must never collapse into one false mega-concept), first_seen_at, last_seen_at, raw (json).
   - `ad_library_searches` — id, label, terms (100), search_type (24), countries (json), filters (json), niche (48), schedule enum('nightly','weekly','off') default 'weekly', created_by, last_run_at.
3. `adlib:refresh` command (RANGED, one process): tracked pages in chunks of 10 page_ids, then scheduled searches; upsert on ad_archive_id; ads present before but absent from an ACTIVE-status sweep get `is_active=false, delivery_stop=COALESCE(api stop, today)`. Schedule nightly 02:30 UTC. Wire into Sync health as a sync_log row so the operator sees it ran. Operational specifics:
   - **media_type labeling:** the ArchivedAd node does not return media type, so each page chunk sweeps 3× (`media_type=ALL`, then `IMAGE`, then `VIDEO` to label rows). ~50 tracked pages ≈ 5 chunks ≈ 15 calls + pagination — still trivial against budget, and Phase 5's "new-format adoption" alert depends on this label existing.
   - **Pagination counts against the budget:** each cursor page is a call. Cap page-sweeps at 5 pages per chunk per night and `log()` when truncated (no silent caps) — a page with >~250 active ads finishes labeling the next night.
   - **Budget behavior:** when the hourly budget (config, default 150) is hit, SLEEP until the next hour and continue; hard-stop only at 06:00 UTC with a sync_log note of what remains (resumes next night). Never blow the limit.
4. Longevity + score materialization: nightly after refresh, compute per-ad `longevity_days`, per-corpus percentiles, `signal_score` (decimal 5,4) — stored columns on ad_library_ads (additive), so the UI sorts on an indexed column.

Tests: fetcher parsing from canned fixtures (EU fields present/absent → null not 0), token never in any stored/emitted URL (regex over payloads — a leak here is a security bug), upsert idempotency, active→inactive transition, concept_hash grouping, budget stop-and-resume, score math on a seeded corpus. **Proof:** suite green + one real nightly run on the server touching < 150 calls (count logged) with row counts reported.

### Phase 3 — Market library UI (search + top-100 + tracking)

**Plain language:** the "Market" tab — search the EU ad library like a pro tool: keyword or competitor, filters, and a Top-100 feed ranked by the disclosed Signal Score. Every card: creative text, brand page, "running for N days", EU reach, demographic split, a link out to the live ad on Facebook, and an "Add to tracking" button.

1. `GET /api/ads-library/market?q=&search_type=&niche=&country=&media_type=&active=&platform=&page_id=&sort=signal|newest|longevity|reach&limit=…` — reads ONLY stored `ad_library_ads` (fast, no live API on page views — the reports pass already taught us why: R-01). A "Search Meta live" action (master_admin/manager, explicit click) runs `ArchiveFetcher::byTerms` once, upserts, then re-queries locally — so ad-hoc searches enrich the corpus permanently.
2. Top-100 feed = stored corpus filtered by niche, **collapsed to ONE card per concept_hash** (the highest-scoring variant represents the concept, with a "14 variants" chip) — top 100 CONCEPTS, not raw ad rows. This prevents one advertiser's variant spam from flooding the feed (the exact "duplicate creatives clutter" complaint against BigSpy) and makes variant count visible instead of noisy. Sorted by signal_score, capped 100. Score tooltip shows the formula verbatim + `Proxy — public signals` badge. Longevity chip ("Running 127 days"), reach chip ("1.2M reached in EU").
3. Ad card detail drawer (reuse the self-contained drawer pattern from AdsAuditReportDocument): full creative texts, targeting (ages/gender/locations), demographic reach bars from reach_breakdown, payer/beneficiary, concept siblings list, permalink button ("View live on Facebook"), Save-to-board (Phase 4 seam: renders disabled with "Boards arrive in the next phase" until then).
3b. Saved-search UI enforces the API's 100-char `search_terms` limit with a live counter (maxlength + hint), and shows the search_type toggle as "Any order" / "Exact phrase" in plain language.
4. Tracking management: "Tracked competitors" panel — paste URL/name → PageResolver → confirm → row in ad_library_pages with niche; per-page ad counts + new-this-week; remove = status 'paused' (keep history).
5. Per-brand entry point: BrandAdsPage gets a "Market" link pre-filtered to the brand's niche.

Tests: endpoint filters/sort/caps, live-search upsert path (mocked adapter), RBAC (viewing = any brand-visible user; tracking mgmt + live search = master_admin/manager), drawer payload shape. **Proof:** suite + tsc + build green; screenshots of top-100 for one niche + a tracked competitor page with its ads.

### Phase 4 — Boards + briefs (the "plan ads" workflow, ratified)

**Plain language:** save any ad — internal winner or market ad — to a board ("Q3 hooks — footwear"), tag it (hook/angle/format), and turn a board into a creative brief the team can shoot: objective, audience, reference ads, hooks that already worked FOR OUR OWN brands in that niche, product facts from Shopify. Foreplay charges $175–459/mo largely for this loop; ours is fed by verified data.

1. Migrations: `ad_boards` (id, name, brand_id null — brand-scoped or workspace-wide, niche null, created_by, timestamps), `ad_board_items` (board_id, source enum('internal','market'), ref_id (ad_creative_daily ad_id or ad_library_ads id), note text, tags json — e.g. `["hook:problem-callout","format:ugc-video","angle:price"]`, position, added_by; unique (board_id, source, ref_id)), `ad_briefs` (id, board_id, brand_id, title, status enum('draft','ready','shipped') , blocks json, created_by, timestamps).
2. Tagging: manual tag input with autocomplete from a curated starter taxonomy in `config/adslibrary.php` (hooks: problem-callout, social-proof, founder-story, before-after, price-anchor, unboxing…; formats: ugc-video, static-product, carousel, meme, testimonial; angles). **Optional LLM assist** (only when the LLM key exists, per D-016 posture): suggest tags from creative TEXT via LlmManager — market-ad text is public data; internal ad NAMES only, never metrics, through the prompt. Suggested tags are always operator-confirmed before save (rules own truth; LLM assists).
3. **Pattern benchmarks (the moat, v1 of research synthesis item #1):** for each tag with ≥3 tagged INTERNAL creatives clearing the $50 evidence floor, show `Verified` medians (ROAS/CTR) computed from ad_creative_daily: "ugc-video in footwear: median ROAS 2.4× across 9 of our creatives". Deterministic SQL, evidence-gated, honest "not enough tagged data yet" below 3.
4. Brief builder: board → brief with editable blocks: Objective, Audience (prefilled from brand + market-ad targeting data), Reference ads (board items with permalinks/thumbnails), Proven hooks (tag benchmarks table), Product facts (price + stock from product_catalog for a chosen product — inventory-aware: warn when the product's cover-days < 28), Deliverables checklist, Notes. Share = internal link; print CSS clean (no PDF button, consistent with reports).
5. **Thumbnail persistence for boarded ads (verified gap):** Meta CDN thumbnail URLs on `ad_creative_daily` EXPIRE — a board of last quarter's winners would rot into broken images. On add-to-board of an INTERNAL ad, download its current thumbnail to local storage (`storage/app/public/adlib-thumbs/{ad_id}.jpg`, served via the public disk symlink) and store the local path on the board item; render local-first with the neutral placeholder fallback. This is the agency's OWN ad-account media — no Ad Library ToS exposure. Market-ad cards stay text + permalink (no media stored, per §2).
6. UI: Boards tab — board grid → board view (item cards with source badges Verified/Proxy) → "Create brief". Save-to-board buttons go live on Phase 1 winner cards + Phase 3 market drawer.

Tests: board/item CRUD + RBAC, unique item constraint, tag benchmark math + evidence gates, brief block assembly (product facts join, targeting prefill), LLM-assist path mocked + skipped-when-no-key. **Proof:** suite + tsc + build green; a real board with 5 mixed items and a generated brief screenshot.

### Phase 5 — Alerts, digest + the vendor seam

1. **Competitor movement alerts** (deterministic, from stored corpus deltas): new-ads-this-week per tracked page; concept-variant spikes (page's active ad count ≥2× its 30-day median — the "20→100" scaling signal); new-format adoption (first video ad from a page that only ran statics). Surface: "This week in your market" panel on the Market tab + a card in the store-audit page's ads section + include in the Weekly report's action list when the brand has tracked competitors in its niche.
2. **Vendor seam** (deferred until Kanwar approves spend): `AdLibrarySource` interface with `OfficialApiSource` as the only implementation; a documented `VendorSource` stub. Researched options + published prices for the decision moment: ScrapeCreators $0.99–1.88/1k requests, credits never expire (https://scrapecreators.com/); Apify actors $0.50–5.80/1k ads (https://apify.com/data_xplorer/facebook-ads-library, https://apify.com/apify/facebook-ads-scraper); SearchAPI.io $1–4/1k searches (https://www.searchapi.io/pricing). What a vendor adds: US/global commercial ads, direct media URLs, sometimes impression/spend estimates. What it costs beyond money: scraping-ToS gray zone (vendors scrape the website's internal GraphQL) — Kanwar signs off explicitly or it stays off.
3. **Explicitly rejected** (documented so nobody re-adds them): scraped like/comment counts as "performance" (misleading — engagement ≠ conversion), black-box AI performance scores (the exact pattern behind competitors' 1.9–2.4/5 trust ratings), storing media files in v1 (ToS: batch download not permitted).

Tests: alert rules at/below thresholds on seeded corpus deltas; interface contract test so VendorSource can't drift. **Proof:** suite green; one real weekly digest rendered for a niche with ≥2 tracked pages.

---

## 5. Decisions already made (do not re-ask Kanwar)

| Decision | Answer |
|---|---|
| Data source | Official Meta Ad Library API first; vendor seam built but OFF until Kanwar approves recurring cost |
| Coverage honesty | EU ads only via official API; UI states "EU delivery only — US-only campaigns not visible" |
| "Best performing" for market ads | Disclosed Signal Score (longevity/reach/variants percentiles) — a sort key, never called performance |
| Verified vs Proxy badges | Mandatory on every metric everywhere |
| Internal winners | Ship FIRST (Phase 1), evidence-gated by AdAudit constants |
| Plan ads | Boards + tags + brief builder (Phase 4), pattern benchmarks from own data |
| Media storage | TEXT + metadata only in v1; snapshot token URLs never stored/rendered; public permalinks only |
| LLM role | Optional tag suggestions from public/creative text only, operator-confirmed (D-016 posture) |
| Product lens (D-022, ratified 2026-07-10) | Helm is a product sold to other agencies. New tables carry `workspace_id` seams from day one; Ad Library tokens + default countries are per-workspace; **cross-tenant pooling of pattern benchmarks or winners is FORBIDDEN** — one agency's performance data never informs another tenant's UI unless a future explicit opt-in aggregate is designed and ratified (Atria's opted-in model is the reference). Copy stays white-label neutral |

## 6. Open items the executor must raise WITH Kanwar (not decide alone)

1. **ToS gate (blocking Phase 2):** read the Ad Library API Terms during onboarding — storage + display clauses — and give the explicit go. The full text is not crawlable; nobody has read it yet.
2. Identity verification (facebook.com/id, ~48h) + app/token creation — his hands, Phase 0.
3. Niche list + per-brand assignment (he knows the portfolio; seed list lives in config).
4. Initial competitor set per niche (ask Bosco for 3–5 pages per niche; PageResolver makes adding them cheap).
5. Vendor spend decision — only when EU-only coverage or missing media provably hurts (bring measured gaps, not vibes: "N tracked competitors had X US-only campaigns we missed").
6. Multi-tenant note — SUPERSEDED by D-022 (in §5): the tenant seam columns are now REQUIRED at table creation, not optional. Full tenancy infrastructure (auth scoping, billing, domains) still waits for the second agency, but no new table ships without its `workspace_id` seam, and nothing pools performance data across tenants.
7. TikTok Creative Center as a later source: TikTok publishes public "Top Ads" data and Foreplay/Atria cover TikTok inspiration. Out of ratified scope (Meta first) — when Kanwar wants it, it slots in as another `AdLibrarySource` implementation behind the same interface. Do not build now.
