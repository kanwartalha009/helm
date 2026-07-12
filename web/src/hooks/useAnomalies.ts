import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Anomaly feed (GO-2.4). Deterministic rules only — `evidence` always carries the
 * numbers, the rule and the threshold, so any alert can be re-derived by hand.
 *
 * Dismissal REQUIRES a reason (enforced server-side, 422 without one). That reason is
 * the honesty record the GO-3 ledger will score against — without it, "dismissed" would
 * become an unfalsifiable way for the engine to bury its own misses.
 */
export interface AnomalyRow {
  id: number;
  date: string;
  kind: 'cpm_spike' | 'cpa_spike' | 'roas_drop' | 'spend_spike' | 'zero_delivery' | 'stockout_on_ads' | 'mer_divergence';
  subject: string;
  severity: 'info' | 'warn' | 'critical';
  evidence: Record<string, unknown>;
  resolvedAt: string | null;
  resolutionReason: string | null;
  brand?: { name: string | null; slug: string | null };
}

export function useBrandAnomalies(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'anomalies'],
    enabled: !!slug,
    queryFn: async (): Promise<AnomalyRow[]> => {
      const { data } = await api.get<{ rows: AnomalyRow[] }>(`/brands/${slug}/anomalies`);
      return data.rows;
    },
  });
}

export function useDismissAnomaly(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (args: { id: number; reason: string }) => {
      await api.post(`/brands/${slug}/anomalies/${args.id}/dismiss`, { reason: args.reason });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['brand', slug, 'anomalies'] });
      qc.invalidateQueries({ queryKey: ['anomalies-feed'] });
    },
  });
}

/** Open anomalies across accessible brands — the dashboard bell. */
export function useAnomalyFeed(enabled = true) {
  return useQuery({
    queryKey: ['anomalies-feed'],
    enabled,
    staleTime: 5 * 60 * 1000,
    queryFn: async (): Promise<{ open: number; rows: AnomalyRow[] }> => {
      const { data } = await api.get<{ open: number; rows: AnomalyRow[] }>('/anomalies');
      return data;
    },
  });
}

/** Plain-language titles. The evidence carries the numbers; this is just the headline. */
export const ANOMALY_LABEL: Record<AnomalyRow['kind'], string> = {
  cpm_spike: 'CPM spike',
  cpa_spike: 'CPA spike',
  roas_drop: 'ROAS drop',
  spend_spike: 'Spend spike',
  zero_delivery: 'Platform stopped delivering',
  stockout_on_ads: 'Ads running on an out-of-stock product',
  mer_divergence: 'Tracking health — platform claims diverged from store revenue',
};
