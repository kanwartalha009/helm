import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type {
  MarketAlert,
  MarketFilters,
  MarketResponse,
  ResolveResult,
  TrackedPage,
  WinnersFilters,
  WinnersResponse,
} from '@/types/adsLibrary';

/**
 * GET /api/ads-library/winners — internal winners (Phase 1). Cross-brand, RBAC
 * scoped server-side. Empty filters map to last30 / sort=roas defaults.
 */
export function useAdsLibraryWinners(filters: WinnersFilters) {
  return useQuery({
    queryKey: ['ads-library-winners', filters],
    queryFn: async (): Promise<WinnersResponse> => {
      const params: Record<string, string> = { window: filters.window };
      if (filters.niche) params.niche = filters.niche;
      if (filters.platform) params.platform = filters.platform;
      if (filters.media_type) params.media_type = filters.media_type;
      if (filters.brand) params.brand = filters.brand;
      if (filters.sort) params.sort = filters.sort;
      if (filters.search) params.search = filters.search;
      const { data } = await api.get<WinnersResponse>('/ads-library/winners', { params });
      return data;
    },
  });
}

/** GET /api/ads-library/market — stored corpus, concept-collapsed. Proxy signals only. */
export function useAdsLibraryMarket(filters: MarketFilters, enabled = true) {
  return useQuery({
    queryKey: ['ads-library-market', filters],
    enabled,
    queryFn: async (): Promise<MarketResponse> => {
      const params: Record<string, string | number> = {};
      for (const [k, v] of Object.entries(filters)) {
        if (v !== undefined && v !== '') params[k] = v as string | number;
      }
      const { data } = await api.get<MarketResponse>('/ads-library/market', { params });
      return data;
    },
  });
}

/** GET "This week in your market" — deterministic competitor movement alerts. */
export function useMarketAlerts(niche?: string) {
  return useQuery({
    queryKey: ['ads-library-alerts', niche ?? ''],
    queryFn: async (): Promise<MarketAlert[]> => {
      const { data } = await api.get<{ alerts: MarketAlert[] }>('/ads-library/alerts', { params: niche ? { niche } : {} });
      return data.alerts;
    },
  });
}

/** GET tracked competitor pages. */
export function useTrackedPages(enabled = true) {
  return useQuery({
    queryKey: ['ads-library-pages'],
    enabled,
    queryFn: async (): Promise<TrackedPage[]> => {
      const { data } = await api.get<{ pages: TrackedPage[] }>('/ads-library/pages');
      return data.pages;
    },
  });
}

export function useResolvePage() {
  return useMutation({
    mutationFn: async (input: string): Promise<ResolveResult> => {
      const { data } = await api.post<ResolveResult>('/ads-library/pages/resolve', { input });
      return data;
    },
  });
}

export function useTrackPage() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: { page_id: string; page_name?: string | null; niche?: string | null; country_default?: string | null }) => {
      const { data } = await api.post('/ads-library/pages', body);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['ads-library-pages'] }),
  });
}

export function useUntrackPage() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/ads-library/pages/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['ads-library-pages'] }),
  });
}

/** POST "Search Meta live" — enriches the corpus, then the market list refetches. */
export function useLiveSearch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: { q: string; search_type?: string; niche?: string | null; media_type?: 'IMAGE' | 'VIDEO' }) => {
      const { data } = await api.post<{ upserted: number }>('/ads-library/market/search', body);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['ads-library-market'] }),
  });
}
