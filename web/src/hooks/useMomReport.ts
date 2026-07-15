import { useQuery, useQueryClient, useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';

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

// Exported so the Share button (MomReportDocument, M5 addendum) can send the
// exact same filter shape into POST .../reports/mom/shares that every
// section fetch already uses — one place computes this, never two.
export function toQuery(filters: MomFiltersInput): Record<string, string> {
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

/**
 * M5 addendum (Kanwar, 2026-07-15 — public share links) — mom's own share
 * flow, mirroring useReports.ts's useCreateShare/usePublicReport but against
 * MomShareController's dedicated routes (mom's section-streamed shape has no
 * equivalent of v1's single-payload ReportRegistry->build(), see that
 * controller's docblock). `usePublicMomShell` + `usePublicMomSection` are
 * called the same "one shell fetch + one fetch per section" way the
 * authenticated useMomReport/useMomSection pair works — just against the
 * token-gated public routes, no Sanctum auth.
 */
export function useCreateMomShare(slug: string | undefined) {
  return useMutation({
    mutationFn: async (payload: { filters: Record<string, string>; expiresInDays?: number }) => {
      const { data } = await api.post<{ token: string; url: string }>(`/brands/${slug}/reports/mom/shares`, payload);
      return data;
    },
    onError: (err: any) => {
      toast.error('Couldn’t create share link', err?.response?.data?.message ?? err?.message ?? 'Unknown error');
    },
  });
}

export interface PublicMomShell {
  reportType: 'mom';
  brand: { name: string; slug: string; baseCurrency: string };
  currency: string;
  month: { label: string; start: string; end: string };
  sections: MomSectionManifestEntry[];
  // Per-agency white-label theme (MomShareController::branding(), same source
  // v1's public report payload carries) — used by MomPublicReportPage's
  // PresentationMode title slide instead of useAuth() since this route has
  // no authenticated session.
  branding: { agency_name: string; accent: string; footer_text: string };
  shared: true;
}

export function usePublicMomShell(token: string | undefined) {
  return useQuery({
    queryKey: ['public-mom-shell', token],
    enabled: !!token,
    retry: false,
    queryFn: async (): Promise<PublicMomShell> => {
      const { data } = await api.get<PublicMomShell>(`/mom/r/${token}`);
      return data;
    },
  });
}

export function usePublicMomSection<T = Record<string, unknown>>(token: string | undefined, key: string) {
  return useQuery({
    queryKey: ['public-mom-section', token, key],
    enabled: !!token,
    retry: false,
    queryFn: async (): Promise<T & { key: string; status: string }> => {
      const { data } = await api.get(`/mom/r/${token}/sections/${key}`);
      return data;
    },
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
