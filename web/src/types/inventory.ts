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
  units: number;
  unitsPrev: number;
  // Percent change in units vs the prior equal-length window. null when the
  // prior window had no sales (a genuine "new"/no-baseline case, not 0%).
  deltaPct: number | null;
  spend: number;
  revenue: number;
  // null when the product had no attributed Meta spend (ROAS is undefined, not 0).
  roas: number | null;
  ads: number;
  status: InventoryStatus;
  action: InventoryAction;
}

export interface InventorySummary {
  products: number;
  pause: number;
  alert: number;
  ok: number;
  netStock: number;
  units: number;
  unitsPrev: number;
  // Total Meta spend for the brand = attributed + unattributed. The blended
  // ROAS below uses this (not attributedSpend) so the headline isn't flattered.
  metaSpend: number;
  attributedSpend: number;
  revenue: number;
  roas: number | null;
}

// Meta spend not tied to a single product (dynamic / Advantage+ catalog, home
// and collection ads). Shown as a banner, never split across the product rows.
export interface InventoryUnattributed {
  collection: number;
  other: number;
  total: number;
}

export interface InventoryResponse {
  brand: { id: number; name: string; slug: string; currency: string };
  period: InventoryPeriod;
  from: string; // Y-m-d, brand timezone
  to: string;   // Y-m-d, brand timezone (window ends yesterday)
  currency: string;
  summary: InventorySummary;
  unattributed: InventoryUnattributed;
  products: InventoryProduct[];
}
