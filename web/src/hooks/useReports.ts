import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';
import type {
  AnyReportData,
  NarrativeBlocksShape,
  ReportContent,
  ReportFiltersInput,
  ReportNarrativePayload,
  ReportTypeItem,
} from '@/types/reports';

// Real /api/reports* endpoints — no mocks. The reporting engine (slice 2.0).

export function useReportTypes() {
  return useQuery({
    queryKey: ['report-types'],
    queryFn: async () => {
      const { data } = await api.get<{ reports: ReportTypeItem[] }>('/reports');
      return data.reports ?? [];
    },
    staleTime: 5 * 60_000,
  });
}

export function useReport(
  slug: string | undefined,
  type: string | undefined,
  filters: ReportFiltersInput,
) {
  return useQuery({
    queryKey: ['report', slug, type, filters.period, filters.compare],
    enabled: !!slug && !!type,
    queryFn: async () => {
      const { data } = await api.get<AnyReportData>(
        `/brands/${slug}/reports/${type}`,
        { params: { period: filters.period, compare: filters.compare } },
      );
      return data;
    },
  });
}

// Public, token-addressed read-only report (the link Bosco sends a client).
export function usePublicReport(token: string | undefined) {
  return useQuery({
    queryKey: ['public-report', token],
    enabled: !!token,
    retry: false,
    queryFn: async () => {
      const { data } = await api.get<AnyReportData>(`/r/${token}`);
      return data;
    },
  });
}

export function useGenerateNarrative(slug: string | undefined, type: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: ReportFiltersInput & { language?: 'en' | 'es' }) => {
      const { data } = await api.post<{ narrative: ReportNarrativePayload }>(
        `/brands/${slug}/reports/${type}/narrative`,
        payload,
      );
      return data.narrative;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['report', slug] }),
    onError: (err: any) =>
      toast.error('Couldn\u2019t generate the narrative', err?.response?.data?.message ?? err?.message ?? 'Unknown error'),
  });
}

export function useSaveNarrative(slug: string | undefined, type: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: ReportFiltersInput & { blocks: NarrativeBlocksShape }) => {
      const { data } = await api.patch<{ narrative: ReportNarrativePayload }>(
        `/brands/${slug}/reports/${type}/narrative`,
        payload,
      );
      return data.narrative;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['report', slug] }),
    onError: (err: any) =>
      toast.error('Couldn\u2019t save your edits', err?.response?.data?.message ?? err?.message ?? 'Unknown error'),
  });
}

export function useCreateShare(slug: string | undefined, type: string | undefined) {
  return useMutation({
    mutationFn: async (payload: {
      filters: ReportFiltersInput;
      content: ReportContent;
    }) => {
      const { data } = await api.post<{ token: string; url: string }>(
        `/brands/${slug}/reports/${type}/shares`,
        payload,
      );
      return data;
    },
    onError: (err: any) => {
      toast.error(
        'Couldn’t create share link',
        err?.response?.data?.message ?? err?.message ?? 'Unknown error',
      );
    },
  });
}
