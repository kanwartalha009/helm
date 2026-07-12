# Growth OS — MASTER build plan (end-to-end handover)

**Date:** 2026-07-11 · **Author:** Fable strategy + research passes, ratified with Kanwar · **Executor:** Claude Opus in Claude Code
**Purpose:** the complete, self-sufficient instruction set for Helm's evolution into the agency Growth OS. Written so the whole program is buildable WITHOUT further access to the planning model. Strategy rationale + competitive evidence: `docs/strategy/GROWTH_OS_ASSESSMENT_2026-07-11.md`. Governing decisions: `docs/decisions/README.md` (D-001…D-022 — ADRs override everything).

**How to use:** build one phase at a time, in order (§12). Before EVERY phase: `git log --oneline -5` + porcelain check (two Claude sessions have worked this repo in parallel — see helm_clobber_lesson memory), read this doc's phase section fully, then follow §0. After EVERY phase: full suite + `npx tsc --noEmit` + `npm run build`, update `docs/AS-BUILT.md`, add an ADR row for any deviation.

---

## 0. Non-negotiables (same contract as the prior handover docs — enforced, not advisory)

All of `docs/feature-specs/product-audit-adset-underperformers.md` §0 applies verbatim: additive migrations only (prod live, ~88 brands), missing ≠ 0 (null → "—", never €0), all external HTTP in `api/app/Platforms/` adapters, brand-tz dates, native money + `fx_rate_to_usd` snapshots with USD ratio math, D-005 revenue basis, rules own numbers / LLM prose only (D-016), sqlite test date gotchas (DB::table seeds, whereBetween), freshness gates fail CLOSED, no new composer/npm deps without Kanwar's explicit yes, `verified-numbers` + `clarity-first` skills apply.

Plus three Growth-OS-specific laws:

1. **Verified vs Proxy labelling** on every metric surface (ratified in ads-library.md §1): `Verified — our data` vs `Proxy — public signals`. A third label appears in this program: **`Modeled — baseline forecast`** for GO-2 forecasts. Never mix them silently.
2. **The Ledger is sacred (GO-2/3):** recommendation rows are INSERT-ONLY. No update/delete of past recommendations, evidence, or outcomes — an edited track record is worthless. Corrections happen as new rows referencing the old (`supersedes_id`).
3. **Never claim "accurate attribution".** The product line is *triangulated truth*: store-truth MER as the spine, platform-reported numbers beside it, each carrying its documented bias direction (§4.5). Platform ROAS presented as truth = instant credibility death with senior buyers (evidence in the assessment doc).

---

## 1. Program map — what exists, what's in flight, what this doc adds

