import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * The Stop/Scale/Fix board (GO-3.2) — open ledger recommendations, made operable.
 *
 * ACCEPT RECORDS INTENT AND EXECUTES NOTHING. Helm never touches an ad account. The
 * accept response returns a `checklist` of what the OPERATOR must now go and do
 * themselves, and `executionNote` says so on every render.
 *
 * Recording intent is what makes the track record (GO-3.3) possible: without an
 * "accepted" timestamp there is nothing to measure the outcome against.
 */
export interface RecommendationRow {
  id: number;
  source: string;
  kind: string;
  subjectType: string;
  subjectId: string;
  title: string;
  evidence: Record<string, unknown>;
  confidence: 'solid' | 'early';
  status: 'open' | 'accepted' | 'dismissed' | 'expired';
  statusReason: string | null;
  /** GO-3.3 — shown in the ledger table INCLUDING the losses. A track record that only
   *  displays its wins is an advertisement. Null until the outcome is measured (14–30d). */
  outcome: 'improved' | 'worsened' | 'flat' | 'unmeasurable' | null;
  outcomeMetric: string | null;
  measuredAt: string | null;
  createdAt: string | null;
}

export interface RecommendationsResponse {
  rows: RecommendationRow[];
  kindLabels: Record<string, string>;
  kindOrder: string[];
  checklists: Record<string, string[]>;
  executionNote: string;
}

export function useRecommendations(slug: string | undefined, status = 'open') {
  return useQuery({
    queryKey: ['brand', slug, 'recommendations', status],
    enabled: !!slug,
    queryFn: async (): Promise<RecommendationsResponse> => {
      const { data } = await api.get<RecommendationsResponse>(`/brands/${slug}/recommendations`, { params: { status } });
      return data;
    },
  });
}

export function useAcceptRecommendation(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<{ status: string; checklist: string[]; note: string }> => {
      const { data } = await api.post(`/brands/${slug}/recommendations/${id}/accept`);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'recommendations'] }),
  });
}

export function useDismissRecommendation(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (args: { id: number; reason: string }) => {
      await api.post(`/brands/${slug}/recommendations/${args.id}/dismiss`, { reason: args.reason });
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'recommendations'] }),
  });
}
