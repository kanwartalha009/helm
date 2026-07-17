import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** M1 + REV2 R2 (monthly-report-v2-mom.md) — the report customizer's read/write. */
export interface ReportLayoutSection {
  key: string;
  label?: string;
  enabled: boolean;
  position: number;
  view: 'chart' | 'table' | 'both';
  settings?: Record<string, unknown> | null;
}

export interface ReportLayoutResponse {
  reportType: string;
  sections: ReportLayoutSection[];
  hasOverride?: boolean;
}

export function useReportLayout(slug: string | undefined, reportType: string) {
  return useQuery({
    queryKey: ['brand', slug, 'report-layouts', reportType],
    enabled: !!slug,
    queryFn: async (): Promise<ReportLayoutResponse> => {
      const { data } = await api.get<ReportLayoutResponse>(`/brands/${slug}/report-layouts/${reportType}`);
      return data;
    },
  });
}

export function useSaveReportLayout(slug: string | undefined, reportType: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (sections: ReportLayoutSection[]) => {
      const { data } = await api.put(`/brands/${slug}/report-layouts/${reportType}`, { sections });
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'report-layouts', reportType] }),
  });
}

export function useClearReportLayout(slug: string | undefined, reportType: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data } = await api.delete(`/brands/${slug}/report-layouts/${reportType}`);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'report-layouts', reportType] }),
  });
}

/** Settings -> "Report format" page: the agency-wide default layout for a report type. */
export function useAgencyDefaultLayout(reportType: string) {
  return useQuery({
    queryKey: ['report-layouts-default', reportType],
    queryFn: async (): Promise<ReportLayoutResponse> => {
      const { data } = await api.get<ReportLayoutResponse>(`/report-layouts/${reportType}/default`);
      return data;
    },
  });
}

export function useSaveAgencyDefaultLayout(reportType: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (sections: ReportLayoutSection[]) => {
      const { data } = await api.put(`/report-layouts/${reportType}/default`, { sections });
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['report-layouts-default', reportType] }),
  });
}

/**
 * Save the current layout as the agency default AND apply it to every brand
 * (drops all per-brand overrides). master_admin only. Returns { brandsReset }.
 */
export function useApplyLayoutToAllBrands(reportType: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (sections: ReportLayoutSection[]): Promise<{ ok: boolean; brandsReset: number }> => {
      const { data } = await api.post(`/report-layouts/${reportType}/apply-to-all`, { sections });
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['report-layouts-default', reportType] });
      // Every brand's resolved layout may have changed → refetch any that are open.
      qc.invalidateQueries({ queryKey: ['brand'] });
    },
  });
}
