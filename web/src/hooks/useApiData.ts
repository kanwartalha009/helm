import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';
import type {
  AuditLogEntry,
  Brand,
  SyncLog,
  User,
} from '@/types/domain';

// Real-API hooks for the pages that have shipped end-to-end with Laravel.
// (mockApi and its hooks were removed 2026-07-10 — every page reads the real
// API now; Phase 2 placeholder pages render explicit empty states instead.)

/* ---- Brands ---------------------------------------------------------- */

export function useBrandsLive() {
  return useQuery({
    queryKey: ['brands', 'live'],
    queryFn: async () => {
      const { data } = await api.get<Brand[] | { data: Brand[] }>('/brands');
      return Array.isArray(data) ? data : data.data;
    },
  });
}

import type { PlatformConnection } from '@/types/domain';

export interface BrandDetailResponse {
  brand: Brand;
  connections: PlatformConnection[];
}

/**
 * GET /api/brands/{slug} — single brand + its connections in one round trip.
 * The route param is the brand slug; the controller eager-loads connections.
 */
export function useBrandDetail(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'live'],
    enabled: !!slug,
    queryFn: async (): Promise<BrandDetailResponse> => {
      const { data } = await api.get<BrandDetailResponse>(`/brands/${slug}`);
      return data;
    },
  });
}

export interface BrandMetricTile {
  label: string;
  revenue: number;
  netSales: number;
  totalSales: number;
  orders: number;
  refunds: number;
  days: number;
  isComplete: boolean;
}

export interface BrandDailyMetricRow {
  date: string;
  netSales: number | null;
  totalSales: number | null;
  orders: number | null;
  refunds: number | null;
  // Blended ad spend across Meta/Google/TikTok and Blended ROAS (Total revenue
  // ÷ spend), both in the brand's native currency. Null when no ad data.
  spend: number | null;
  roas: number | null;
  currency: string;
  isComplete: boolean;
  pulledAt: string | null;
}

export interface BrandMetricsResponse {
  currency: string;
  timezone: string;
  /** Size of the daily series window (the API caps `daily` — default 90 days, ?days= up to 365). */
  windowDays?: number;
  tiles: {
    today: BrandMetricTile;
    yesterday: BrandMetricTile;
    last7: BrandMetricTile;
    last30: BrandMetricTile;
    allTime: BrandMetricTile;
  };
  daily: BrandDailyMetricRow[];
}

/**
 * GET /api/brands/{slug}/metrics — rolled-up tiles + full daily series.
 * Invalidated by useTriggerSync so the Overview tab refreshes the instant the
 * sync request returns.
 */
export function useBrandMetrics(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'metrics'],
    enabled: !!slug,
    queryFn: async (): Promise<BrandMetricsResponse> => {
      const { data } = await api.get<BrandMetricsResponse>(`/brands/${slug}/metrics`);
      return data;
    },
  });
}

/* ---- Users (Team page) ---------------------------------------------- */

export function useUsers(enabled = true) {
  return useQuery({
    queryKey: ['users'],
    enabled,
    queryFn: async () => {
      const { data } = await api.get<User[] | { data: User[] }>('/users');
      return Array.isArray(data) ? data : data.data;
    },
  });
}

/* ---- Brand team access (brand_user_access) -------------------------- */

export interface BrandAccessUsersResponse {
  userIds: number[];
  users: { id: number; name: string; email: string; role: string; status: string }[];
}

/** GET /api/brands/{slug}/users — users assigned to a brand. Admin/manager only. */
export function useBrandAccessUsers(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand-users', slug],
    enabled: !!slug,
    queryFn: async (): Promise<BrandAccessUsersResponse> => {
      const { data } = await api.get<BrandAccessUsersResponse>(`/brands/${slug}/users`);
      return data;
    },
  });
}

/** PUT /api/brands/{slug}/users — replace the brand's assigned users. */
export function useAssignBrandUsers() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: { slug: string; userIds: number[] }) => {
      const { data } = await api.put(`/brands/${input.slug}/users`, { user_ids: input.userIds });
      return data;
    },
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ['brand-users', vars.slug] });
      qc.invalidateQueries({ queryKey: ['users'] });
      toast.success('Team updated', 'Brand access saved.');
    },
    onError: (err: any) => {
      toast.error("Couldn't update team", err?.response?.data?.message ?? err.message);
    },
  });
}

