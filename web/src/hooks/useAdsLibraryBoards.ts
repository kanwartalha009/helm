import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { BoardDetail, BoardSummary, Brief } from '@/types/adsLibrary';

/** GET /api/ads-library/boards — board list. */
export function useBoards(enabled = true) {
  return useQuery({
    queryKey: ['ads-library-boards'],
    enabled,
    queryFn: async (): Promise<BoardSummary[]> => {
      const { data } = await api.get<{ boards: BoardSummary[] }>('/ads-library/boards');
      return data.boards;
    },
  });
}

export function useBoard(id: number | null) {
  return useQuery({
    queryKey: ['ads-library-board', id],
    enabled: id != null,
    queryFn: async (): Promise<BoardDetail> => {
      const { data } = await api.get<BoardDetail>(`/ads-library/boards/${id}`);
      return data;
    },
  });
}

export function useCreateBoard() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: { name: string; brand_id?: number | null; niche?: string | null }) => {
      const { data } = await api.post<{ id: number }>('/ads-library/boards', body);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['ads-library-boards'] }),
  });
}

export function useAddBoardItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (args: { boardId: number; source: 'internal' | 'market'; ref_id: string; tags?: string[]; note?: string }) => {
      const { boardId, ...body } = args;
      const { data } = await api.post(`/ads-library/boards/${boardId}/items`, body);
      return data;
    },
    onSuccess: (_d, v) => {
      qc.invalidateQueries({ queryKey: ['ads-library-board', v.boardId] });
      qc.invalidateQueries({ queryKey: ['ads-library-boards'] });
    },
  });
}

export function useRemoveBoardItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (args: { boardId: number; itemId: number }) => {
      await api.delete(`/ads-library/boards/${args.boardId}/items/${args.itemId}`);
    },
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['ads-library-board', v.boardId] }),
  });
}

export function useUpdateBoardItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (args: { boardId: number; itemId: number; tags?: string[]; note?: string | null }) => {
      const { boardId, itemId, ...body } = args;
      await api.patch(`/ads-library/boards/${boardId}/items/${itemId}`, body);
    },
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['ads-library-board', v.boardId] }),
  });
}

/** POST suggest-tags — optional LLM assist; returns taxonomy always, suggested when a key exists. */
export function useSuggestTags() {
  return useMutation({
    mutationFn: async (args: { boardId: number; itemId: number }): Promise<{ enabled: boolean; suggested: string[]; taxonomy: string[]; note?: string }> => {
      const { data } = await api.post(`/ads-library/boards/${args.boardId}/items/${args.itemId}/suggest-tags`);
      return data;
    },
  });
}

export function useCreateBrief() {
  return useMutation({
    mutationFn: async (args: { boardId: number; title?: string; product?: string }) => {
      const { boardId, ...body } = args;
      const { data } = await api.post<{ id: number }>(`/ads-library/boards/${boardId}/brief`, body);
      return data;
    },
  });
}

export function useBrief(id: number | null) {
  return useQuery({
    queryKey: ['ads-library-brief', id],
    enabled: id != null,
    queryFn: async (): Promise<Brief> => {
      const { data } = await api.get<Brief>(`/ads-library/briefs/${id}`);
      return data;
    },
  });
}
