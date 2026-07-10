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

// Dead / overstocked inventory (slice 2.1) — by product / collection.
export interface DeadInventoryRow {
  key: string;
  label: string;
  endingUnits: number;
  unitsSold: number;
  sellThrough: number | null;
  coverDays: number | null;
  status: 'dead' | 'slow';
}

export interface DeadInventorySection {
  capturedOn: string;
  windowDays: number;
  rows: DeadInventoryRow[];
  deadCount: number;
  deadUnits: number;
  flaggedItems: number;
}

export interface DeadInventoryData {
  byProduct: DeadInventorySection | null;
  byCollection: DeadInventorySection | null;
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
/** The four LLM narrative blocks (D-016). Keys are stable; labels live in the UI. */
export interface NarrativeBlocksShape {
  observations: string;
  actions: string;
  plan: string;
  ideas: string;
}

/** Server-stored narrative draft + edit state for one filter selection. */
export interface ReportNarrativePayload {
  blocks: NarrativeBlocksShape;      // what should render (edits win)
  draftBlocks: NarrativeBlocksShape; // the model's untouched draft
  isEdited: boolean;
  provider: string;
  model: string;
  language: string;
  generatedAt: string | null;
  editedAt: string | null;
}

export interface ReportContent {
  commentary?: string; // the top "Summary" block
  // Narrative blocks as they were at share time — the public page renders
  // these verbatim (D-016: edited before send).
  narrativeBlocks?: NarrativeBlocksShape;
  nextSteps?: string;  // end-of-report "Next steps / to discuss" block
  // Monthly report — agency-set targets for the Overall picture (editable, saved
  // with the share alongside the commentary).
  targets?: { blendedRoas?: number | null; newCustomerRoas?: number | null };
  actions?: { kind: 'stop' | 'fix' | 'scale'; title: string; body: string }[];
  regionRead?: string;
  productRead?: string;
  collectionRead?: string;
  metaAuditRead?: string;
  googleAuditRead?: string;
  strategyRead?: string;
}

export interface OverallPerformanceReportData {
  reportType: 'overall-performance';
  // LLM layer (D-016): stored draft for this filter selection + availability.
  narrative?: ReportNarrativePayload | null;
  llm?: { enabled: boolean; provider: string };
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
  deadInventory?: DeadInventoryData | null;
  adsAudit?: AdAuditSection[];
  freshness?: { upToDate: boolean; lastSynced: string | null; staleDays: number; windowEnd: string };
  branding: ReportBranding;
  content?: ReportContent | null;
  shared?: boolean;
}

// ── Monthly client report (agency → store owner) ────────────────────────────
// Month-over-month heatmap sections; each carries a readiness status so the doc
// renders the whole plan and lights sections up as their data lands.
export interface MonthlySeriesRow {
  key: string;
  label: string;
  byMonth: Record<string, number | null>; // Y-m => revenue (null = month not synced → "—")
  total: number;
  yoyTotal: number;
  deltaYoY: number | null;
  orders: number;
  share?: number | null; // 0–1, top rows only
}

export interface MonthlySeriesData {
  months: string[]; // Y-m, chronological
  rows: MonthlySeriesRow[];
  other: { byMonth: Record<string, number | null>; total: number; yoyTotal: number; deltaYoY: number | null; share: number | null; count: number } | null;
  total: number;
}

export type MonthlySectionStatus = 'ready' | 'coming' | 'needs_source' | 'no_data';

// Single-month metric rows (e.g. ad spend by gender). Reach/frequency are absent
// until they're added to the Meta breakdown pull.
export interface MonthlyGenderRow {
  label: string;
  cost: number;
  reach: number | null;
  freq: number | null;
  clicks: number;
  cpc: number | null;
  ctr: number | null;
  cpm: number | null;
  purchases: number;
  roas: number | null; // attributed revenue ÷ spend
  cpa: number | null; // spend ÷ purchases
  share: number | null;
}

// ROAS-by-country month-over-month: per-month ROAS (rev ÷ Meta spend) + the
// window spend behind it. Cells are heat-tinted against the section's blended ROAS.
export interface MonthlyRoasRow {
  key: string;
  label: string;
  byMonth: Record<string, number | null>; // Y-m => ROAS
  spend: number;
  roas: number | null;
}

export interface MonthlyRoasData {
  months: string[];
  rows: MonthlyRoasRow[];
  blended: number | null;
  unattributed?: number | null; // Advantage+ Meta spend with no country — footnoted, not a row
}

// Landing page × best sellers: Meta spend vs product revenue, with stock + a read.
export interface MonthlyLandingRow {
  label: string;
  spend: number;
  revenue: number;
  roas: number | null;
  units: number;
  stock: number;
  read: string;
}

// Ad spend by placement (IG · Feed, IG · Reels, …). Reach/freq are null until a
// re-sync captures reach on the breakdown pull.
export interface MonthlyPlacementRow {
  label: string;
  cost: number;
  reach: number | null;
  freq: number | null;
  clicks: number;
  cpc: number | null;
  ctr: number | null;
  cpm: number | null;
  purchases: number;
  roas: number | null; // attributed revenue ÷ spend
  cpa: number | null; // spend ÷ purchases
  share: number | null;
}

// New vs existing customers, one row per trailing month. Revenue is NOT split by
// customer type (no customer_type dimension on ShopifyQL sales) — counts only,
// with blended revenue/spend/ROAS alongside. CAC = spend ÷ new customers.
export interface MonthlyCustomerRow {
  month: string;
  new: number;
  returning: number;
  total: number;
  retPct: number | null; // returning ÷ total, %
  revenue: number;
  orders: number;
  aov: number | null;
  spend: number;
  roas: number | null;
  roasNew: number | null; // ESTIMATE: new customers × AOV ÷ ad spend
  cac: number | null; // ad spend ÷ new customers
}

// Web funnel row — sessions → cart → checkout → purchase, by country / landing.
export interface MonthlyFunnelRow {
  label: string;
  sessions: number;
  cart: number;
  checkout: number;
  purchase: number;
  cvr: number | null; // purchase ÷ sessions %
}

export interface MonthlyReportSection {
  status: MonthlySectionStatus;
  data?: MonthlySeriesData;          // month-over-month heat table (country/category/product/market)
  metrics?: MonthlyGenderRow[];      // single-month metric table (gender)
  roas?: MonthlyRoasData;            // month-over-month ROAS heat table (roas by country)
  products?: MonthlyLandingRow[];    // landing × best sellers (spend vs revenue + stock)
  placement?: MonthlyPlacementRow[]; // ad spend by placement
  funnel?: MonthlyFunnelRow[];       // web funnel by country / landing path
  customers?: MonthlyCustomerRow[];  // new vs existing customers, month over month
  note?: string;
}

export interface MonthlyKpi {
  value: number | null;
  previous: number | null;
  deltaPct: number | null;
  deltaAbs: number | null;
}

export interface MonthlyReportData {
  reportType: 'monthly';
  brand: { name: string; slug: string; baseCurrency: string; timezone: string };
  currency: string;
  month: { label: string; start: string; end: string };
  comparison: { mom: string; yoy: string };
  overall: {
    blendedRoas: MonthlyKpi;
    revenue: MonthlyKpi;
    adSpend: MonthlyKpi;
    newCustomerRoas: MonthlyKpi | null;
    acquisitionYoY: MonthlyKpi | null;
  };
  sections: {
    countryRevenue: MonthlyReportSection;
    categories: MonthlyReportSection;
    bestSellers: MonthlyReportSection;
    roasByCountry: MonthlyReportSection;
    gender: MonthlyReportSection;
    market: MonthlyReportSection;
    placement: MonthlyReportSection;
    landingSellers: MonthlyReportSection;
    newVsExisting: MonthlyReportSection;
    funnelCountry: MonthlyReportSection;
    funnelLanding: MonthlyReportSection;
  };
  branding: ReportBranding;
  content?: ReportContent | null;
  shared?: boolean;
  // Monthly report is inherently the last complete month, so it has no freshness
  // gate — kept optional so the view pages can read it across the report union.
  freshness?: { upToDate: boolean; lastSynced: string | null; staleDays: number; windowEnd: string };
}

// The report pages accept any report type; they branch on `reportType`.
export type AnyReportData = OverallPerformanceReportData | MonthlyReportData;

export interface ReportFiltersInput {
  period: 'last7' | 'last30' | 'mtd';
  compare: 'previous' | 'last_year' | 'none';
}

export const DEFAULT_BRANDING: ReportBranding = {
  agency_name: 'Roasdriven',
  accent: '#1f6f5c',
  footer_text: 'Powered by novasolution.ae',
};
