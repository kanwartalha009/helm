# Google Ads view — Ads hub parity (Google-native, not a Meta clone)

Status: **shipped** — brand-split + channel-mix + device. Geo deliberately skipped. Owner: Nova.
Related: `docs/feature-specs/reporting-and-creative-intelligence.md`, ads hub (Ads Overview / Audience / Creatives).

## Why this exists

The Ads hub Overview looks blank on Google next to Meta/TikTok. Google only ever
had the core KPI/trend/campaign pull; every richer section (funnel, breakdowns,
engagement, creatives) was built Meta-first. But Google **cannot** and **should
not** mirror Meta/TikTok — its data model, campaign types, and reporting limits
are fundamentally different. This spec is the Google-native version.

## What the live account actually runs (Nude Project, customer 9373633600, last 30d)

Pulled via the Google Ads API. Spend mix is roughly **⅔ Performance Max**,
**⅓ Search** (BRAND-PURE + BRAND-GENERIC + generic, per country), **a sliver of
Shopping**. **No Video/YouTube or Display campaigns at all.** Campaign names encode
country + channel + brand/generic (`NP_US_GADS_PMAX_ALL_MIX_UNISEX`,
`NP_UK_GADS_BRAND-PURE_SEARCH_MIX_UNISEX`) — parseable.

This single fact drives every scope decision below.

## Feasibility by Helm section

| Section | Google? | Notes |
|---|---|---|
| Core KPIs (ROAS/rev/purch/CPA/AOV/CPM/CPC/CTR) | have | Already synced (daily_metrics + ad_campaign_daily_metrics) |
| Reach & frequency | no | Not exposed for Search/PMax — omit for Google |
| Funnel | partial | Impressions → Clicks → Conversions only; no link-click/ATC steps |
| Device (desktop/mobile/tablet/CTV) | yes | Confirmed live via `segments.device` — clean, full |
| Geo (country / region) | yes | `geographic_view`; partly redundant (campaigns already per-country) |
| Age / gender | **partial** | Only Search reports it; **PMax (⅔ of spend) returns zeros**, and Search is heavy on "Undetermined". NOT account-wide like Meta/TikTok |
| Channel mix (PMax / Search·Brand / Search·Generic / Shopping) | **yes** | The Google-native cut a DTC agency reviews. Parse campaign names |
| Video engagement panel | no | No video campaigns — skip entirely |
| Creatives / thumbnails | hard | PMax = asset groups, Search = text ads, Shopping = product images. No unified video-thumbnail grid |

## Build plan (priority)

1. **Channel mix — DONE (v1).** Read-time fold of `ad_campaign_daily_metrics[google]`
   into channel segments via campaign-name parsing (`AdsOverviewQuery::channelBreakdown()`
   + `googleChannel()`). No new sync, no migration. Renders as a "Channel mix" panel
   on the Google Overview (`byChannel`, reuses the `.abrk` bar layout). Order matters:
   GENERIC tested before BRAND (BRAND-GENERIC contains both); unrecognised → Other.
2. **Brand vs non-brand — DONE.** `brandSplit()` + `isBrandCampaign()` fold campaigns
   into Brand / Non-brand / Performance Max (mixed). The bar is share of REVENUE and
   the panel carries the incrementality caveat in-product (not a footnote). This is the
   deterministic foundation a future audit sits on.
3. **Device split — DONE.** `ReportsFetcher::fetchBreakdownRange($conn,'device',…)`
   (GAQL `segments.device` from `campaign`, enum decoded via `DeviceEnum\Device::name()`)
   → `meta_breakdown_daily[platform=google, breakdown_type=device]` via
   `CampaignSync::syncGoogleBreakdown()`; daily sync + `google:backfill-breakdown` command.
   Gated on a targeted `$deviceable` so Google gets the device donut/detail but no empty
   region map.
4. **Geo rollup — NOT built (deliberate).** For per-country-campaign accounts it
   duplicates the campaign table and needs geo_target_constant name resolution — cost
   without value. `fetchBreakdownRange` has a `$dimension` seam; add a 'country' branch
   only if a buyer runs single global campaigns.

**Skip:** video-engagement panel, reach/frequency (no data), geo. **Demographics:** defer;
if built, label **"Search only"** and never render PMax spend as if it has age/gender.

## Caveats to bake in (agency-trust critical)

- **`conversions` = all primary conversion actions, not strictly purchases.** Nude
  Project's values look purchase-like (AOV ~€100–150), but confirm the account's
  primary action is purchase, or filter to `segments.conversion_action_category = PURCHASE`.
- **Demographics are a Search subset, never account-wide** — PMax is a black box for
  age/gender. Showing a half-account chart as the whole account erodes trust.
- **No reach/frequency** for Search/PMax.
- **Data-driven attribution** — Google ROAS won't reconcile with Meta/TikTok; that's
  expected. Blended ROAS (Shopify ÷ total spend) stays the cross-platform truth.

## White-label note

Channel detection is name-convention based (fits `NP_<cc>_GADS_<CHANNEL>_…`). A later
pass can store the real `advertising_channel_type` on the campaign row for channel
certainty independent of naming; brand-vs-generic will always be name-derived (no API
field exists for it).
