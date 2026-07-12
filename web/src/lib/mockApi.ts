// Mock data layer. The Laravel API doesn't exist yet, so hooks call functions
// here. Every function returns a Promise so swapping for axios calls later is
// trivial — same signatures, same shapes.

import type {
  AdRow,
  AuditFinding,
  AuditLogEntry,
  Brand,
  DashboardRow,
  PlatformConnection,
  PlatformCredential,
  PlatformCredentialSchema,
  ProductRow,
  SyncLog,
  Ticket,
  User,
} from '@/types/domain';

const delay = (ms = 120) => new Promise<void>((r) => setTimeout(r, ms));

export const MOCK_USER: User = {
  id: 1,
  name: 'Kanwar',
  email: 'kanwartalha009@gmail.com',
  role: 'master_admin',
  status: 'active',
  displayInitials: 'K',
  timezone: 'UTC',
  mfaEnabled: false,
  accessibleBrandIds: [],
  notificationPrefs: {
    daily_sync_digest: true,
    connection_errored: true,
    ticket_assigned: false,
    weekly_summary: false,
  },
  avatarUrl: null,
  onboardingCompletedAt: '2026-02-12T10:00:00Z',
  onboardingComplete: true,
  lastLoginAt: '2026-05-16T09:14:00Z',
};

const BRANDS: Brand[] = [
  { id: 1, name: 'Meller', slug: 'meller', timezone: 'Europe/Madrid', baseCurrency: 'EUR', groupTag: 'EU', status: 'active', initials: 'ML', region: 'Spain', shopDomain: 'meller.myshopify.com' },
  { id: 2, name: 'Ayla & Co', slug: 'ayla-co', timezone: 'Asia/Dubai', baseCurrency: 'AED', groupTag: 'GCC', status: 'active', initials: 'AY', region: 'UAE', shopDomain: 'ayla-co.myshopify.com' },
  { id: 3, name: 'Nova Threads', slug: 'nova-threads', timezone: 'America/New_York', baseCurrency: 'USD', groupTag: 'US', status: 'active', initials: 'NT', region: 'US', shopDomain: 'nova-threads.myshopify.com' },
  { id: 4, name: 'Kenza Beauty', slug: 'kenza-beauty', timezone: 'Asia/Riyadh', baseCurrency: 'SAR', groupTag: 'GCC', status: 'active', initials: 'KE', region: 'Saudi Arabia', shopDomain: 'kenza-beauty.myshopify.com' },
  { id: 5, name: 'Olea Skincare', slug: 'olea-skincare', timezone: 'Europe/Rome', baseCurrency: 'EUR', groupTag: 'EU', status: 'active', initials: 'OL', region: 'Italy', shopDomain: 'olea.myshopify.com' },
  { id: 6, name: 'Ren Coffee', slug: 'ren-coffee', timezone: 'Europe/Stockholm', baseCurrency: 'SEK', groupTag: 'Nordics', status: 'active', initials: 'RN', region: 'Sweden', shopDomain: 'ren-coffee.myshopify.com' },
];

