import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as mockApi from '@/lib/mockApi';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';
import type { DashboardRow } from '@/types/domain';

// Real /api/dashboard call. Returns [] when the user has no brands yet —
// the DashboardPage renders an empty-state CTA in that case.
export function useDashboardData() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: async (): Promise<DashboardRow[]> => {
      const { data } = await api.get<{ rows: DashboardRow[] }>('/dashboard');
      return Array.isArray(data) ? data : data.rows ?? [];
    },
  });
}

interface MasterSyncResult {
  dispatched: number;
  brandsSynced: number;
  brandsSkipped: number;
  brandsAlreadyRunning?: number;
  totalBrands: number;
  message?: string;
}

/**
 * POST /api/sync/all — fans out the same job set as the per-brand Sync now
 * but for every active brand at once. The endpoint returns 202 immediately
 * and Horizon drains the queue in the background, so the dashboard query is
 * invalidated on a short delay rather than waiting for jobs to complete.
 *
 * Brands that already have an in-flight sync are silently skipped server-
 * side and counted as `brandsAlreadyRunning`. We mention this in the toast
 * so the operator understands why fewer jobs queued than they expected.
 */
export function useMasterSync() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (): Promise<MasterSyncResult> => {
      const { data } = await api.post<MasterSyncResult>('/sync/all');
      return data;
    },
    onSuccess: (result) => {
      if (result.dispatched === 0) {
        // Two distinct zero-dispatch cases: nothing to sync at all, or
        // everything was already running. Render them differently.
        if ((result.brandsAlreadyRunning ?? 0) > 0) {
          toast.info(
            'Sync already in progress',
            `${result.brandsAlreadyRunning} brand${
              result.brandsAlreadyRunning === 1 ? '' : 's'
            } already syncing. Nothing new queued.`
          );
          return;
        }
        toast.info(result.message ?? 'Nothing to sync', 'No active brands have any connections.');
        return;
      }

      const parts: string[] = [];
      if (result.brandsSkipped > 0) {
        parts.push(
          `${result.brandsSkipped} brand${result.brandsSkipped === 1 ? '' : 's'} skipped (no connections)`
        );
      }
      if ((result.brandsAlreadyRunning ?? 0) > 0) {
        parts.push(
          `${result.brandsAlreadyRunning} already syncing`
        );
      }
      const tail = parts.length > 0 ? ` ${parts.join(', ')}.` : '';

      toast.success(
        'Sync queued',
        `${result.dispatched} job${result.dispatched === 1 ? '' : 's'} queued across ${result.brandsSynced} brand${result.brandsSynced === 1 ? '' : 's'}.${tail} Refresh in a minute.`
      );
      // Pessimistic refresh — wait briefly so Horizon has a chance to start
      // draining before we re-fetch. The user can also hit refresh manually.
      setTimeout(() => {
        qc.invalidateQueries({ queryKey: ['dashboard'] });
        qc.invalidateQueries({ queryKey: ['sync-status'] });
      }, 8_000);
    },
    onError: (err: any) => {
      const msg =
        err?.response?.data?.message ??
        err?.message ??
        'The sync request failed. Check Horizon and try again.';
      toast.error('Couldn’t start sync', msg);
    },
  });
}

export function useBrands() {
  return useQuery({
    queryKey: ['brands'],
    queryFn: mockApi.getBrands,
  });
}

export function useBrand(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug],
    queryFn: () => (slug ? mockApi.getBrand(slug) : Promise.resolve(undefined)),
    enabled: !!slug,
  });
}

export function useConnections(brandId: number | undefined) {
  return useQuery({
    queryKey: ['connections', brandId],
    queryFn: () => (brandId ? mockApi.getConnections(brandId) : Promise.resolve([])),
    enabled: !!brandId,
  });
}

export function useSyncLogs() {
  return useQuery({ queryKey: ['sync-logs'], queryFn: mockApi.getSyncLogs });
}

export function useAdRows() {
  return useQuery({ queryKey: ['ad-rows'], queryFn: mockApi.getAdRows });
}

export function useProductRows() {
  return useQuery({ queryKey: ['product-rows'], queryFn: mockApi.getProductRows });
}

export function useAuditFindings() {
  return useQuery({ queryKey: ['audit-findings'], queryFn: mockApi.getAuditFindings });
}

export function useTickets() {
  return useQuery({ queryKey: ['tickets'], queryFn: mockApi.getTickets });
}

export function useAuditLog() {
  return useQuery({ queryKey: ['audit-log'], queryFn: mockApi.getAuditLog });
}

export function usePlatformCredentials() {
  return useQuery({
    queryKey: ['platform-credentials'],
    queryFn: mockApi.getPlatformCredentials,
  });
}

export function usePlatformCredentialSchema() {
  return useQuery({
    queryKey: ['platform-credential-schema'],
    queryFn: mockApi.getPlatformCredentialSchema,
    staleTime: 60 * 60_000, // schema doesn't change often
  });
}
