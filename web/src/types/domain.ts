// Domain types — mirror the API contract from spec §11 and the
// daily_metrics row from spec §7.1. These are the shapes the frontend
// expects from the Laravel API once it exists. Mocks use these too.

export type Role = 'master_admin' | 'manager' | 'team_member' | 'brand_user';
export type Platform = 'shopify' | 'meta' | 'google' | 'tiktok';
export type BrandStatus = 'active' | 'paused' | 'archived';
export type ConnectionStatus = 'active' | 'paused' | 'errored';
export type SyncStatus = 'queued' | 'running' | 'success' | 'failed';
export type TicketStatus =
  | 'open'
  | 'triaged'
  | 'in_progress'
  | 'blocked'
  | 'done'
  | 'wont_do';
export type TicketPriority = 'low' | 'med' | 'high' | 'urgent';
export type TicketCategory = 'bug' | 'change' | 'question' | 'urgent';

export interface User {
  id: number;
  name: string;
  email: string;
  role: Role;
  status: 'active' | 'invited' | 'disabled';
  displayInitials: string;
  timezone: string;
  mfaEnabled: boolean;
  accessibleBrandIds: number[];
  notificationPrefs: {
    daily_sync_digest: boolean;
    connection_errored: boolean;
    ticket_assigned: boolean;
    weekly_summary: boolean;
  };
  avatarUrl: string | null;
  onboardingCompletedAt: string | null;
  onboardingComplete: boolean;
  lastLoginAt: string | null;
}

export interface Brand {
  id: number;
  name: string;
  slug: string;
  timezone: string;
  baseCurrency: string;
  groupTag: string | null;
  status: BrandStatus;
  initials: string;
  region: string;
  shopDomain?: string;
  // True when the brand has its own Shopify Partner app credentials stored.
  // Drives the OAuth flow UI — when false, the Client ID + Secret fields
  // appear; when true, just the shop domain field.
  hasShopifyApp?: boolean;
}

export interface PlatformConnection {
  id: number;
  brandId: number;
  platform: Platform;
  externalId: string;
  displayName: string | null;
  status: ConnectionStatus;
  lastSyncAt: string | null;
  lastError: string | null;
}

export interface DailyMetric {
  brandId: number;
  platform: Platform;
  date: string;                 // ISO date in brand's timezone
  revenue: number | null;
  revenueNet: number | null;
  orders: number | null;
  spend: number | null;
  impressions: number | null;
  clicks: number | null;
  conversions: number | null;
  conversionValue: number | null;
  currency: string;
  fxRateToUsd: number;
  isComplete: boolean;
}

// A single row in the dashboard table — two-day comparison plus L7d block.
// `brand.platforms` lists the platforms with an active PlatformConnection on
// the brand. The dashboard cell logic uses it to distinguish "no connection
// for this platform" (N/A) from "connected but no data yet" (warning tag).
export interface PlatformHealth {
  status: string;
  lastSyncAt: string | null;
  hasError: boolean;
}

export interface DashboardRowBrand extends Brand {
  platforms?: Platform[];
  /**
   * Per-platform health derived from platform_connections rows. Lets the
   * dashboard distinguish three null-revenue cases: not connected (N/A),
   * connected-but-errored (Shopify failed), connected-and-healthy ($0).
   */
  platformHealth?: Partial<Record<Platform, PlatformHealth>>;
}

export interface DashboardRow {
  brand: DashboardRowBrand;
  yesterday: {
    // `revenue` = gross (before refunds). `revenueNet` = gross − refunds.
    // The Gross/Net toggle on the dashboard swaps between them.
    revenue: number | null;
    revenueNet: number | null;
    refundsAmount: number | null;
    metaSpend: number | null;
    googleSpend: number | null;
    tiktokSpend: number | null;
    totalSpend: number | null;
    roas: number | null;
    isComplete: boolean;
  };
  dayBefore: {
    revenue: number | null;
    revenueNet: number | null;
    refundsAmount: number | null;
    metaSpend: number | null;
    googleSpend: number | null;
    tiktokSpend: number | null;
    totalSpend: number | null;
    roas: number | null;
  };
  last7d: {
    // `revenue` and `revenuePrior7d` are NET sums (gross − refunds).
    // `revenueGross*` are the gross variants the toggle reaches for.
    revenue: number | null;
    revenueGross: number | null;
    revenuePrior7d: number | null;
    revenueGrossPrior7d: number | null;
    isComplete: boolean;
  };
}

export interface SyncLog {
  id: number;
  brand: Pick<Brand, 'id' | 'name' | 'initials'>;
  platform: Platform;
  targetDate: string;
  status: SyncStatus;
  durationMs: number | null;
  recordsProcessed: number | null;
  errorMessage: string | null;
  completedAt: string | null;
}

export interface AdRow {
  level: 'campaign' | 'adset' | 'ad';
  id: string;
  name: string;
  parentId?: string;
  spend: number;
  revenue: number;
  roas: number;
  ctr: number;
  cpc: number;
  frequency: number;
  flag?: 'scale' | 'underperformer' | null;
}

export interface ProductRow {
  productId: string;
  sku: string | null;
  title: string;
  unitsSold: number;
  revenue: number;
  refundUnits: number;
  refundAmount: number;
  refundRate: number;
}

export interface AuditFinding {
  id: number;
  brandId: number;
  auditType: 'page_speed' | 'broken_event' | 'checkout_drop' | 'other';
  severity: 'info' | 'warn' | 'critical';
  title: string;
  description: string;
  detectedAt: string;
  resolvedAt: string | null;
}

export interface Ticket {
  id: number;
  brand: Pick<Brand, 'id' | 'name'>;
  raisedBy: Pick<User, 'id' | 'name'>;
  assignedTo: Pick<User, 'id' | 'name'> | null;
  title: string;
  category: TicketCategory;
  status: TicketStatus;
  priority: TicketPriority;
  externalTaskUrl: string | null;
  createdAt: string;
}

export interface AuditLogEntry {
  id: number;
  actor: Pick<User, 'id' | 'name'>;
  action: string;
  target: string;
  ip: string;
  createdAt: string;
}

export type DateRangePreset =
  | 'yesterday'
  | 'last_7'
  | 'last_week'
  | 'mtd'
  | 'last_month'
  | 'qtd'
  | 'last_quarter'
  | 'last_30'
  | 'last_90'
  | 'ytd'
  | 'custom';

export interface PlatformCredential {
  id: number;
  platform: 'shopify' | 'meta' | 'google' | 'tiktok';
  key: string;
  label: string | null;
  maskedValue: string;            // server only ever returns masked
  status: 'active' | 'rotated' | 'revoked';
  lastUsedAt: string | null;
  expiresAt: string | null;
  createdAt: string;
  createdBy: { id: number; name: string } | null;
}

export interface PlatformCredentialSchemaItem {
  key: string;
  label: string;
  sensitive: boolean;
}

export type PlatformCredentialSchema = {
  shopify: PlatformCredentialSchemaItem[];
  meta: PlatformCredentialSchemaItem[];
  google: PlatformCredentialSchemaItem[];
  tiktok: PlatformCredentialSchemaItem[];
};

export type CompareBaseline =
  | 'prior_period'
  | 'same_period_last_year'
  | 'same_period_last_month'
  | 'none';