// Mock rows assume 0% refund rate, so gross == net everywhere. The real
// `/api/dashboard` returns proper gross + net + refunds — these mocks are
// only used by storybook/preview routes, never by the live dashboard.
const DASHBOARD_ROWS: DashboardRow[] = [
  {
    brand: BRANDS[0],
    yesterday: { revenue: 8420, revenueNet: 8420, netSales: 8420, totalSales: 10104, refundsAmount: 0, metaSpend: 1520, googleSpend: 680, tiktokSpend: 330, totalSpend: 2530, roas: 3.33, roasTotal: 3.99, isComplete: true },
    dayBefore: { revenue: 7489, revenueNet: 7489, netSales: 7489, totalSales: 8987, refundsAmount: 0, metaSpend: 1460, googleSpend: 680, tiktokSpend: 278, totalSpend: 2418, roas: 3.10, roasTotal: 3.72, isComplete: true },
    rolling:   { windowDays: 7, revenue: 52860, revenueGross: 52860, netSales: 52860, totalSales: 63432, revenuePrior: 47012, revenueGrossPrior: 47012, netSalesPrior: 47012, totalSalesPrior: 56414, isComplete: true },
  },
  {
    brand: BRANDS[1],
    yesterday: { revenue: 5210, revenueNet: 5210, netSales: 5210, totalSales: 6252, refundsAmount: 0, metaSpend: 890, googleSpend: 380, tiktokSpend: 140, totalSpend: 1410, roas: 3.70, roasTotal: 4.43, isComplete: true },
    dayBefore: { revenue: 5376, revenueNet: 5376, netSales: 5376, totalSales: 6451, refundsAmount: 0, metaSpend: 901, googleSpend: 383, tiktokSpend: 140, totalSpend: 1424, roas: 3.78, roasTotal: 4.53, isComplete: true },
    rolling:   { windowDays: 7, revenue: 36040, revenueGross: 36040, netSales: 36040, totalSales: 43248, revenuePrior: 37196, revenueGrossPrior: 37196, netSalesPrior: 37196, totalSalesPrior: 44635, isComplete: true },
  },
  {
    brand: BRANDS[2],
    yesterday: { revenue: null, revenueNet: null, netSales: null, totalSales: null, refundsAmount: null, metaSpend: 890, googleSpend: 410, tiktokSpend: null, totalSpend: 1300, roas: null, roasTotal: null, isComplete: false },
    dayBefore: { revenue: null, revenueNet: null, netSales: null, totalSales: null, refundsAmount: null, metaSpend: 842, googleSpend: 398, tiktokSpend: null, totalSpend: 1240, roas: null, roasTotal: null, isComplete: false },
    rolling:   { windowDays: 7, revenue: null, revenueGross: null, netSales: null, totalSales: null, revenuePrior: null, revenueGrossPrior: null, netSalesPrior: null, totalSalesPrior: null, isComplete: false },
  },
  {
    brand: BRANDS[3],
    yesterday: { revenue: 4050, revenueNet: 4050, netSales: 4050, totalSales: 4860, refundsAmount: 0, metaSpend: 770, googleSpend: 280, tiktokSpend: 360, totalSpend: 1410, roas: 2.87, roasTotal: 3.45, isComplete: true },
    dayBefore: { revenue: 3794, revenueNet: 3794, netSales: 3794, totalSales: 4553, refundsAmount: 0, metaSpend: 691, googleSpend: 280, tiktokSpend: 295, totalSpend: 1266, roas: 3.00, roasTotal: 3.60, isComplete: true },
    rolling:   { windowDays: 7, revenue: 28400, revenueGross: 28400, netSales: 28400, totalSales: 34080, revenuePrior: 26592, revenueGrossPrior: 26592, netSalesPrior: 26592, totalSalesPrior: 31910, isComplete: true },
  },
  {
    brand: BRANDS[4],
    yesterday: { revenue: 2810, revenueNet: 2810, netSales: 2810, totalSales: 3372, refundsAmount: 0, metaSpend: 590, googleSpend: 210, tiktokSpend: null, totalSpend: 800, roas: 3.51, roasTotal: 4.22, isComplete: true },
    dayBefore: { revenue: 2850, revenueNet: 2850, netSales: 2850, totalSales: 3420, refundsAmount: 0, metaSpend: 603, googleSpend: 211, tiktokSpend: null, totalSpend: 814, roas: 3.50, roasTotal: 4.20, isComplete: true },
    rolling:   { windowDays: 7, revenue: 19720, revenueGross: 19720, netSales: 19720, totalSales: 23664, revenuePrior: 19996, revenueGrossPrior: 19996, netSalesPrior: 19996, totalSalesPrior: 23995, isComplete: true },
  },
  {
    brand: BRANDS[5],
    yesterday: { revenue: 2030, revenueNet: 2030, netSales: 2030, totalSales: 2436, refundsAmount: 0, metaSpend: 410, googleSpend: 160, tiktokSpend: null, totalSpend: 570, roas: 3.56, roasTotal: 4.27, isComplete: true },
    dayBefore: { revenue: 1948, revenueNet: 1948, netSales: 1948, totalSales: 2338, refundsAmount: 0, metaSpend: 402, googleSpend: 159, tiktokSpend: null, totalSpend: 562, roas: 3.47, roasTotal: 4.16, isComplete: true },
    rolling:   { windowDays: 7, revenue: 14210, revenueGross: 14210, netSales: 14210, totalSales: 17052, revenuePrior: 13637, revenueGrossPrior: 13637, netSalesPrior: 13637, totalSalesPrior: 16364, isComplete: true },
  },
];

export async function getCurrentUser(): Promise<User> {
  await delay();
  return MOCK_USER;
}

export async function getDashboard(): Promise<DashboardRow[]> {
  await delay();
  return DASHBOARD_ROWS;
}

