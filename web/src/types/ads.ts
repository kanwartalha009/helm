// Ads hub — per-brand ad-platform Overview (Meta today; platform-agnostic).
// Mirrors the API shape returned by AdsOverviewQuery::run (GET
// /api/brands/{slug}/ads). All money is in the brand's native currency unless
// ?currency=USD. Metrics are Meta-ATTRIBUTED (7d_click purchases + value ÷
// spend), not blended — an ads view ranks campaigns / countries / devices.

export type AdsPeriod = 'last7' | 'last30' | 'mtd' | 'custom';

export interface AdsBrand {
  id: number;
  name: string;
  slug: string;
  initials: string;
  baseCurrency: string;
  timezone: string;
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
}

export interface AdsByCountry {
  hasData: boolean; // false until `meta:backfill-breakdown country` has run
  top: AdsCountryRow | null;
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
  campaigns: AdsCampaignRow[];
}
