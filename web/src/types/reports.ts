// Reporting & Creative Intelligence — payload types (feature spec slice 2.0).
// The server builds a render-ready payload; these mirror it. Each report type
// will add its own payload shape; 2.0 ships Overall Performance.

export interface ReportBranding {
  agency_name: string;
  accent: string;
  footer_text: string;
}

export interface ReportTypeItem {
  key: string;
  label: string;
}

export interface ReportKpi {
  value: number | null;
  previous: number | null;
  deltaPct: number | null;
  deltaAbs: number | null;
}

export interface ReportRow {
  label: string;
  kind: 'money' | 'ratio' | 'int';
  value: number | null;
  previous: number | null;
  deltaPct: number | null;
  deltaAbs: number | null;
}

export interface ReportPlatformSpend {
  platform: string;
  connected: boolean;
  spend: number | null;
}

// Granular commerce breakdown (slice 2.1) — by region / product / category.
export type CommerceTrend = 'dead' | 'wounded' | 'stable' | 'growing' | 'new' | null;

export interface CommerceRow {
  key: string;
  label: string;
  revenue: number;
  orders: number;
  aov: number | null;
  share: number | null; // 0–1, within the section
  previous: number | null;
  deltaPct: number | null;
  trend: CommerceTrend;
}

export interface CommerceMatrixBucket {
  bucket: 'dead' | 'wounded' | 'new' | 'growing';
  count: number;
  samples: { label: string; deltaPct: number | null; revenue: number }[];
}

export interface CommerceSection {
  rows: CommerceRow[];
  other: { revenue: number; orders: number; share: number | null; count: number } | null;
  total: { revenue: number; orders: number };
  matrix: CommerceMatrixBucket[] | null;
}

// Campaign-level ads audit (slice 2.2 / 2.4) — Meta + Google.
export type AdVerdict = 'dead' | 'scaling_loss' | 'weak' | 'winner' | 'steady' | 'minor';

export interface AdAuditKpi {
  value: number | null;
  previous: number | null;
  deltaPct: number | null;
}

export interface AdCampaignRow {
  id: string;
  name: string;
  spend: number;
  roas: number | null;
  conversions: number;
  prevRoas: number | null;
  spendDelta: number | null;
  verdict: AdVerdict;
  action: string;
}

export interface AdAuditSection {
  platform: string;
  kpis: { spend: AdAuditKpi; purchases: AdAuditKpi; roas: AdAuditKpi; ctr: AdAuditKpi; cpm: AdAuditKpi };
  waste: { amount: number; sharePct: number | null; count: number };
  campaigns: AdCampaignRow[];
  totalCampaigns: number;
  actions: { kind: 'stop' | 'fix' | 'scale'; title: string; body: string }[];
}

// Analyst narrative + action plan — written by the LLM layer (slice 2.3) and
// editable before send. Every field optional: the report renders cleanly with
// none of it (data-only), and richer as each slot is filled.
export interface ReportContent {
  commentary?: string;
  actions?: { kind: 'stop' | 'fix' | 'scale'; title: string; body: string }[];
  regionRead?: string;
  productRead?: string;
  collectionRead?: string;
  metaAuditRead?: string;
  googleAuditRead?: string;
  strategyRead?: string;
}

export interface OverallPerformanceReportData {
  reportType: string;
  brand: { name: string; slug: string; baseCurrency: string; timezone: string };
  currency: string;
  period: { label: string; start: string; end: string };
  comparison: { label: string | null; start: string; end: string } | null;
  kpis: {
    revenue: ReportKpi;
    adSpend: ReportKpi;
    blendedRoas: ReportKpi;
    orders: ReportKpi;
    aov: ReportKpi;
  };
  revenueVsSpend: ReportRow[];
  byPlatform: ReportPlatformSpend[];
  spendComplete: boolean;
  // Slice 2.1 — null until shopify:backfill-commerce has landed rows.
  byRegion?: CommerceSection | null;
  byProduct?: CommerceSection | null;
  byCategory?: CommerceSection | null;
  adsAudit?: AdAuditSection[];
  branding: ReportBranding;
  content?: ReportContent | null;
  shared?: boolean;
}

export interface ReportFiltersInput {
  period: 'last7' | 'last30' | 'mtd';
  compare: 'previous' | 'last_year' | 'none';
}

export const DEFAULT_BRANDING: ReportBranding = {
  agency_name: 'Roasdriven',
  accent: '#1f6f5c',
  footer_text: 'Powered by novasolution.ae',
};
