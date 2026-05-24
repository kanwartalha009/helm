import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type {
  AuditLogEntry,
  Brand,
  SyncLog,
  User,
} from '@/types/domain';

// Real-API hooks for the pages that have shipped end-to-end with Laravel.
// Phase 2/3 pages still pull from mockApi via useDashboardData.ts — that file
// is intentionally left alone so the work-in-progress views keep rendering.

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
  revenueNet: number;
  orders: number;
  refunds: number;
  days: number;
  isComplete: boolean;
}

export interface BrandDailyMetricRow {
  date: string;
  platform: string;
  revenue: number | null;
  revenueNet: number | null;
  orders: number | null;
  refunds: number | null;
  currency: string;
  fxRateToUsd: number;
  isComplete: boolean;
  pulledAt: string | null;
}

export interface BrandMetricsResponse {
  currency: string;
  timezone: string;
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

export function useUsers() {
  return useQuery({
    queryKey: ['users'],
    queryFn: async () => {
      const { data } = await api.get<User[] | { data: User[] }>('/users');
      return Array.isArray(data) ? data : data.data;
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
