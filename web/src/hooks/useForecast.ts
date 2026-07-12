import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Forecast baseline (GO-2.3) — seasonal-naive + drift.
 *
 * `status: 'insufficient_history'` is a first-class outcome, not an error: the engine
 * REFUSES rather than extrapolating from thin history, and in that case there are no
 * numbers to render at all. Anything that does render must carry `label`
 * ("Modeled — baseline forecast") — a forecast shown without it is a bug.
 */
export interface ForecastDay {
  date: string;
  seasonal: number | null;   // what the brand did on this date last year (null = gap, not 0)
  forecast: number | null;
}

export interface ForecastResponse {
  status: 'ok' | 'insufficient_history';
  label: string;
  reason?: string;
  methodNote?: string;
  currency?: string;
  horizonDays?: number;
  periodStart?: string;
  periodEnd?: string;
  trend?: number;
  trendApplied?: boolean;
  trendClamped?: boolean;
  trendNote?: string;
  coverage?: { lastYearDays: number; ofDays: number; pct: number; missingDays: number };
  days?: ForecastDay[];
  totals?: { forecast: number; seasonalOnly: number };
  monthEnd?: {
    label: string;
    actualToDate: number;
    forecastRest: number;
    projectedMonth: number;
    currency: string;
  } | null;
}

export function useBrandForecast(slug: string | undefined, horizon?: number) {
  return useQuery({
    queryKey: ['brand', slug, 'forecast', horizon ?? 'default'],
    enabled: !!slug,
    queryFn: async (): Promise<ForecastResponse> => {
      const { data } = await api.get<ForecastResponse>(`/brands/${slug}/forecast`, {
        params: horizon ? { horizon } : {},
      });
      return data;
    },
  });
}