export async function getBrands(): Promise<Brand[]> {
  await delay();
  return BRANDS;
}

export async function getBrand(slug: string): Promise<Brand | undefined> {
  await delay();
  return BRANDS.find((b) => b.slug === slug);
}

export async function getConnections(brandId: number): Promise<PlatformConnection[]> {
  await delay();
  return [
    { id: 101, brandId, platform: 'shopify', externalId: 'meller.myshopify.com', displayName: 'Meller', status: 'active', lastSyncAt: '2026-05-16T13:04:00Z', lastError: null },
    { id: 102, brandId, platform: 'meta', externalId: 'act_2847291038', displayName: 'Meller Spain', status: 'active', lastSyncAt: '2026-05-16T13:04:00Z', lastError: null },
    { id: 103, brandId, platform: 'google', externalId: '478-291-3847', displayName: 'Meller', status: 'active', lastSyncAt: '2026-05-16T13:04:00Z', lastError: null },
    { id: 104, brandId, platform: 'tiktok', externalId: '7284937214', displayName: 'Meller', status: 'active', lastSyncAt: '2026-05-16T13:04:00Z', lastError: null },
  ];
}

export async function getSyncLogs(): Promise<SyncLog[]> {
  await delay();
  return [
    { id: 1, brand: { id: 3, name: 'Nova Threads', initials: 'NT' }, platform: 'shopify', targetDate: '2026-05-15', status: 'failed', durationMs: null, recordsProcessed: null, errorMessage: '401 access_token revoked', completedAt: '2026-05-16T12:52:00Z' },
    { id: 2, brand: { id: 5, name: 'Olea Skincare', initials: 'OL' }, platform: 'tiktok', targetDate: '2026-05-15', status: 'failed', durationMs: null, recordsProcessed: null, errorMessage: '40100 advertiser_not_visible', completedAt: '2026-05-16T12:30:00Z' },
    { id: 3, brand: { id: 1, name: 'Meller', initials: 'ML' }, platform: 'shopify', targetDate: '2026-05-15', status: 'success', durationMs: 4200, recordsProcessed: 812, errorMessage: null, completedAt: '2026-05-16T13:04:00Z' },
    { id: 4, brand: { id: 1, name: 'Meller', initials: 'ML' }, platform: 'meta', targetDate: '2026-05-15', status: 'success', durationMs: 2100, recordsProcessed: 1, errorMessage: null, completedAt: '2026-05-16T13:04:00Z' },
  ];
}

export async function getAdRows(): Promise<AdRow[]> {
  await delay();
  return [
    { level: 'campaign', id: 'c1', name: 'Summer Launch — Prospecting', spend: 5820, revenue: 17140, roas: 2.94, ctr: 1.82, cpc: 0.47, frequency: 1.4, flag: 'scale' },
    { level: 'campaign', id: 'c2', name: 'Retargeting — ATC 7d', spend: 2140, revenue: 8920, roas: 4.17, ctr: 2.41, cpc: 0.31, frequency: 3.2 },
    { level: 'campaign', id: 'c3', name: 'Brand awareness — broad', spend: 1420, revenue: 1180, roas: 0.83, ctr: 0.94, cpc: 0.62, frequency: 2.1, flag: 'underperformer' },
    { level: 'campaign', id: 'c4', name: 'Lookalikes — 1% LAL', spend: 830, revenue: 1400, roas: 1.69, ctr: 1.21, cpc: 0.51, frequency: 1.8 },
  ];
}

export async function getProductRows(): Promise<ProductRow[]> {
  await delay();
  return [
    { productId: 'p1', sku: 'MLR-BNK-BLK', title: 'Banks watch — Black', unitsSold: 214, revenue: 18290, refundUnits: 5, refundAmount: 420, refundRate: 2.3 },
    { productId: 'p2', sku: 'MLR-BNK-RGD', title: 'Banks watch — Rose gold', unitsSold: 182, revenue: 15470, refundUnits: 4, refundAmount: 340, refundRate: 2.2 },
    { productId: 'p3', sku: 'MLR-MKR-TRT', title: 'Maker sunglasses — Tortoise', unitsSold: 96, revenue: 5760, refundUnits: 11, refundAmount: 680, refundRate: 11.5 },
    { productId: 'p4', sku: 'MLR-NYR-CML', title: 'Nayar bag — Camel', unitsSold: 74, revenue: 4810, refundUnits: 2, refundAmount: 130, refundRate: 2.7 },
    { productId: 'p5', sku: 'MLR-CLD-SLV', title: 'Calder rings — Silver', unitsSold: 52, revenue: 2340, refundUnits: 1, refundAmount: 45, refundRate: 1.9 },
  ];
}

