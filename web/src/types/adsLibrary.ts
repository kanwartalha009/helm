// Ads Library — payload types (docs/feature-specs/ads-library.md Phase 1).
// Winners = "what's actually working across our brands" by REAL ROAS, evidence-
// gated, badged Verified — our data.

export type AdsLibraryWindow = 'last30' | 'last90';
export type AdsLibraryPlatform = 'meta' | 'google' | 'tiktok';
export type WinnerSort = 'roas' | 'spend' | 'ctr';

export interface WinnerRow {
  adId: string;
  platform: AdsLibraryPlatform;
  brand: { name: string; slug: string };
  niche: string | null;
  name: string;
  bodyText: string | null;
  thumbnailUrl: string | null;
  mediaType: 'image' | 'video';
  currency: string;
  spend: number;        // native display
  spendUsd: number;     // ranking basis
  roas: number | null;
  cpa: number | null;   // native
  ctr: number | null;   // %
  purchases: number;
  thumbstop: number | null; // %, video only
  hold: number | null;      // %, video only
  confidence: 'solid' | 'early';
}

export interface WinnersResponse {
  window: AdsLibraryWindow;
  periodStart: string;
  periodEnd: string;
  asOf: string | null;
  total: number;   // how many cleared the evidence floor (before the 100 cap)
  cap: number;
  rows: WinnerRow[];
  niches: string[];
}

export interface WinnersFilters {
  window: AdsLibraryWindow;
  niche?: string;       // '__unassigned' for brands with no niche
  platform?: AdsLibraryPlatform;
  media_type?: 'image' | 'video';
  brand?: string;       // slug
  sort?: WinnerSort;
  search?: string;
}

// ── Market library (Phase 3) — Proxy signals only (no spend/ROAS on EU ads). ──
export type MarketSort = 'signal' | 'rising' | 'newest' | 'longevity' | 'reach';

export interface MarketRow {
  adArchiveId: string;
  pageId: string;
  pageName: string | null;
  niche: string | null;
  permalink: string | null;
  mediaType: string | null;
  deliveryStart: string | null;
  deliveryStop: string | null;
  isActive: boolean;
  longevityDays: number | null;
  euReach: number | null;
  signalScore: number | null;
  variants: number;
  bodies: string[];
  linkTitles: string[];
  languages: string[];
  platforms: string[];
  targetAges: unknown;
  targetGender: string | null;
  targetLocations: unknown;
  reachBreakdown: unknown;
  beneficiaryPayers: unknown;
}

export interface MarketResponse {
  rows: MarketRow[];
  total: number;
  cap: number;
  sort: MarketSort;
  scoreWeights: { longevity_weight?: number; reach_weight?: number; variants_weight?: number };
  coverageNote: string;
}

export interface MarketFilters {
  q?: string;
  niche?: string;
  media_type?: 'image' | 'video';
  active?: '1' | '0' | 'all';
  page_id?: string;
  sort?: MarketSort;
  limit?: number;
}

export interface MarketAlert {
  pageId: string;
  pageName: string | null;
  niche: string | null;
  type: 'new_ads' | 'variant_spike' | 'new_format';
  severity: 'info' | 'warn';
  message: string;
}

export interface TrackedPage {
  id: number;
  pageId: string;
  pageName: string | null;
  niche: string | null;
  countryDefault: string | null;
  status: string;
  lastRefreshedAt: string | null;
  activeAds: number;
  newThisWeek: number;
}

export interface ResolveResult {
  pageId: string | null;
  candidates: { pageId: string; pageName: string | null }[];
  source: string;
}

// ── Boards + briefs (Phase 4) ──
export interface BoardSummary {
  id: number;
  name: string;
  brandId: number | null;
  niche: string | null;
  itemCount: number;
}

export interface BoardItem {
  id: number;
  source: 'internal' | 'market';
  refId: string;
  note: string | null;
  tags: string[];
  name: string;
  thumbnail?: string | null;
  permalink?: string | null;
  bodyText?: string | null;
  mediaType?: string | null;
  badge: 'Verified' | 'Proxy';
}

export interface TagBenchmark {
  tag: string;
  count: number;
  medianRoas: number | null;
  medianCtr: number | null;
  enough: boolean;
}

export interface BoardDetail {
  board: { id: number; name: string; brandId: number | null; niche: string | null };
  items: BoardItem[];
  benchmarks: TagBenchmark[];
}

export interface Brief {
  id: number;
  boardId: number | null;
  brandId: number | null;
  title: string;
  status: 'draft' | 'ready' | 'shipped';
  blocks: Record<string, unknown>;
}
