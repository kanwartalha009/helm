// Ads hub — per-brand ad-platform Overview (Meta today; platform-agnostic).
// Mirrors the API shape returned by AdsOverviewQuery::run (GET
// /api/brands/{slug}/ads). All money is in the brand's native currency unless
// ?currency=USD. Metrics are Meta-ATTRIBUTED (7d_click purchases + value ÷
// spend), not blended — an ads view ranks campaigns / countries / devices.

export type AdsPeriod = 'last7' | 'last14' | 'last30' | 'mtd' | 'lastmonth' | 'custom';
export type AdsPlatform = 'meta' | 'google' | 'tiktok';

export interface AdsBrand {
  id: number;
  name: string;
  slug: string;
  initials: string;
  baseCurrency: string;
  timezone: string;
  platforms: AdsPlatform[]; // ad platforms with an active connection on this brand
}

// Ratios are null (not 0) when their denominator is zero — the UI renders "—".
export interface AdsSummary {
  spend: number;
  revenue: number;
  purchases: number;
  impressions: number;
  clicks: number;
  roas: number | null;
  cpa: number | null;
  aov: number | null;
  cpm: number | null;
  cpc: number | null;
  ctr: number | null;
  // reach summed over a window is an upper bound; frequency = impressions ÷ reach
  // (approximate). Both null until the funnel fields are synced for the window.
  reach: number | null;
  frequency: number | null;
  // % change vs the prior equal-length window, per metric key; null = no baseline.
  delta: Record<string, number | null>;
}

export interface AdsTrendPoint {
  date: string; // Y-m-d, brand timezone
  spend: number;
  revenue: number;
  purchases: number;
  impressions: number;
  clicks: number;
}

// value/pending: Impressions + Purchases are live; Link clicks + Add to cart are
// null+pending until the 4 Meta fields land.
export interface AdsFunnelStep {
  key: string;
  label: string;
  value: number | null;
  pending: boolean;
}

export interface AdsCountryRow {
  key: string;
  label: string;
  spend: number;
  revenue: number;
  purchases: number;
  impressions: number;
  clicks: number;
  roas: number | null;
  cpa: number | null;
  ctr: number | null;
  pct: number; // this segment's share of window spend
}

export interface AdsByCountry {
  hasData: boolean; // false until `meta:backfill-breakdown` has run for the axis
  top: AdsCountryRow | null;
  total?: number; // total spend across all segments (denominator for pct)
  rows: AdsCountryRow[];
}

export interface AdsDeviceRow {
  label: string;
  value: number;
  pct: number;
}

export interface AdsByDevice {
  hasData: boolean; // false until the `device` breakdown has been backfilled
  metric: string; // 'purchases'
  total: number;
  rows: AdsDeviceRow[];
}

// Deterministic per-campaign signal (judged vs the account's own efficiency,
// with a spend floor). null = not enough spend to judge, or performing normally.
export type AdsSignal = 'scale' | 'cut' | 'watch';

export interface AdsCampaignRow {
  id: string;
  name: string;
  status: string | null;
  spend: number;
  revenue: number;
  purchases: number;
  impressions: number;
  clicks: number;
  roas: number | null;
  cpa: number | null;
  ctr: number | null;
  deltaImpressions: number | null;
  signal: AdsSignal | null;
  signalReason: string | null;
}

export interface AdsIssueCampaign {
  id: string;
  name: string;
  spend: number;
  roas: number | null;
  purchases: number;
  reason: string | null;
}

// Account "issues & fixes" — cut candidates (with wasted spend), watch, scale.
export interface AdsIssues {
  hasData: boolean;
  wastedSpend: number;
  cutCount: number;
  scaleCount: number;
  cut: AdsIssueCampaign[];
  watch: AdsIssueCampaign[];
  scale: AdsIssueCampaign[];
}