export async function getAuditFindings(): Promise<AuditFinding[]> {
  await delay();
  return [
    { id: 1, brandId: 1, auditType: 'broken_event', severity: 'warn', title: 'Meta Pixel — Purchase event missing on /thank-you', description: '', detectedAt: '2026-05-14', resolvedAt: null },
    { id: 2, brandId: 1, auditType: 'checkout_drop', severity: 'critical', title: 'Checkout step 2 drop-off increased to 38%', description: '', detectedAt: '2026-05-14', resolvedAt: null },
    { id: 3, brandId: 1, auditType: 'page_speed', severity: 'warn', title: 'LCP regression on /products on mobile (3.1s)', description: '', detectedAt: '2026-05-07', resolvedAt: null },
    { id: 4, brandId: 1, auditType: 'other', severity: 'info', title: 'Cart abandonment email opt-in below 12%', description: '', detectedAt: '2026-04-30', resolvedAt: null },
  ];
}

export async function getTickets(): Promise<Ticket[]> {
  await delay();
  return [
    { id: 1148, brand: { id: 1, name: 'Meller' }, raisedBy: { id: 10, name: 'Ana Martín' }, assignedTo: { id: 2, name: 'Jordan Reeves' }, title: 'Meta Pixel Purchase event firing twice on Meller', category: 'bug', status: 'in_progress', priority: 'urgent', externalTaskUrl: null, createdAt: '2026-05-16T08:14:00Z' },
    { id: 1147, brand: { id: 2, name: 'Ayla & Co' }, raisedBy: { id: 11, name: 'Rashid Khan' }, assignedTo: { id: 3, name: 'Sara Mahmoud' }, title: "Ayla & Co revenue numbers don't match Shopify admin", category: 'question', status: 'triaged', priority: 'med', externalTaskUrl: null, createdAt: '2026-05-16T05:10:00Z' },
    { id: 1146, brand: { id: 4, name: 'Kenza Beauty' }, raisedBy: { id: 12, name: 'Tariq' }, assignedTo: null, title: 'Can we add a new TikTok ad account to Kenza Beauty?', category: 'change', status: 'open', priority: 'low', externalTaskUrl: null, createdAt: '2026-05-15T18:00:00Z' },
    { id: 1145, brand: { id: 3, name: 'Nova Threads' }, raisedBy: { id: 1, name: 'Roasdriven system' }, assignedTo: { id: 1, name: 'Kanwar' }, title: 'Nova Threads Shopify connection keeps failing', category: 'bug', status: 'in_progress', priority: 'urgent', externalTaskUrl: null, createdAt: '2026-05-15T13:30:00Z' },
    { id: 1143, brand: { id: 0, name: 'All brands' }, raisedBy: { id: 4, name: 'Diego López' }, assignedTo: null, title: 'Add custom date range presets (Last 14 days, YTD)', category: 'change', status: 'open', priority: 'low', externalTaskUrl: null, createdAt: '2026-05-14T11:00:00Z' },
  ];
}

export async function getPlatformCredentials(): Promise<PlatformCredential[]> {
  await delay();
  return [
    { id: 1, platform: 'meta', key: 'system_user_token', label: 'Production BM', maskedValue: 'EAAg••••••••••••P3kZ', status: 'active', lastUsedAt: '2026-05-16T13:04:00Z', expiresAt: null, createdAt: '2026-02-12T10:00:00Z', createdBy: { id: 1, name: 'Kanwar' } },
    { id: 2, platform: 'google', key: 'refresh_token', label: 'MCC primary', maskedValue: '1//0e••••••••••••8w7v', status: 'active', lastUsedAt: '2026-05-16T13:04:00Z', expiresAt: null, createdAt: '2026-02-12T10:05:00Z', createdBy: { id: 1, name: 'Kanwar' } },
    { id: 3, platform: 'google', key: 'client_id', label: 'OAuth client', maskedValue: '4827••••••••••••.com', status: 'active', lastUsedAt: '2026-05-16T13:04:00Z', expiresAt: null, createdAt: '2026-02-12T10:05:00Z', createdBy: { id: 1, name: 'Kanwar' } },
    { id: 4, platform: 'google', key: 'client_secret', label: 'OAuth secret', maskedValue: 'GOCS••••••••••••aB42', status: 'active', lastUsedAt: '2026-05-16T13:04:00Z', expiresAt: null, createdAt: '2026-02-12T10:05:00Z', createdBy: { id: 1, name: 'Kanwar' } },
    { id: 5, platform: 'google', key: 'developer_token', label: 'Dev token', maskedValue: 'aXkP••••••••••••2X9Q', status: 'active', lastUsedAt: '2026-05-16T13:04:00Z', expiresAt: null, createdAt: '2026-02-12T10:05:00Z', createdBy: { id: 1, name: 'Kanwar' } },
    { id: 6, platform: 'google', key: 'login_customer_id', label: 'MCC customer ID', maskedValue: '478-291-3847', status: 'active', lastUsedAt: '2026-05-16T13:04:00Z', expiresAt: null, createdAt: '2026-02-12T10:05:00Z', createdBy: { id: 1, name: 'Kanwar' } },
    { id: 7, platform: 'tiktok', key: 'bc_token', label: 'Production BC', maskedValue: '7284••••••••••••Qa3M', status: 'active', lastUsedAt: '2026-05-16T13:04:00Z', expiresAt: null, createdAt: '2026-02-14T15:20:00Z', createdBy: { id: 1, name: 'Kanwar' } },
  ];
}

