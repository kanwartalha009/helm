// Inventory Intelligence — per-brand product table (stock × Meta spend).
// Mirrors the API shape returned by InventoryQuery::run (GET
// /api/brands/{slug}/inventory). All money is in the brand's native currency;
// revenue is Total sales + refunds (before returns), Online Store only; ROAS is
// blended (revenue ÷ Meta spend) at the product level.

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
  // null when the product had no attributed Meta spend (ROAS is undefined, not 0)
  // or when spend data is missing for the window.
  roas: number | null;
  ads: number | null;
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
  // Total Meta spend for the brand = attributed + unattributed. The blended
  // ROAS below uses this (not attributedSpend) so the headline isn't flattered.
  // null when no ad-product rows are synced for the window — render '—', not €0.
  metaSpend: number | null;
  attributedSpend: number | null;
  revenue: number | null;
  roas: number | null;
}

// Meta spend not tied to a single product (dynamic / Advantage+ catalog, home
// and collection ads). Shown as a banner, never split across the product rows.
export interface InventoryUnattributed {
  collection: number;
  other: number;
  total: number;
}

// How far each dataset actually reaches. `catalog` is an ISO timestamp (same
// meaning as the legacy top-level syncedAt); `commerce`/`adSpend` are Y-m-d —
// the latest day with synced rows. null = never synced.
export interface InventoryDataThrough {
  catalog: string | null;
  commerce: string | null;
  adSpend: string | null;
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
  status: InventoryStatus;
  action: InventoryAction;
  products: InventoryProduct[]; // members, shown when the row is expanded
}