/** GET /api/users/{id} — single user by numeric id. */
export function useUser(id: number | string | undefined) {
  return useQuery({
    queryKey: ['user', String(id)],
    enabled: id !== undefined && id !== '',
    queryFn: async () => {
      const { data } = await api.get<User>(`/users/${id}`);
      return data;
    },
  });
}

/* ---- Audit log ------------------------------------------------------- */

interface AuditLogPage {
  data: AuditLogEntry[];
  nextCursor: string | null;
  prevCursor: string | null;
  hasMore: boolean;
}

/**
 * GET /api/audit-logs — cursor-paginated. Default 50/page. The caller can
 * pass a cursor for the next page; useAuditLogs() without args fetches the
 * most recent page so the existing AuditLogPage works unchanged.
 */
export function useAuditLogs(cursor?: string) {
  return useQuery({
    queryKey: ['audit-logs', 'live', cursor ?? 'first'],
    queryFn: async (): Promise<AuditLogPage> => {
      const { data } = await api.get<AuditLogPage | AuditLogEntry[]>('/audit-logs', {
        params: cursor ? { cursor } : undefined,
      });
      // Defensive: if older callers ever bypass the cursor endpoint and get
      // back an array, normalise to the page shape so the consumer is stable.
      if (Array.isArray(data)) {
        return { data, nextCursor: null, prevCursor: null, hasMore: false };
      }
      return data;
    },
  });
}

/* ---- Sync status ----------------------------------------------------- */

export interface SyncCounts {
  successful: number;
  failed: number;
  running: number;
  queued: number;
}

export interface SyncStatusPayload {
  logs: SyncLog[];
  counts: SyncCounts;
}

export function useSyncStatus() {
  return useQuery({
    queryKey: ['sync-status', 'live'],
    queryFn: async (): Promise<SyncStatusPayload> => {
      const { data } = await api.get<SyncStatusPayload>('/sync/status');
      // Defensive — earlier shape returned a flat collection; tolerate both.
      if (Array.isArray(data)) {
        return {
          logs: data,
          counts: {
            successful: 0,
            failed: 0,
            running: 0,
            queued: 0,
          },
        };
      }
      return data;
    },
  });
}

import { useMutation, useQueryClient } from '@tanstack/react-query';

export function useRetrySyncLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (logId: number) => {
      await api.post(`/sync-logs/${logId}/retry`);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['sync-status'] });
    },
  });
}

/* ---- Deep analytics (slice 2.1/2.4) + LLM chat (D-016) ---------------- */

export interface BrandProductRow {
  key: string;
  title: string;
  revenue: number;
  orders: number;
  units: number;
  refunds: number;
  refundRatePct: number | null;
  sharePct: number | null;
  prevRevenue: number | null;
  deltaPct: number | null;
}

export interface BrandProductsResponse {
  currency: string;
  periodStart: string;
  periodEnd: string;
  rows: BrandProductRow[];
  totalRevenue: number;
  hasData: boolean;
  lastPulledAt: string | null;
}

/** GET /api/brands/{slug}/products — commerce product aggregates for a window. */
export function useBrandProducts(slug: string | undefined, period: string, search: string) {
  return useQuery({
    queryKey: ['brand', slug, 'products', period, search],
    enabled: !!slug,
    queryFn: async (): Promise<BrandProductsResponse> => {
      const { data } = await api.get<BrandProductsResponse>(`/brands/${slug}/products`, {
        params: { period, ...(search ? { search } : {}) },
      });
      return data;
    },
  });
}

export interface AuditFinding {
  id: string;
  area: 'ads' | 'inventory' | 'data';
  severity: 'critical' | 'warn' | 'info' | 'good';
  title: string;
  detail: string;
  meta?: Record<string, unknown> | null;
}

export interface AuditFindingsResponse {
  periodStart: string;
  periodEnd: string;
  findings: AuditFinding[];
  generatedAt: string;
}

/** GET /api/brands/{slug}/audit-findings — rules-engine findings, never LLM. */
export function useAuditFindings(slug: string | undefined, period: string) {
  return useQuery({
    queryKey: ['brand', slug, 'audit-findings', period],
    enabled: !!slug,
    queryFn: async (): Promise<AuditFindingsResponse> => {
      const { data } = await api.get<AuditFindingsResponse>(`/brands/${slug}/audit-findings`, {
        params: { period },
      });
      return data;
    },
  });
}
