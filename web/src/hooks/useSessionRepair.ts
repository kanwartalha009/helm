import { useEffect, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';
import type { InventorySyncRun } from './useInventorySync';

/**
 * "Fill missing days" on the Inventory sessions strip.
 *
 * The sessions gate is all-or-nothing: one missing or unreconciled day blanks the whole window
 * (a partial sum would under-report every product while looking exact). So the operator needs to
 * fix that day from the UI — the empty state used to tell them to run an artisan command, which is
 * not something a customer can do.
 *
 * Kept SEPARATE from useInventorySync on purpose:
 *  - it re-pulls only the specific broken days in the CURRENT window, which may be months old and
 *    therefore unreachable by the 7-day "Sync now" refresh;
 *  - it polls its own run, so an inventory refresh already in flight doesn't make this look busy.
 */
const isActive = (s: InventorySyncRun['status'] | undefined): boolean => s === 'queued' || s === 'running';

export function useSessionRepairStatus(slug: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ['session-repair', slug],
    enabled: !!slug && enabled,
    queryFn: async (): Promise<{ run: InventorySyncRun | null }> => {
      const { data } = await api.get<{ run: InventorySyncRun | null }>(`/brands/${slug}/inventory/sessions/repair`);
      return data;
    },
    // Poll only while a run is in flight — a finished run polls at zero cost.
    refetchInterval: (q) => (isActive(q.state.data?.run?.status) ? 2500 : false),
    refetchOnWindowFocus: false,
  });
}

export function useStartSessionRepair(slug: string | undefined) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (vars: { from: string; to: string }): Promise<{ run: InventorySyncRun }> => {
      const { data } = await api.post<{ run: InventorySyncRun }>(
        `/brands/${slug}/inventory/sessions/repair`,
        vars,
      );
      return data;
    },
    onSuccess: (data) => {
      // Seed the poller so the button flips to "Filling…" on the very next paint rather than after
      // the first poll lands — otherwise the click looks like it did nothing for 2.5 seconds.
      qc.setQueryData(['session-repair', slug], data);
      qc.invalidateQueries({ queryKey: ['session-repair', slug] });
    },
    onError: (err: unknown) => {
      // A swallowed error is what makes a button feel DEAD. Say something.
      const status = (err as { response?: { status?: number } })?.response?.status;
      toast.error(
        'Could not start the fill',
        status === 404
          ? 'This endpoint is not deployed yet — deploy the latest API.'
          : status === 429
            ? 'Too many requests. Wait a minute and try again.'
            : 'Something went wrong. Check the logs and try again.',
      );
    },
  });
}

/**
 * Fire `onFinished` exactly once, when a run reaches a terminal state.
 *
 * Without this the page keeps rendering the "—" it was showing before: the data on the server
 * changed, but the inventory query's cache has no idea, so nothing refetches and the fill looks
 * like it did nothing. The run id guard means we invalidate on the TRANSITION, not on every 2.5s
 * poll that happens to observe a finished run.
 */
export function useRepairFinished(run: InventorySyncRun | null | undefined, onFinished: () => void): void {
  const handled = useRef<number | null>(null);

  const id = run?.id;
  const status = run?.status;

  useEffect(() => {
    if (id === undefined || status === undefined) return;
    if (status !== 'done' && status !== 'failed') return;
    if (handled.current === id) return;

    handled.current = id;
    onFinished();
  }, [id, status, onFinished]);
}
