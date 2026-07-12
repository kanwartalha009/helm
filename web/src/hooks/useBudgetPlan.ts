import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Budget planner (GO-2.2). A plan document — nothing here reaches an ad platform.
 *
 * `spend90` / `runRateMonth` / `plannedSpend` / `delta` are all nullable: a platform
 * with no history has no run-rate, and an unplanned cell is unplanned — never 0.
 */
export interface BudgetPlanRow {
  platform: 'meta' | 'google' | 'tiktok';
  country: string;
  spend90: number | null;
  days90: number;
  reportedRoas: number | null;
  runRateMonth: number | null;
  plannedSpend: number | null;
  note: string | null;
  delta: number | null;
  deltaPct: number | null;
}

export interface BudgetPlanResponse {
  month: string;
  currency: string;
  lookbackDays: number;
  daysInMonth: number;
  rows: BudgetPlanRow[];
  totals: { runRateMonth: number | null; plannedSpend: number | null; delta: number | null };
  executionNote: string;
}

export function useBudgetPlan(slug: string | undefined, month?: string) {
  return useQuery({
    queryKey: ['brand', slug, 'budget-plan', month ?? 'next'],
    enabled: !!slug,
    queryFn: async (): Promise<BudgetPlanResponse> => {
      const { data } = await api.get<BudgetPlanResponse>(`/brands/${slug}/budget-plan`, {
        params: month ? { month } : {},
      });
      return data;
    },
  });
}

export function useSaveBudgetPlan(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: { month: string; platform: string; planned_spend: number; note?: string }) => {
      const { data } = await api.put(`/brands/${slug}/budget-plan`, body);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'budget-plan'] }),
  });
}