export interface AdsOverviewResponse {
  brand: AdsBrand;
  platform: string; // 'meta'
  period: AdsPeriod;
  from: string;
  to: string;
  currency: string; // 'native' | 'usd'
  isComplete: boolean; // freshness gate — false renders an amber "not fully synced"
  summary: AdsSummary;
  trend: AdsTrendPoint[];
  funnel: AdsFunnelStep[];
  byCountry: AdsByCountry;
  byDevice: AdsByDevice;
  // Demographic sub-views (Phase C) — same shape as byCountry. Empty until the
  // matching breakdown has been backfilled; the Audience tab hides empty panels.
  byAgeGender: AdsByCountry;
  byGender: AdsByCountry; // male / female / unknown, folded from age_gender
  byAge: AdsByCountry; // age buckets, folded from age_gender
  byPlacement: AdsByCountry; // publisher platform (Facebook / Instagram / …)
  byPlacementDetail: AdsByCountry; // platform × position (e.g. Instagram · Stories)
  byDeviceDetail: AdsByCountry; // impression device, by spend (Overview donut is by purchases)
  byAudience: AdsByCountry; // ASC segment: new / engaged / existing / unknown
  byRegion: AdsByCountry; // country rolled up into regions (Europe, North America, …)
  byChannel: AdsByCountry; // Google-only: campaigns folded into channel mix (PMax / Search·Brand / …)
  byBrandType: AdsByCountry; // Google-only: Brand vs Non-brand vs Performance Max (incrementality lens)
  tiktokNative: AdsTikTokNative | null; // TikTok-only video + social engagement
  metaNative: AdsMetaNative | null; // Meta-only video completion + social engagement
  campaigns: AdsCampaignRow[];
  issues: AdsIssues; // deterministic "issues & fixes" from per-campaign signals
}

// TikTok-native engagement (video completion + social) — TikTok only.
export interface AdsTikTokNative {
  hasData: boolean;
  video: {
    plays: number;
    watched2s: number;
    watched6s: number;
    p25: number;
    p50: number;
    p75: number;
    p100: number;
    completionRate: number | null; // p100 ÷ plays %
  };
  social: {
    likes: number;
    comments: number;
    shares: number;
    follows: number;
    profileVisits: number;
  };
}

// Meta-native engagement (video completion + social) — Meta only. No "6-sec"
// (ThruPlay is Meta's deep-watch signal) and no profile visits (Meta reports
// none for ads); "follows" maps to Page likes.
export interface AdsMetaNative {
  hasData: boolean;
  video: {
    plays: number;
    watched3s: number; // Meta's 3-sec video plays (hook metric); 2-sec doesn't populate
    thruplays: number;
    p25: number;
    p50: number;
    p75: number;
    p100: number;
    completionRate: number | null; // p100 ÷ plays %
  };
  social: {
    likes: number;
    comments: number;
    shares: number;
    pageLikes: number;
  };
}

// Campaign drill-down (Phase B) — one campaign's KPIs + daily trend.
// summary reuses AdsSummary (reach/frequency are null — those live at the
// account level, not per campaign).
export interface AdsCampaignDetail {
  campaign: { id: string; name: string; status: string | null };
  period: AdsPeriod;
  from: string;
  to: string;
  currency: string;
  brand: { baseCurrency: string };
  summary: AdsSummary;
  trend: AdsTrendPoint[];
}

// Creatives (Phase D) — one ad-level card.
export type AdsCreativeState = 'scaling' | 'declining' | 'holding' | 'testing' | 'hidden';

export interface AdsCreative {
  adId: string;
  name: string;
  campaignId: string | null;
  thumbnail: string | null;
  mediaType: 'image' | 'video';
  state: AdsCreativeState;
  wow: number | null; // spend % vs prior equal window; null = new ad (no baseline)
  spend: number;
  revenue: number;
  purchases: number;
  impressions: number;
  clicks: number;
  roas: number | null;
  cpa: number | null;
  ctr: number | null;
  ts: number | null; // Thumbstop % (video only): 3-sec views / impressions
  hr: number | null; // Hold rate % (video only): ThruPlays / impressions
  ctp: number | null; // Click→Purchase %: purchases / clicks
  ctatc: number | null; // Click→Add-to-cart %: add-to-cart / clicks
}

export interface AdsCreativesResponse {
  hasData: boolean; // false until meta:backfill-creatives has run
  from: string;
  to: string;
  currency: string;
  baseCurrency: string;
  count: number; // total ads analyzed in the window (uncapped)
  totalSpend: number; // total spend across all ads (denominator for % visible)
  trend: AdsTrendPoint[]; // daily totals across all creatives — powers KPI sparklines
  rows: AdsCreative[];
}
