import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { InventoryPeriod, InventoryResponse } from '@/types/inventory';

/**
 * GET /api/brands/{slug}/inventory — the Inventory Intelligence report for one
 * brand over a selectable window. Native brand currency (no USD toggle — this is
 * a single-brand view). `from`/`to` are only sent for a custom range; the
 * server ignores them otherwise. Gated on a brand being selected.
 */
export function useInventory(
  slug: string | undefined,
  period: InventoryPeriod,
  from?: string,
  to?: string,
  enabled: boolean = true,
) {
  const custom = period === 'custom' && !!from && !!to;
  return useQuery({
    queryKey: ['inventory', slug, period, custom ? from : '', custom ? to : ''],
    enabled: !!slug && enabled,
    queryFn: async (): Promise<InventoryResponse> => {
      const params: Record<string, string> = { period };
      if (custom) {
        params.from = from as string;
        params.to = to as string;
      }
      const { data } = await api.get<InventoryResponse>(`/brands/${slug}/inventory`, { params });
      return data;
    },
  });
}
