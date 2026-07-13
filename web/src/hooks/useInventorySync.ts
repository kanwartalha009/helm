import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';

/**
 * Manual "Sync now" for Inventory Intelligence.
 *
 * The refresh runs on the queue (it re-pulls stock, sales, product ad spend and sessions — tens of
 * seconds to a couple of minutes), so the POST returns immediately and we POLL the run until it
 * finishes. The operator sees which dataset is being pulled, not an anonymous spinner: the job
 * writes a step line ("3/5 · Sessions") BEFORE each step starts.
 */
export type SyncRunStatus = 'queued' | 'running' | 'done' | 'failed';

export interface InventorySyncRun {
  id: number;
  status: SyncRunStatus;
  message: string | null;
  startedAt: string | null;
  finishedAt: string | null;
}

const isActive = (s: SyncRunStatus | undefined): boolean => s === 'queued' || s === 'running';

export function useInventorySyncStatus(slug: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ['inventory-sync', slug],
    enabled: !!slug && enabled,
    queryFn: async (): Promise<{ run: InventorySyncRun | null }> => {
      const { data } = await api.get<{ run: InventorySyncRun | null }>(`/brands/${slug}/inventory/sync`);
      return data;
    },
    // Poll only while a run is in flight — a finished run polls at zero cost.
    refetchInterval: (q) => (isActive(q.state.data?.run?.status) ? 2500 : false),
    refetchOnWindowFocus: false,
  });
}

export function useStartInventorySync(slug: string | undefined) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<{ run: InventorySyncRun }> => {
      const { data } = await api.post<{ run: InventorySyncRun }>(`/brands/${slug}/inventory/sync`);
      return data;
    },
    onSuccess: (data) => {
      // Seed the poller with the run we just started, so the button flips to "Syncing…" on the
      // very next paint rather than after the first poll lands.
      qc.setQueryData(['inventory-sync', slug], data);
      qc.invalidateQueries({ queryKey: ['inventory-sync', slug] });
    },
    onError: (err: unknown) => {
      // A silently-swallowed error is what makes a button feel DEAD: the click does nothing, the
      // label never changes, and the operator has no idea whether they even hit it. Say something.
      const status = (err as { response?: { status?: number } })?.response?.status;
      toast.error(
        'Could not start the sync',
        status === 404
          ? 'The sync endpoint is not available yet — deploy the latest API.'
          : status === 429
            ? 'Too many sync requests. Wait a minute and try again.'
            : 'Something went wrong. Check the logs and try again.',
      );
    },
  });
}
