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
  /** A standing goal applies to every month with no explicit override. */
  isStandingDefault: boolean;
  currency: string;
  daysInMonth: number;
  completeDays: number;
  remainingDays: number;
  /** What the brand must average per remaining day to still hit the goal. */
  neededPerDay: number | null;
  elapsedPct: number;
  dataThrough: string | null;
  monthEnded: boolean;
  revenue: PacingMetric | null;
  spend: PacingMetric | null;
  /** `actual` is null (never 0) when there is no ad spend — no ratio exists. USD-correct. */
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
    isStandingDefault: boolean;
  } | null;
  pacing: Pacing | null;
}

/**
 * Read a brand's target for a month.
 *
 * `exact` (editor mode) reads the TRUE stored row for the scope with no
 * standing-default fallback — a specific `month` reads that month's own row;
 * `exact` with no `month` reads the standing default itself. Without `exact`
 * (the default), the response is the RESOLVED goal in force (month override,
 * else standing default), which is what the read-only cards want.
 */
export function useBrandTargets(slug: string | undefined, month?: string, exact = false) {
  return useQuery({
    queryKey: ['brand', slug, 'targets', month ?? 'current', exact ? 'exact' : 'resolved'],
    enabled: !!slug,
    queryFn: async (): Promise<TargetsResponse> => {
      const params: Record<string, string> = {};
      if (month) params.month = month;
      if (exact) params.exact = '1';
      const { data } = await api.get<TargetsResponse>(`/brands/${slug}/targets`, { params });
      return data;
    },
  });
}

export function useSaveBrandTargets(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: {
      /** Omit for the STANDING DEFAULT goal (applies to every un-overridden month). */
      month?: string;
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

/**
 * Clear a brand's goal for a scope. Pass a 'Y-m' month to remove just that
 * month's override, or '__default' to clear the standing default (matches the
 * DELETE brands/{brand}/targets/{month} contract). Used by the goals drawer so
 * a wrongly-set default (e.g. July numbers leaking into every month) can be
 * removed without leaving the report.
 */
export function useDeleteBrandTarget(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (scope: string) => {
      const { data } = await api.delete(`/brands/${slug}/targets/${encodeURIComponent(scope)}`);
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