export async function getPlatformCredentialSchema(): Promise<PlatformCredentialSchema> {
  await delay();
  return {
    shopify: [
      { key: 'partner_app_key',    label: 'Partner app key',    sensitive: false },
      { key: 'partner_app_secret', label: 'Partner app secret', sensitive: true },
      { key: 'partner_app_scopes', label: 'OAuth scopes',       sensitive: false },
    ],
    meta: [
      { key: 'system_user_token', label: 'System User token', sensitive: true },
    ],
    meta_adlib: [
      { key: 'access_token', label: 'Ad Library token', sensitive: true },
    ],
    slack: [
      { key: 'webhook_url', label: 'Slack incoming-webhook URL', sensitive: true },
    ],
    google: [
      { key: 'refresh_token', label: 'Refresh token', sensitive: true },
      { key: 'client_id', label: 'OAuth client ID', sensitive: false },
      { key: 'client_secret', label: 'OAuth client secret', sensitive: true },
      { key: 'developer_token', label: 'Developer token', sensitive: true },
      { key: 'login_customer_id', label: 'MCC customer ID', sensitive: false },
    ],
    tiktok: [
      { key: 'bc_token', label: 'Business Center access token', sensitive: true },
    ],
  llm: [
    { key: 'anthropic_api_key', label: 'Anthropic API key', sensitive: true },
    { key: 'openai_api_key', label: 'OpenAI API key', sensitive: true },
  ],
  };
}

export async function getAuditLog(): Promise<AuditLogEntry[]> {
  await delay();
  return [
    { id: 1, actor: { id: 1, name: 'Kanwar' }, action: 'connection.attached', target: 'TikTok · Meller', ip: '82.143.10.4', createdAt: '12 min ago' },
    { id: 2, actor: { id: 2, name: 'Jordan Reeves' }, action: 'user.role_changed', target: 'Diego López (team_member → manager)', ip: '94.21.55.2', createdAt: '2 hr ago' },
    { id: 3, actor: { id: 1, name: 'Kanwar' }, action: 'user.invited', target: 'maria@nova-solution.io (team_member)', ip: '82.143.10.4', createdAt: '2 days ago' },
    { id: 4, actor: { id: 2, name: 'Jordan Reeves' }, action: 'brand_access.granted', target: 'Sara Mahmoud — Ayla & Co', ip: '94.21.55.2', createdAt: '3 days ago' },
    { id: 5, actor: { id: 1, name: 'Kanwar' }, action: 'impersonation.started', target: 'Ana Martín (brand_user)', ip: '82.143.10.4', createdAt: '3 days ago' },
    { id: 6, actor: { id: 1, name: 'Kanwar' }, action: 'impersonation.ended', target: 'Ana Martín', ip: '82.143.10.4', createdAt: '3 days ago' },
    { id: 7, actor: { id: 3, name: 'Sara Mahmoud' }, action: 'mfa.enabled', target: '(self)', ip: '37.46.8.9', createdAt: '5 days ago' },
    { id: 8, actor: { id: 1, name: 'Kanwar' }, action: 'connection.deleted', target: 'TikTok · Marlow & Sons', ip: '82.143.10.4', createdAt: '6 days ago' },
  ];
}