**DONE (as of 2026-07-11 — verify with git, don't trust this list blindly):** daily+rolling Shopify/Meta/Google/TikTok sync incl. campaigns, ad sets (all 3 platforms + PMax asset groups), creatives (+body_text), product-attributed spend, breakdowns, Shopify web funnel (sessions→cart→checkout→purchase by country/landing — GA4 is therefore OPTIONAL depth, not a gap); commerce dimensions; inventory snapshots + catalog (scheduled); 5 report types incl. comprehensive ads-audit w/ per-campaign issues drawer; one-job backfills + coverage card; LLM layer (D-016, key-only activation); products hub + ProductFlags; audit cards v2; AdSetFlags; brand margin/target-CPA fields + config/rules.php; per-brand DeadInventory; recommendation-grade verdict confidence ($50/$150 gates).

**IN FLIGHT (Opus, per ads-library.md):** brands.niche, ad_creative_daily.body_text, ad_library tables + MetaAdLibrary adapter — the Ads Library phases 0–5. Finish that program first; GO-3/GO-4 consume its corpus.

**THIS DOC (the Growth OS):** GO-1 Truth completion → GO-2 Analyse & plan (+ silent Ledger) → GO-3 Strategist brain (+ visible Ledger, digests) → GO-4 Seasonal playbook engine (the market whitespace) → GO-5 Creative testing engine. Kanwar's 5 phases with the 5 ratified upgrades (U1–U5, assessment doc §3).

---

## 2. Strategy doctrine (compressed — full evidence in the assessment doc)

- Nobody in the market covers even 3 of the 5 areas well; seasonal per-market planning from own data and a recommendation track record **do not exist anywhere** — those two are Helm's moat, and they compound.
- Trust mechanics: senior buyers trust independent measurement most, in-platform least; only ~5% accept Google's own recommendations. Every Helm suggestion therefore ships with: the evidence (numbers + rule cited), the confidence tier, and — once GO-3 lands — the engine's own historical win-rate. Generic advice is the failure mode that killed trust in every incumbent.
- Platform automation (Advantage+/PMax) absorbs execution; Helm's lane is verification + strategy + creative supply. Never build bid-management.
- D-022 product lens on everything: workspace_id seams, per-workspace credentials, zero cross-tenant pooling, white-label copy.

---

## 3. Integration facts (primary-sourced 2026-07-11 — build against these, re-verify versions at build time)

### 3.1 Klaviyo (the GO-1 centerpiece)
- Base `https://a.klaviyo.com/api/`; version via header `revision: 2026-04-15` (current stable; ISO-date scheme, 1yr stable + 1yr deprecated — https://developers.klaviyo.com/en/docs/api_versioning_and_deprecation_policy).
- Auth for v1: **per-client private API key** (`Authorization: Klaviyo-API-Key pk_…`), custom scopes `campaigns:read flows:read metrics:read` set AT CREATION (immutable after — tell the operator in the UI hint). Stored per-workspace+brand in platform_credentials (`platform='klaviyo'`). OAuth (PKCE, per-account tokens) is the marketplace-grade upgrade — build the credential layer so OAuth can slot in later; do not build OAuth now. (https://developers.klaviyo.com/en/docs/authenticate_, /set_up_oauth)
- Daily attributed revenue per flow/campaign: `POST /api/metric-aggregates/` — body `{metric_id: <Placed Order id via GET /api/metrics/>, measurements:["sum_value","count"], interval:"day", by:["$attributed_message"] or ["$attributed_flow"], filter:[datetime range ≤1yr], timezone:"<brand tz>"}`; burst 3/s steady 60/m. Campaign/flow rollups: `POST /api/campaign-values-reports/` + `/api/flow-values-reports/` — **daily cap 225 calls/day per account** (burst 1/s steady 2/m) → sync design: metric-aggregates for the daily series (cheap), values-reports weekly for rollup reconciliation only. (https://developers.klaviyo.com/en/reference/query_metric_aggregates, /reporting_api_overview)
- **Attribution honesty box (must render wherever Klaviyo revenue shows):** Klaviyo revenue is last-touch within Klaviyo's own windows (email opens/clicks 5 days default, SMS click 5d/open 1d, configurable ≤90d, changes apply RETROACTIVELY) — so email revenue + ads revenue + organic ≠ store revenue; they overlap. Render Klaviyo numbers as their own channel column, never summed into a "total attributed" fiction. Periodic re-sync required because retroactive setting changes rewrite history. (https://help.klaviyo.com/hc/en-us/articles/1260804504250, /11118357030555)
- Data floor: no reporting data before 2023-06-01; ≤1yr query windows. Currency: raw store values, NO conversion by Klaviyo — snapshot brand currency + fx like every other table.
- No sandbox; free linked test account exists (irreversible conversion — don't).

### 3.2 GA4 Data API (OPTIONAL — build only if a client demands non-Shopify web depth; Shopify funnel already covers sessions/CVR)
- Service-account flow, REST-only from PHP confirmed (JWT RS256 via openssl_sign → `POST https://oauth2.googleapis.com/token` grant_type=jwt-bearer — https://developers.google.com/identity/protocols/oauth2/service-account). Client grants the SA email Viewer on their property.
- `POST https://analyticsdata.googleapis.com/v1beta/properties/{id}:runReport` — dims `date`,`country`,`sessionDefaultChannelGroup` (VERIFY exact name against /data/v1/api-schema before hardcoding); metrics `sessions`,`totalRevenue`,`keyEvents` (NOT `conversions` — renamed 2024-05-06). Quotas: 200k tokens/day, 40k/hr standard property; send `"returnPropertyQuota": true` and log it.

### 3.3 Slack digests
- Slack app + `incoming-webhook` OAuth scope: install flow lets the workspace pick the channel, returns `incoming_webhook.url`; POST Block Kit JSON; ~1 msg/s, 429 + Retry-After. Legacy custom-integration webhooks deprecated — don't. Store webhook URL per workspace (it's a secret — encrypt like credentials; Slack revokes leaked ones). (https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks)

### 3.4 Forecasting baselines (zero deps — ratified method)
- Seasonal naive ("each forecast equals the last observed value from the same season") + drift, per Hyndman & Athanasopoulos fpp3 §5.2 — explicitly legitimate benchmarks (https://otexts.com/fpp3/simple-methods.html). Pure SQL self-joins + arithmetic. Label output `Modeled — baseline forecast (seasonal-naive + trend)`; NEVER render a forecast without that label.

---

## 4. GO-1 — Truth completion (Kanwar's phase 1, upgraded per U1)

**Plain language:** finish the data set so every later recommendation stands on complete, honestly-labelled numbers. Klaviyo email revenue (Bosco's whole lifecycle pillar is invisible today), true product costs → contribution margin, a per-brand data-quality score, and the bias-annotated MER spine.

**4.1 Klaviyo adapter** — `app/Platforms/Klaviyo/{KlaviyoClient, RevenueFetcher}.php` per §3.1. New table `email_daily_metrics`: brand_id, workspace_id (nullable — D-022), date, source enum('flow','campaign'), source_id (64), source_name (255 null), conversions (unsignedInt), conversion_value (14,2), currency (8), fx_rate_to_usd, is_complete, pulled_at; unique (brand_id, date, source, source_id). Daily sync (mirror syncMetaAdProducts wiring: fault-isolated in the day job) + ranged `klaviyo:backfill {brand} --since=` (≥2023-06-01 clamp) + backfill-job dataset `history` gains it when klaviyo connected + coverage card row. Settings: Klaviyo key CRUD in Platform keys (existing pattern; hint about immutable scopes). Dashboard/monthly report: "Email revenue (Klaviyo-attributed)" column/section with the §3.1 honesty box. Weekly report: email block when data exists.

**4.2 Product costs → contribution margin.** Shopify's catalog exposes per-variant `inventoryItem.unitCost` — extend the catalog fetcher to pull it (additive col `unit_cost` on product_catalog; VERIFY field availability in the API version in use before building; if absent → manual only). Fallback/override: per-product manual cost input on the Products hub (master_admin/manager), stored `product_costs` (brand_id, product_key, unit_cost, currency, effective_from date, set_by) — effective-dated so margin history stays honest. Brand-level fallback: existing `gross_margin_pct`. Computation precedence: product unit_cost → brand gross_margin_pct → null (margin surfaces show "—" + "set costs" hint). New metrics where revenue already shows: contribution margin €/% (revenue − COGS − ad spend, documented formula tooltip). NEVER invent shipping/fees v1 — those are config-ready fields defaulting null.

**4.3 Data-quality score** — `App\Services\Rules\DataQuality`: per brand 0–100 composed ONLY of measurable parts (each with weight in config): connected-platform coverage vs expected, days-since-last-complete-sync per source, backfill depth vs 12mo target, breakdown/creative/adset coverage, costs-set coverage. Rendered on brand detail + dashboard chip + gate: GO-3/4 recommendations REQUIRE score ≥ threshold (config, default 70) — a strategist that advises on holey data is the generic-advice failure mode. Score breakdown drawer shows exactly what's missing + the one-click backfill.

**4.4 MER spine + bias annotations.** Dashboard + overall report gain a "Truth" row set: MER (store revenue ÷ total spend — already computable), beside per-platform reported ROAS each annotated: Meta+Advantage+ "platform-reported; Advantage+ campaigns over-credit ≈ +12pp vs manual (Haus, 640 experiments)"; Meta manual 7d-click "may UNDER-report DTC (≈$115 real per $100 reported, Haus)"; Google/TikTok "platform-attributed, unverified". Annotation strings live in `config/truth.php` with source URLs in comments — updatable without code.

**Tests:** Klaviyo fetcher fixtures (incl. tz + currency passthrough + 429 Retry-After), email table upserts, margin precedence chain, quality-score composition boundaries, bias annotations present in payloads. **Proof:** suite+tsc+build; one real brand shows email revenue matching Klaviyo UI ±1% for a spot week; quality score visibly moves after a backfill.

---

## 5. GO-2 — Analyse & plan (+ the silent Ledger)

**5.1 Targets & pacing.** `brand_targets` (brand_id, workspace_id, month 'Y-m', revenue_target, spend_cap, roas_target, mer_target, set_by; unique brand+month). Settings UI + dashboard pacing chips: "Day 14/31 · revenue 43% of target · on pace / behind by €X" — pacing math = target × (elapsed complete days ÷ days in month) vs actual, brand tz, complete days only.

**5.2 Budget planner.** Read-only v1 planning grid: last-90d spend & ROAS by brand × platform (× country where breakdowns exist) + editable "next month plan" cells (stored `budget_plans`: brand_id, month, platform, country nullable, planned_spend) + delta vs current run-rate. NO execution, NO API writes — it's a plan document. Export/share via the existing report-share pattern.

**5.3 Forecast baseline.** Per brand: next-90d daily revenue = seasonal naive (same date last year, complete data only) blended with trailing-28d trend (drift) — both terms shown, `Modeled` label, and NO forecast where last-year data is missing (render "insufficient history", never extrapolate from <90d). Used by: pacing projections, GO-4 plan sizing.

**5.4 Anomaly feed.** Daily scheduled scan (deterministic rules, config thresholds): CPM/CPA ±X% vs trailing-28d median per platform, ROAS drop, spend spike, stockout-on-advertised-product (join ad_product_daily × catalog), MER divergence from platform-reported trend (tracking-health signal), zero-delivery day on a connected platform. Feed table `anomalies` (brand_id, date, kind, severity, evidence json, resolved_at null) + dashboard bell + brand-detail strip. Every anomaly is dismissible with a required reason (feeds ledger honesty later).

**5.5 THE LEDGER (silent).** Table `recommendations`: id, workspace_id, brand_id, source (string 40 — 'ad_audit','adset_flags','product_flags','anomaly','seasonal_stale','playbook',…), kind (string 40 — 'pause','scale','fix','launch','budget_shift','creative_refresh',…), subject_type/subject_id (campaign/adset/ad/product/brand), title, evidence (json: numbers + rule + thresholds cited), confidence ('solid'|'early'), status enum('open','accepted','dismissed','expired') default open, status_reason text null, status_by/status_at, outcome_metric (string 24 null — 'roas','cpa','spend_waste','revenue'), baseline_value (decimal null), measured_value_14d, measured_value_30d, outcome ('improved','worsened','flat','unmeasurable') null, measured_at, supersedes_id null, created_at. **INSERT-ONLY** except the status/outcome columns (state machine: open→accepted|dismissed|expired; outcome set once by the measurement job, never by hand). GO-2 ships the table + writers wired into the EXISTING engines (AdAudit actions, AdSetFlags, ProductFlags, anomalies) writing rows invisibly. No UI yet — history accumulates from day one, which is the whole point.

**Tests:** pacing math (mid-month, tz edges), planner CRUD + RBAC, seasonal-naive SQL vs hand-computed fixture, each anomaly rule at/below threshold, ledger writers create rows with complete evidence json, state machine rejects illegal transitions + any UPDATE of evidence. **Proof:** suite green; ledger row count grows after a daily scan on seeded data.

---

## 6. GO-3 — Strategist brain (Ledger visible + hygiene + digests)

**6.1 Seasonal-stale creative detector (Kanwar's flagship example).** `config/seasons.php`: per-season keyword lists ES/EN/FR/IT/DE/NL (christmas/navidad/noël/weihnachten…, BFCM terms, rebajas/soldes/saldi, winter/summer terms, valentine, mother's/father's day…) + each season's date window per market (§7 calendar). Rule: LIVE ad (status active, spend in last 7d) whose body_text/ad_name/creative bodies match season K's keywords while today is > K.end + grace (config 7d) → recommendation kind 'creative_refresh', severity warn, evidence = matched terms + season window + last-7d spend. LLM assist (optional, key-gated): classify ambiguous copy — but a recommendation NEVER fires on LLM output alone; keyword+date rule is the trigger, LLM only enriches the explanation (D-016). Surfaces: ads hub badge, audit card, ledger.

**6.2 Stop/Scale/Fix board.** `/planning` page per brand (tab on ads hub): open ledger recommendations grouped by kind with evidence expanded, Accept / Dismiss(reason required) buttons wired to the state machine. Accept on 'pause'/'scale' does NOT execute anything — it records intent and shows the operator checklist ("pause in Ads Manager, then mark done"). Helm never touches campaign state (doctrine §2).

**6.3 Track record — visible.** Measurement job (daily): for accepted/dismissed rows past 14d/30d, measure outcome_metric on the subject over the window vs baseline_value (rules per kind documented in code: pause-accepted → waste avoided = baseline weekly spend×weeks if ROAS stayed <1; scale-accepted → ROAS held ≥ threshold at higher spend; refresh → CTR/ROAS recovery; unmeasurable when subject vanished — honest bucket). Brand page + workspace page: "Helm's track record: N recommendations · X% accepted · Y% of accepted improved the target metric" with the full filterable ledger table beneath. The number is computed live from ledger rows — no cached vanity metric.

**6.4 Competitor gap map** (needs ads-library corpus): per brand niche — active concepts by market vs the brand's own live campaigns by market → "competitors are live in FR with jewelry video; you have no FR campaigns" cards (Proxy-labelled). Feeds GO-4.

**6.5 Digests.** Weekly per-workspace Slack (webhook per §3.3, stored encrypted in workspace settings + test button) + in-app: new recommendations, anomalies, track-record delta, competitor movement. Block Kit, ≤1/s, honest empty ("quiet week — nothing actionable").

**Tests:** stale-detector per language/season boundary (in-window vs post-window+grace), no-LLM-trigger invariant, board state transitions + RBAC, each outcome-measurement rule on seeded histories incl. 'unmeasurable', gap-map join, webhook payload + failure tolerance. **Proof:** suite green; a seeded Christmas-copy ad past Jan 7+grace produces the recommendation with correct evidence; track-record numbers hand-verified on seeded ledger.

---

## 7. GO-4 — Seasonal playbook engine (the whitespace — build it rule-first)

**7.1 Market calendar.** Table `market_moments` (market 2-char, moment_key, label, starts_on, ends_on, kind enum('legal_sale','gift','event'), source, year) + `calendar:seed {year}` command with THIS seed data embedded (authoritative: EVZ evz.de sale-periods page, updated 2025-01; gift dates: Trusted Shops May 2025 — re-verify yearly, dates shift):
- FR: soldes d'hiver 2nd Wed Jan (4 wks, LAW), soldes d'été last Wed Jun; French Days late Apr + late Sep; Mother's Day LATE MAY (≠ US); St-Nicolas Dec 6.
- ES: rebajas ~Jan 7 (regional, can run to Mar) + Jul 1; Three Kings Jan 6 (PRIMARY gift moment); Father's Day Mar 19; Mother's Day early May.
- IT: saldi early Jan–Feb + early Jul–mid Aug (regional law); Epiphany Jan 6; Father's Day Mar 19; Mother's Day mid-May.
- BE: Jan 3–31 + Jul 1–31 (strict). NL: King's Day Apr 27; Sinterklaas Dec 5 (competes with Christmas); St. Martin's Nov 11. DE/AT: no legal periods; Nikolaus Dec 6; Advent. PL: Children's Day Jun 1; Mother's Day May 26.
- Pan-EU: BFCM (4th Thu Nov + window ~Nov 9–Dec 19 per Triple Whale), Singles Day 11.11, Valentine's Feb 14, Christmas.

**7.2 Playbook physics — `config/playbooks.php`** (every constant carries its source comment; [HELM DEFAULT] where none exists):
`preheat_weeks_start: 8, preheat_weeks_creative_locked: 4` (TGM BFCM guide; CTC "6-week window", 2026) · `event_budget_ramp: [2.0, 4.0]` (TGM) · `min_event_creatives: 7` (TGM "7–10") · `judgment_days_min: 5` (TGM) · `build_lead_hours: 72` (ad review, TGM) · `cpm_spike_scenarios: [0, 10, 20]` % for CAC-ceiling modelling (CTC method; BFCM observed +50–150%) · `email_share_of_event_revenue: [30, 40]` % context (TGM) · `post_event_phase_days: 21` [HELM DEFAULT].

**7.3 Plan generator (rule-assembled; LLM prose ONLY on top).** Input: brand (niche, markets from country revenue + breakdowns, margin, targets, quality score ≥ gate) + moment (from calendar) + own history (same moment last year: revenue/spend/ROAS/CPM by platform via existing tables — Verified) + competitor corpus (niche activity in market — Proxy) + physics constants. Output `campaign_plans` row (brand_id, workspace_id, moment_key, market, status draft/ready/shared, blocks json, created_by): timeline block (dated T-8w…T+3w milestones from constants), budget block (last-year actuals × target growth, CAC ceiling at CPM +0/10/20% from margin — requires margin set, else block renders "set margin first"), channel block (platform split from own history + competitor-activity note), creative block (volume quota, proven hooks from tagged winners, ads-library references, moodboard link), measurement block (targets + MER expectations + judgment-window rule), each block footnoted Verified/Proxy/Modeled/[source]. LLM pass (operator-triggered) turns assembled blocks into client-ready prose — NEVER generates numbers (BrandDataScope-style allowlist audit test mandatory). UI: `/planning` gains Plans tab: moment picker (next 6 moments for the brand's markets) → generate → edit blocks → share (report-share infra). Every generated plan writes a 'playbook' ledger row per actionable block so GO-3 measures plan quality later.

**7.4 Moodboard / brand style.** `brand_styles` (brand_id, palette json, tone_words json, do_dont json, refs json, confirmed_by null): palette extracted from top-20 catalog product images (pure-PHP dominant-color binning — no new deps; GD is in Laravel) + winning-creative thumbnails; tone drafted by LLM from store copy (title/descriptions already in catalog) — ALWAYS operator-reviewed/edited before `confirmed_by` set; unconfirmed style renders "draft" and GO-5 refuses to use it. Moodboard view: palette + top verified winners + saved market ads + tone words.

**Tests:** calendar seed integrity (every market ≥1 moment/quarter; FR soldes = 2nd Wed), generator block math vs hand-computed fixture (budget/CAC scenarios), margin-missing + quality-gate refusals, allowlist audit (no raw customer data to LLM), plan share flow, ledger rows per plan. **Proof:** suite green; a real brand's BFCM plan generated with every number traceable to a table row or config constant — Kanwar reviews one plan personally before this phase is called done.

---

## 8. GO-5 — Creative testing engine (LAST, per Kanwar; repositioned per U4)

Doctrine: **variation/testing engine inside a human loop — never a turnkey ad-maker** (Icon post-mortem + universal "generic output" complaint, assessment §3-U4). Scope v1: TEXT ONLY — copy variants + hooks + UGC scripts. Image/video generation requires an external generation API = new dependency + cost → **hard gate: Kanwar picks the provider and approves spend first; build the seam (`CreativeGenerator` interface), ship text-only.**
1. From a brief (ads-library Phase 4) or a plan's creative block: generate N copy variants + hook lines + UGC shot-scripts via LlmManager — inputs strictly: confirmed brand_style, proven-hook tags + their Verified benchmarks, product facts (name/price/stock), moment context. Operator edits/approves each; approved items stored `creative_drafts` (brand_id, brief_id/plan_id, kind, content json, status draft/approved/exported/launched).
2. Export: copy-paste blocks + CSV; **push-to-Meta as PAUSED ads = phase GO-5b**, gated on Kanwar approving `ads_management` token scope (writes to client accounts = new risk class; ADR required). Never auto-publish, never touch budgets (doctrine).
3. Ledger closes the loop: exported/launched drafts link to the resulting ad ids (operator attaches; CampaignNameParser can suggest) → 30d outcomes → "AI-assisted vs other creatives" honest comparison per brand — publishable proof nobody else has (whitespace: no independent AI-vs-human benchmark exists).

**Tests:** generation inputs allowlist, refusal on unconfirmed style, draft lifecycle + RBAC, export formats, ledger linkage. **Proof:** suite green; one brand's brief produces operator-approved variants grounded in its confirmed moodboard; zero LLM calls contain raw customer rows (audit test).

---

## 9. Cross-cutting requirements

- **RBAC:** planning/ledger/digest config = master_admin+manager; team_member sees own-brand read-only boards; unassigned = 404 (brand invisibility convention — cf. DataCoverageTest pattern).
- **Tenancy (D-022):** workspace_id nullable on every new table above; per-workspace credentials (Klaviyo keys, Slack webhooks, GA4 grants); no cross-tenant reads anywhere — add a CI test greping new queries for workspace scoping once tenancy activates.
- **Performance:** ledger + anomalies tables get (brand_id, created_at) indexes; measurement job chunks; plan generation is on-demand only (never scheduled LLM spend — D-016 cost stance).
- **Deploy per phase:** standard `git pull && bash scripts/deploy.sh`; new config files ⇒ verify config:cache; new scheduled commands need nothing (cron exists); any queue additions keep timeout < 3600 < 3700 chain.
- **Docs per phase:** AS-BUILT update + ADR row for deviations + memory topic file if Fable access returns.

## 10. Decisions already made (do not re-ask)

| Decision | Answer |
|---|---|
| Phase order | GO-1→5 strictly; ads-library program finishes first; GO-5 text-only until provider approved |
| Attribution stance | Triangulated truth: MER spine + annotated platform numbers; never "100% accurate" |
| Ledger | Insert-only, from GO-2, silent first; outcomes measured by job only; track record computed live |
| Klaviyo auth | Per-client private key v1 (scopes at creation), OAuth seam later |
| GA4 | OPTIONAL (Shopify funnel already synced); build only on client demand |
| Forecasts | Seasonal naive + drift, zero deps, `Modeled` label, refuse on thin history (fpp3 §5.2) |
| Execution | Helm NEVER writes to ad platforms in GO-1..5 except gated GO-5b paused drafts |
| Creative gen | Testing engine, human-approved, text-first; image/video behind provider+cost gate |
| Labels | Verified / Proxy / Modeled on every metric, no exceptions |

## 11. Kanwar-owed inputs (the doc reminds; only he can do)

1. Klaviyo private keys per brand (read scopes) as clients grant them.
2. Per-brand margins/target CPAs (still open from the product-audit build) + product costs where unitCost isn't in Shopify.
3. Slack workspace webhook (via the Slack-app install flow) when digests land.
4. Ads-library ToS read + Meta identity verification (still the Phase-2 gate there).
5. GO-5 image/video provider choice + budget, and the separate ads_management write-scope decision (ADR each).
6. Yearly `calendar:seed` review (sale dates shift; EVZ is the anchor).
7. Secrets rotation + the old owed list (see memory helm_security_secrets — still open).

## 12. Build order & dependency graph

```
[Ads-library phases 0–5: in flight] ──┐
GO-1 Klaviyo+costs+quality+truth ─────┼─→ GO-2 targets/planner/forecast/anomalies + LEDGER(silent)
                                      │        └─→ GO-3 stale-detector + board + track record + digests
[needs ads-library corpus] ───────────┴────────────→ GO-3.4 gap map → GO-4 playbook engine (calendar+physics+moodboard)
                                                                 └─→ GO-5 creative testing (text) → GO-5b paused-push [gated]
```
Rough sizing (agent-days, honest guesses — measure, don't promise): GO-1 ≈ 4–6 · GO-2 ≈ 4–5 · GO-3 ≈ 5–7 · GO-4 ≈ 6–8 · GO-5 ≈ 3–4. Each phase independently shippable and client-visible.
