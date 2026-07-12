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
  // "Dead" = sold ≤ deadThresholdUnits units in the snapshot window (10% of
  // this brand's median). Optional so a cached pre-migration payload never
  // renders "undefined units".
  deadThresholdUnits?: number;
  medianUnits?: number | null;
}

// byCollection was removed server-side (2026-07-10) — the block is byProduct only.
export interface DeadInventoryData {
  byProduct: DeadInventorySection | null;
}

// Campaign-level ads audit (slice 2.2 / 2.4) — Meta + Google + TikTok.
export type AdVerdict = 'dead' | 'scaling_loss' | 'weak' | 'winner' | 'steady' | 'minor';

// Verdict confidence: 'early' = under $150 of spend in the window — direction
// is indicative only, so the UI flags it and never over-claims.
export type AdConfidence = 'solid' | 'early';

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
  confidence?: AdConfidence;
}

export interface AdAuditAction {
  kind: 'stop' | 'fix' | 'scale';
  title: string;
  body: string;
  confidence?: AdConfidence;
}

export interface AdAuditSection {
  platform: string;
  kpis: { spend: AdAuditKpi; purchases: AdAuditKpi; roas: AdAuditKpi; ctr: AdAuditKpi; cpm: AdAuditKpi };
  waste: { amount: number; sharePct: number | null; count: number };
  campaigns: AdCampaignRow[];
  totalCampaigns: number;
  actions: AdAuditAction[];
  // How many days of data the verdicts read — optional so cached
  // pre-migration payloads stay renderable.
  windowDays?: number;
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

export interface TruthBlock {
  storeRevenue: number;
  totalSpend: number;
  mer: number | null;
  merLabel: string;
  merFormula: string;
  platforms: {
    platform: 'meta' | 'google' | 'tiktok';
    spend: number;
    reportedRevenue: number;
    reportedRoas: number | null;
    label: string;
    annotation: string;
  }[];
  divergenceNote: string;
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
  /**
   * GO-1.4 triangulated truth. MER is the spine (store revenue ÷ total ad spend);
   * `platforms` is a LIST of what each platform claims about itself, each with its
   * bias annotation. There is deliberately NO total of reported revenue — two
   * platforms routinely claim the same order, so summing them is a fiction.
   * Optional so pre-migration cached payloads still render.
   */
  truth?: TruthBlock | null;
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
  yoyTotal: number | null; // null when any prior-year month is unsynced → "—"
  deltaYoY: number | null;
  orders: number;
  share?: number | null; // 0–1, top rows only
}

export interface MonthlySeriesData {
  months: string[]; // Y-m, chronological
  rows: MonthlySeriesRow[];
  other: { byMonth: Record<string, number | null>; total: number; yoyTotal: number | null; deltaYoY: number | null; share: number | null; count: number } | null;
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

/** Channel mix — Meta / Google / TikTok side by side for the month. Revenue
 * and ROAS are platform-reported (attribution overlaps); share is of spend. */
export interface MonthlyChannelRow {
  platform: string;
  label: string;
  spend: number;
  purchases: number;
  revenue: number;
  roas: number | null;
  cpa: number | null;
  share: number | null;
}

// Gender / placement (2026-07-10 shape change): the section now carries one
// row set PER PLATFORM (Meta today, TikTok when its breakdown sync lands).
// Row shape is unchanged — the platforms wrapper is the only difference.
export interface MonthlyPlatformSection<TRow> {
  status: 'ok' | 'no_data';
  platforms: { platform: string; rows: TRow[] }[];
  note?: string;
}

export interface MonthlyReportSection {
  status: MonthlySectionStatus;
  data?: MonthlySeriesData;          // month-over-month heat table (country/category/product/market)
  metrics?: MonthlyGenderRow[];      // single-month metric table (gender)
  roas?: MonthlyRoasData;            // month-over-month ROAS heat table (roas by country)
  products?: MonthlyLandingRow[];    // landing × best sellers (spend vs revenue + stock)
  placement?: MonthlyPlacementRow[]; // ad spend by placement
  channels?: MonthlyChannelRow[];    // channel mix (Meta/Google/TikTok side by side)
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

// A selectable report window (month / week pickers on the view page).
export interface ReportWindowOption {
  key: string; // 'YYYY-MM' for months, 'YYYY-MM-DD' (Monday) for weeks
  label: string;
}

export interface MonthlyReportData {
  reportType: 'monthly';
  brand: { name: string; slug: string; baseCurrency: string; timezone: string };
  currency: string;
  month: { label: string; start: string; end: string };
  // Selectable months, latest first — first entry is the default the server
  // builds when no ?month= is passed.
  availableMonths?: ReportWindowOption[];
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
    gender: MonthlyPlatformSection<MonthlyGenderRow>;
    market: MonthlyReportSection;
    channelMix: MonthlyReportSection;
    placement: MonthlyPlatformSection<MonthlyPlacementRow>;
    landingSellers: MonthlyReportSection;
    newVsExisting: MonthlyReportSection;
    funnelCountry: MonthlyReportSection;
    funnelLanding: MonthlyReportSection;
    // Klaviyo email revenue (GO-1.1) — its own channel, never summed into revenue.
    // Optional so pre-migration cached payloads still render.
    email?: MonthlyEmailSection;
  };
  branding: ReportBranding;
  content?: ReportContent | null;
  shared?: boolean;
  // Same contract as the other reports: upToDate requires the latest complete
  // Shopify day to reach the report month's end — the view pages gate on it.
  freshness?: { upToDate: boolean; lastSynced: string | null; staleDays: number; windowEnd: string };
}

// ── Weekly performance report (the Monday client email) ─────────────────────
// The last COMPLETE Mon–Sun ISO week in the brand's timezone; build() ignores
// the period filter (like monthly). Compared against the previous week, plus
// the same week last year when the brand has rows that far back.
export interface WeeklyKpi {
  value: number | null;
  previous: number | null; // previous week
  deltaPct: number | null; // WoW, null for ratio KPIs
  deltaAbs: number | null; // WoW, ratio KPIs only
  lastYear: number | null; // same ISO week last year, null when no rows exist
  yoyPct: number | null;
  yoyAbs: number | null;
}

export interface WeeklyDay {
  date: string;
  revenue: number | null; // null when the day is unsynced or incomplete — never 0
  spend: number | null; // null when no ad rows landed that day
  complete: boolean;
}

export interface WeeklyCampaignMover {
  platform: string;
  id: string;
  name: string;
  spend: number;
  revenue: number;
  roas: number | null;
  prevSpend: number | null;
  spendDelta: number | null; // WoW %
  prevRoas: number | null;
  roasDelta: number | null; // WoW absolute ×
}

export interface WeeklyAction {
  kind: 'stop' | 'fix' | 'scale';
  title: string;
  body: string;
  platform: string;
  confidence?: AdConfidence;
}

/**
 * Monthly Klaviyo email section. `status` follows the other monthly sections
 * (ready | needs_source | no_data) — needs_source when the brand has no Klaviyo
 * rows, so the report never renders a fabricated €0 email block.
 */
export interface MonthlyEmailSection {
  status: 'ready' | 'needs_source' | 'no_data';
  note?: string;
  revenue?: number;
  orders?: number;
  shareOfStore?: number | null;
  splits?: { flow: { revenue: number; orders: number }; campaign: { revenue: number; orders: number } };
  rows?: { source: 'flow' | 'campaign'; id: string; name: string | null; revenue: number; orders: number }[];
  label?: string;
  honestyBox?: string;
}

/**
 * Klaviyo email revenue — its OWN channel. NEVER added to store or ad revenue:
 * Klaviyo is last-touch within its own windows and overlaps them. `shareOfStore`
 * is a ratio of two measured numbers, not an additive split. Null block = no data
 * (renders "—", never 0).
 */
export interface EmailRevenueBlock {
  revenue: number;
  orders: number;
  shareOfStore: number | null;
  topSources: { source: 'flow' | 'campaign'; id: string; name: string | null; revenue: number; orders: number }[];
  label: string;
  honestyBox: string;
}

export interface WeeklyMarketAlert {
  type: 'new_ads' | 'variant_spike' | 'new_format';
  severity: 'info' | 'warn';
  message: string;
  pageName: string | null;
}

export interface WeeklyReportData {
  reportType: 'weekly';
  narrative?: ReportNarrativePayload | null;
  llm?: { enabled: boolean; provider: string };
  brand: { name: string; slug: string; baseCurrency: string; timezone: string };
  currency: string;
  week: { label: string; start: string; end: string };
  // Selectable complete weeks (Monday keys), latest first — first entry is the
  // default the server builds when no ?week= is passed.
  availableWeeks?: ReportWindowOption[];
  comparison: {
    previous: { start: string; end: string };
    lastYear: { start: string; end: string } | null;
  };
  kpis: {
    totalRevenue: WeeklyKpi;
    adSpend: WeeklyKpi;
    blendedRoas: WeeklyKpi;
    orders: WeeklyKpi;
    aov: WeeklyKpi;
  };
  dailySeries: WeeklyDay[];
  spendByPlatform: ReportPlatformSpend[];
  spendComplete: boolean;
  campaignMovers: WeeklyCampaignMover[];
  actions: WeeklyAction[];
  // Competitor movement for the brand's niche (Ads Library Phase 5) — Proxy
  // signals from the public Ad Library corpus, never blended into performance.
  // Optional so pre-migration cached payloads stay renderable.
  marketAlerts?: WeeklyMarketAlert[];
  // Klaviyo email revenue (GO-1.1). Optional so pre-migration cached payloads render.
  email?: EmailRevenueBlock | null;
  freshness?: { upToDate: boolean; lastSynced: string | null; staleDays: number; windowEnd: string };
  branding: ReportBranding;
  content?: ReportContent | null;
  shared?: boolean;
}

// ── Creative performance report (ad_creative_daily grain) ───────────────────
// One block per ad platform that has creative rows in the window (Meta today,
// TikTok when its sync lands); platforms without rows are absent, never €0.
export interface CreativeRankings {
  quality: string | null; // Meta relevance diagnostics, e.g. 'above_average'
  engagement: string | null;
  conversion: string | null;
  belowAverage: boolean; // any of the three is below average → warning badge
}

export interface CreativeRow {
  id: string;
  name: string;
  mediaType: string | null; // image | video | null (unknown)
  spend: number;
  spendShare: number | null; // 0–1, of platform spend
  revenue: number;
  roas: number | null;
  purchases: number;
  cpa: number | null;
  ctr: number | null; // %
  thumbstop: number | null; // video_3s ÷ impressions %, null for image creatives
  hold: number | null; // thruplays ÷ video_3s %, null for image creatives
  addToCarts: number;
  rankings: CreativeRankings;
  prevRoas: number | null;
  roasDelta: number | null; // absolute × vs the comparison window
  spendDelta: number | null; // % vs the comparison window
}

export interface CreativeFatigueRow {
  id: string;
  name: string;
  mediaType: string | null;
  spend: number;
  roas: number | null;
  prevRoas: number | null;
  ctr: number | null;
  prevCtr: number | null;
  reason: string; // which signal fell and by how much — rules-derived
}

export interface CreativeScaleRow {
  id: string;
  name: string;
  mediaType: string | null;
  spend: number;
  spendShare: number | null;
  roas: number | null;
  platformMedian: number | null; // the median ROAS the rule compared against
}

export interface CreativeMediaMixRow {
  mediaType: string; // image | video | unknown
  spend: number;
  share: number | null; // 0–1, of platform spend
  creatives: number;
}

export interface CreativePlatformBlock {
  platform: string;
  summary: { creatives: number; spend: number; revenue: number; roas: number | null };
  topCreatives: CreativeRow[];
  totalCreatives: number;
  fatigued: CreativeFatigueRow[];
  scaleCandidates: CreativeScaleRow[];
  mediaMix: CreativeMediaMixRow[];
}

export interface CreativeReportData {
  reportType: 'creatives';
  narrative?: ReportNarrativePayload | null;
  llm?: { enabled: boolean; provider: string };
  brand: { name: string; slug: string; baseCurrency: string; timezone: string };
  currency: string;
  period: { label: string; start: string; end: string };
  comparison: { label: string | null; start: string; end: string } | null;
  platforms: CreativePlatformBlock[];
  freshness?: { upToDate: boolean; lastSynced: string | null; staleDays: number; windowEnd: string };
  branding: ReportBranding;
  content?: ReportContent | null;
  shared?: boolean;
}

// ── Ads audit report (platform-by-platform campaign audit) ──────────────────
// One block per ad platform with campaign rows in the window; a platformFilter
// narrows the build server-side (e.g. a Meta-only audit for a client email).
export type AdsAuditPlatformFilter = 'meta' | 'google' | 'tiktok';

/** ROAS KPI — ratio metrics compare by absolute × delta, not %. */
export interface AdsAuditRoasKpi {
  value: number | null;
  previous: number | null;
  deltaAbs: number | null;
}

export interface AdsAuditMover {
  campaignId: string;
  name: string;
  spend: number;
  prevSpend: number | null;
  spendDeltaPct: number | null;
  roas: number | null;
  prevRoas: number | null;
  verdict: AdVerdict;
  confidence?: AdConfidence;
}

// ── Ads audit v2 additions (2026-07-10, ADDITIVE) ───────────────────────────
// Best / worst performer row — worst is ordered null-ROAS spenders first
// (wasting with zero attributed revenue). Metric fields are nullable so a
// platform that can't attribute (e.g. no purchase rows) renders '—', never 0.
export interface AdsAuditPerformerRow {
  campaignId: string;
  name: string;
  status: string;
  spend: number;
  conversionValue: number | null;
  roas: number | null;
  cpa: number | null;
  ctr: number | null; // %
  cpm: number | null;
  purchases: number | null;
  confidence?: AdConfidence;
}

export type AdsAuditSegmentAxis = 'audience' | 'age_gender' | 'country' | 'device' | 'placement';

export interface AdsAuditSegmentRow {
  key: string;
  label: string;
  spend: number;
  sharePct: number | null; // 0–100, of platform spend on this axis
  ctr: number | null; // %
  cpm: number | null;
  roas: number | null;
  purchases: number | null;
}

export interface AdsAuditSegmentAxisBlock {
  axis: AdsAuditSegmentAxis;
  rows: AdsAuditSegmentRow[]; // ≤10
}

/** Customer-segment breakdowns — axes may be [] when no breakdown synced. */
export interface AdsAuditSegments {
  axes: AdsAuditSegmentAxisBlock[];
}

// Creative winners / fatigued (meta + tiktok only — absent for google).
export interface AdsAuditCreativeRow {
  adId: string;
  name: string;
  thumbnailUrl: string | null;
  mediaType: string | null; // image | video | null (unknown)
  spend: number;
  roas: number | null;
  ctr: number | null; // %
  thumbstopPct: number | null; // video only
  holdPct: number | null; // video only
  cpa: number | null;
  belowAverage: boolean;
}

export interface AdsAuditCreatives {
  status: 'ok' | 'no_data';
  winners: AdsAuditCreativeRow[]; // ≤6
  fatigued: AdsAuditCreativeRow[]; // ≤6
}

export type AdsAuditIssueSeverity = 'critical' | 'warn' | 'info';

export interface AdsAuditCampaignIssue {
  severity: AdsAuditIssueSeverity;
  title: string;
  detail: string;
}

/** Per-campaign detail for the issues drawer (≤12 campaigns by spend). */
export interface AdsAuditCampaignDetail {
  campaignId: string;
  name: string;
  status: string;
  channelType: string | null;
  verdict: AdVerdict;
  confidence?: AdConfidence;
  kpis: {
    spend: number | null;
    prevSpend: number | null;
    roas: number | null;
    prevRoas: number | null;
    cpa: number | null;
    ctr: number | null;
    cpm: number | null;
    purchases: number | null;
  };
  series: { date: string; spend: number | null; roas: number | null }[];
  issues: AdsAuditCampaignIssue[];
}

export interface AdsAuditPlatformBlock {
  platform: string;
  kpis: {
    spend: AdAuditKpi;
    conversionValue: AdAuditKpi;
    roas: AdsAuditRoasKpi;
    purchases: AdAuditKpi;
    cpa: AdAuditKpi;
  };
  audit: AdAuditSection;
  movers: AdsAuditMover[];
  // v2 additive keys — ALL optional so cached pre-migration payloads render
  // (an absent key omits its section, never a zero-filled table).
  best?: AdsAuditPerformerRow[]; // ≤5
  worst?: AdsAuditPerformerRow[]; // ≤5, null-ROAS spenders first
  segments?: AdsAuditSegments | null;
  creatives?: AdsAuditCreatives | null; // meta/tiktok only
  campaignDetails?: AdsAuditCampaignDetail[]; // ≤12
}

export interface AdsAuditReportData {
  reportType: 'ads-audit';
  brand: { name: string; slug: string; baseCurrency: string; timezone: string };
  currency: string;
  period: { label: string; start: string; end: string };
  comparison: { label: string | null; start: string; end: string } | null;
  platformFilter: AdsAuditPlatformFilter | null;
  platforms: AdsAuditPlatformBlock[];
  hasData: boolean;
  freshness?: { upToDate: boolean; lastSynced: string | null; staleDays: number; windowEnd: string };
  branding: ReportBranding;
  content?: ReportContent | null;
  shared?: boolean;
}

// The report pages accept any report type; they branch on `reportType`.
export type AnyReportData = OverallPerformanceReportData | MonthlyReportData | WeeklyReportData | CreativeReportData | AdsAuditReportData;

export interface ReportFiltersInput {
  period: 'last7' | 'last30' | 'mtd' | 'custom';
  compare: 'previous' | 'last_year' | 'none';
  // Custom window (period === 'custom') — only sent when BOTH are set.
  from?: string; // YYYY-MM-DD
  to?: string; // YYYY-MM-DD
  // Fixed-window pickers — monthly / weekly reports.
  month?: string; // YYYY-MM
  week?: string; // YYYY-MM-DD (a Monday)
  // Ads-audit platform narrowing — absent = all platforms.
  platform?: AdsAuditPlatformFilter;
}

export const DEFAULT_BRANDING: ReportBranding = {
  agency_name: 'Roasdriven',
  accent: '#1f6f5c',
  footer_text: 'Powered by novasolution.ae',
};
