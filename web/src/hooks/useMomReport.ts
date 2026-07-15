import { useQuery, useQueryClient, useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * M2 (monthly-report-v2-mom.md §M2) + REV2 R3 — the frontend half of the
 * section-streamed architecture M0 exists to teach. `useMomReport` fetches
 * ONLY the shell (month/currency/availableMonths/freshness/section manifest);
 * `useMomSection` is called ONCE PER SECTION by the section-card components
 * themselves, each an independent react-query cache entry — never one
 * combined fetch. This mirrors the backend split exactly (MomReport vs
 * MomSectionController) so the SPA can never regress into the M0 monolith.
 */

export interface MomFiltersInput {
  month?: string;
  compare: 'previous' | 'last_year';
  compareMonth?: string; // REV2 R3 "Custom" — explicit second month, overrides `compare`'s derivation
}

export interface MomSectionManifestEntry {
  key: string;
  label: string;
  enabled: boolean;
  position: number;
  view: 'chart' | 'table' | 'both';
  settings?: Record<string, unknown> | null;
  ready: boolean;
}

export interface MomReportShell {
  reportType: 'mom';
  brand: { name: string; slug: string; baseCurrency: string; timezone: string };
  currency: string;
  month: { label: string; start: string; end: string };
  compareMonth: { label: string; start: string; end: string } | null;
  availableMonths: { key: string; label: string }[];
  sections: MomSectionManifestEntry[];
  freshness: { upToDate: boolean; lastSynced: string | null; staleDays: number; windowEnd: string; note?: string };
}

function toQuery(filters: MomFiltersInput): Record<string, string> {
  const q: Record<string, string> = { compare: filters.compare };
  if (filters.month) q.month = filters.month;
  if (filters.compareMonth) q.compare_month = filters.compareMonth;
  return q;
}

export function useMomReport(slug: string | undefined, filters: MomFiltersInput) {
  const q = toQuery(filters);
  return useQuery({
    queryKey: ['mom-report', slug, q],
    enabled: !!slug,
    queryFn: async (): Promise<MomReportShell> => {
      const { data } = await api.get<MomReportShell>(`/brands/${slug}/reports/mom`, { params: q });
      return data;
    },
  });
}

/**
 * One section, one request — the whole point of this architecture (M0).
 *
 * `extraParams` (M5 addendum, Kanwar 2026-07-15) lets ONE section layer its
 * own query params on top of the shared filters without touching
 * `MomFiltersInput` — e.g. S1's 3/4/6/12-month window selector, which is a
 * control on that section's own card, not a report-wide filter every other
 * section would need to understand.
 */
export function useMomSection<T = Record<string, unknown>>(
  slug: string | undefined,
  key: string,
  filters: MomFiltersInput,
  enabled: boolean,
  extraParams?: Record<string, string>,
) {
  const q = { ...toQuery(filters), ...extraParams };
  return useQuery({
    queryKey: ['mom-section', slug, key, q],
    enabled: !!slug && enabled,
    queryFn: async (): Promise<T & { key: string; status: string }> => {
      const { data } = await api.get(`/brands/${slug}/reports/mom/sections/${key}`, { params: q });
      return data;
    },
    // A section that failed (status: 'no_data'/'needs_source') is still a
    // valid, successful response — never retried as if it were a network error.
    retry: false,
  });
}

export interface MomCommentary {
  month: string;
  sectionKey: string;
  commentary: string | null;
  todo: { text: string; done?: boolean }[];
}

export function useMomCommentary(slug: string | undefined, key: string, month: string | undefined) {
  return useQuery({
    queryKey: ['mom-commentary', slug, key, month],
    enabled: !!slug && !!month,
    queryFn: async (): Promise<MomCommentary> => {
      const { data } = await api.get(`/brands/${slug}/reports/mom/sections/${key}/commentary`, { params: { month } });
      return data;
    },
  });
}

export function useSaveMomCommentary(slug: string | undefined, key: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: { month: string; commentary: string | null; todo: { text: string; done?: boolean }[] }) => {
      const { data } = await api.put(`/brands/${slug}/reports/mom/sections/${key}/commentary`, body);
      return data;
    },
    onSuccess: (_data, vars) => qc.invalidateQueries({ queryKey: ['mom-commentary', slug, key, vars.month] }),
  });
}

export interface MomNextStepItem {
  text: string;
  group: 'mes' | 'ads' | 'countries' | 'email' | 'cro';
  status: 'open' | 'done' | 'dropped';
  carriedFrom: string | null;
}

export function useSaveMomNextSteps(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: { month: string; items: MomNextStepItem[] }) => {
      const { data } = await api.put(`/brands/${slug}/reports/mom/next-steps`, body);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ predicate: (q) => q.queryKey[0] === 'mom-section' && q.queryKey[2] === 'S0' }),
  });
}

export function useSaveMomNovedades(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: { month: string; body: string }) => {
      const { data } = await api.put(`/brands/${slug}/reports/mom/novedades`, body);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ predicate: (q) => q.queryKey[0] === 'mom-section' && q.queryKey[2] === 'S19' }),
  });
}
