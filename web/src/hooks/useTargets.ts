import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Monthly targets + pacing (GO-2.1).
 *
 * Pacing counts only COMPLETE days — `completeDays` of `daysInMonth`. Any block whose
 * target is unset comes back null (never 0), and status 'unknown' means we have
 * measured nothing yet rather than that the brand is failing.
 */
export interface PacingMetric {
  actual: number;
  target: number;
  expectedNow: number;
  delta: number;
  pctOfTarget: number | null;
  status: 'on_pace' | 'behind' | 'over' | 'unknown';
}

export interface Pacing {
  month: string;
  currency: string;
  daysInMonth: number;
  completeDays: number;
  elapsedPct: number;
  dataThrough: string | null;
  monthEnded: boolean;
  revenue: PacingMetric | null;
  spend: PacingMetric | null;
  roas: { actual: number | null; target: number; status: string } | null;
  targets: { revenue: number | null; spendCap: number | null; roas: number | null; mer: number | null };
}

export interface TargetsResponse {
  month: string;
  target: {
    revenueTarget: number | null;
    spendCap: number | null;
    roasTarget: number | null;
    merTarget: number | null;
  } | null;
  pacing: Pacing | null;
}

export function useBrandTargets(slug: string | undefined, month?: string) {
  return useQuery({
    queryKey: ['brand', slug, 'targets', month ?? 'current'],
    enabled: !!slug,
    queryFn: async (): Promise<TargetsResponse> => {
      const { data } = await api.get<TargetsResponse>(`/brands/${slug}/targets`, {
        params: month ? { month } : {},
      });
      return data;
    },
  });
}

export function useSaveBrandTargets(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: {
      month: string;
      revenue_target?: number | null;
      spend_cap?: number | null;
      roas_target?: number | null;
      mer_target?: number | null;
    }) => {
      const { data } = await api.put(`/brands/${slug}/targets`, body);
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['brand', slug, 'targets'] });
      qc.invalidateQueries({ queryKey: ['brands-pacing'] });
    },
  });
}

export interface BrandPacingRow {
  brandId: number;
  month: string;
  completeDays: number;
  daysInMonth: number;
  pctOfTarget: number | null;
  status: 'on_pace' | 'behind' | 'over' | 'unknown';
  delta: number;
  currency: string;
}

/** Pacing for every accessible brand — merged into the dashboard client-side (no parity risk). */
export function useBrandsPacing(enabled = true) {
  return useQuery({
    queryKey: ['brands-pacing'],
    enabled,
    staleTime: 5 * 60 * 1000,
    queryFn: async (): Promise<{ rows: BrandPacingRow[] }> => {
      const { data } = await api.get<{ rows: BrandPacingRow[] }>('/brands-pacing');
      return data;
    },
  });
}
