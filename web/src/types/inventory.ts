// Inventory Intelligence — per-brand product table (stock × ad spend).
// Mirrors the API shape returned by InventoryQuery::run (GET
// /api/brands/{slug}/inventory). All money is in the brand's native currency;
// revenue is Total sales + refunds (before returns), Online Store only; ROAS is
// blended (revenue ÷ ad spend, all connected platforms) at the product level.

export type InventoryStatus = 'ok' | 'alert' | 'pause';

// The suggested next action, derived server-side from stock + spend.
export type InventoryAction = 'out_of_stock' | 'low_stock' | 'no_spend' | 'ok';

export type InventoryPeriod = 'last7' | 'last30' | 'mtd' | 'custom';

// One size/colour variant: `t` is the variant title (e.g. a shoe size "38"),
// `q` its current stock. Negative q is real Shopify oversell — shown, not clamped.
export interface InventoryVariant {
  t: string;
  q: number;
}

// Shopify's five traffic types, verified against a full year of a real store: paid 3,117,263 ·
// direct 2,599,142 · unknown 757,967 · organic 457,105 · unattributed 7 (they sum exactly to the
// store total). `unattributed` is 0.0001% of traffic and never shows up in a 30-day sample —
// rare, but real, so it gets a column rather than being quietly dropped.
export type TrafficType = 'paid' | 'direct' | 'organic' | 'unknown' | 'unattributed';

export type SessionSplit = Record<TrafficType, number>;

export interface InventoryProduct {
  handle: string;
  title: string;
  variantCount: number;
  variants: InventoryVariant[];
  stock: number;
  // units/unitsPrev/revenue are null when the window has NO commerce rows
  // synced at all (data missing) — distinct from 0 (covered window, no sales).
  units: number | null;
  unitsPrev: number | null;
  // Percent change in units vs the prior equal-length window. null when the
  // prior window had no sales (a genuine "new"/no-baseline case, not 0%) OR
  // when commerce data is missing for the window.
  deltaPct: number | null;
  // spend/ads are null when the window has NO ad-product rows synced (data
  // missing) — distinct from 0 (covered window, product genuinely spent nothing).
  spend: number | null;
  revenue: number | null;
  // null when the product had no attributed ad spend (ROAS is undefined, not 0)
  // or when spend data is missing for the window.
  roas: number | null;
  ads: number | null;
  // Sessions that LANDED on this product's page (Bosco item B). null when the window isn't
  // fully reconciled — render '—', never 0. A covered window with no landings is a real 0.
  sessions?: number | null;
  sessionsByType?: SessionSplit | null;
  status: InventoryStatus;
  action: InventoryAction;
}

export interface InventorySummary {
  products: number;
  pause: number;
  alert: number;
  ok: number;
  netStock: number;
  // null when no commerce rows are synced for the window — render '—', not 0.
  units: number | null;
  unitsPrev: number | null;
  // Total AD spend for the brand = attributed + unattributed, across every platform in
  // ad_product_daily (meta + google + tiktok). Was called `metaSpend`, which was never true:
  // the query has always summed all platforms, so the old name mislabelled the number rather
  // than describing it. `spendPlatforms` says which ones are actually in here.
  // The blended ROAS below uses this (not attributedSpend) so the headline isn't flattered.
  // null when no ad-product rows are synced for the window — render '—', not €0.
  adSpend: number | null;
  attributedSpend: number | null;
  revenue: number | null;
  roas: number | null;
}

// Ad spend not tied to a single product (dynamic / Advantage+ catalog, home
// and collection ads). Shown as a banner, never split across the product rows.
export interface InventoryUnattributed {
  collection: number;
  other: number;
  total: number;
}

// The ad platforms actually contributing spend to this window, biggest first — e.g.
// ['meta', 'google']. Empty = no ad rows at all. The UI names these instead of assuming Meta.
export type SpendPlatform = 'meta' | 'google' | 'tiktok';

// How far each dataset actually reaches. `catalog` is an ISO timestamp (same
// meaning as the legacy top-level syncedAt); `commerce`/`adSpend` are Y-m-d —
// the latest day with synced rows. null = never synced.
export interface InventoryDataThrough {
  catalog: string | null;
  commerce: string | null;
  adSpend: string | null;
  sessions?: string | null;
}

// Sessions by traffic type over the window (Bosco item B).
//
// `complete` is a FAIL-CLOSED gate: unless every day in the window has a row that reconciled
// against Shopify's own store total, it's false, every per-product figure is null, and the UI
// renders '—'. Summing only the days that happen to exist would under-report every product and
// then sort the table by that wrong number.
export interface InventorySessions {
  complete: boolean;
  windowDays: number;
  completeDays: number;
  through: string | null;   // Y-m-d, latest reconciled day (unbounded, not window-limited)
  byType: SessionSplit | null;      // store-level split — the strip Bosco screenshotted
  total: number | null;
  // Sessions that landed anywhere OTHER than a product page: home, collection pages, /pages,
  // search, checkout. Roughly half of a real store's traffic, so it is shown rather than
  // folded silently into the totals.
  storeWide: SessionSplit | null;
  productTotal: number | null;
}

export interface InventoryResponse {
  brand: { id: number; name: string; slug: string; currency: string };
  period: InventoryPeriod;
  from: string; // Y-m-d, brand timezone
  to: string;   // Y-m-d, brand timezone (window ends yesterday)
  currency: string;
  syncedAt: string | null; // ISO — when the catalog (stock) was last snapshotted
  summary: InventorySummary;
  // null when no ad-product rows are synced for the window (unknown, not €0).
  unattributed: InventoryUnattributed | null;
  products: InventoryProduct[];
  // -- Additive fields (backend rollout in parallel; all optional) ---------
  dataThrough?: InventoryDataThrough;
  // Ad-account currency ≠ store currency — spend shown in ad-account currency.
  spendCurrencyMismatch?: boolean;
  // Archived/draft products excluded server-side from the table.
  excludedInactive?: number;
  // Which ad platforms are in the spend/ROAS figures on this page.
  spendPlatforms?: SpendPlatform[];
  // Sessions by traffic type (Bosco item B). Optional: brands whose backfill hasn't run yet
  // simply don't have it.
  sessions?: InventorySessions;
}

// A collection = every product sharing a model name (the first word of the
// title, e.g. all "Nayah …" products). Built client-side for the "By collection"
// view (Bosco, 2026-07-03); metrics are the sum of the member products, ROAS is
// blended over the group, status/action derive from the aggregate stock.
export interface CollectionGroup {
  key: string;          // lowercased model, the group id
  name: string;         // display model (first word of a member title)
  productCount: number; // "Colores" — how many products roll up here
  stock: number;
  // Sums stay null when every member is null (dataset unsynced for the window)
  // — a group of unknowns is unknown, not 0.
  units: number | null;
  unitsPrev: number | null;
  deltaPct: number | null;
  spend: number | null;
  revenue: number | null;
  roas: number | null;
  ads: number | null;
  // Sum of the member products' landings. null when the window isn't reconciled.
  sessions: number | null;
  sessionsByType: SessionSplit | null;
  status: InventoryStatus;
  action: InventoryAction;
  products: InventoryProduct[]; // members, shown when the row is expanded
}
