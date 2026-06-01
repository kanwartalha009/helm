import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { CompareBaseline, DateRangePreset } from '@/types/domain';

export type DashboardView = 'compact' | 'wide';

// Currency display mode for the dashboard. 'native' renders each brand in
// its own currency; 'usd' asks the API to convert every brand to USD for a
// blended view (docs/12 acceptance — the currency toggle).
export type CurrencyMode = 'native' | 'usd';

interface FiltersState {
  period: DateRangePreset;
  compareBaseline: CompareBaseline;
  returns: 'gross' | 'net';
  currency: CurrencyMode;
  brandGroup: string | null;
  view: DashboardView;
  setPeriod: (p: DateRangePreset) => void;
  setCompareBaseline: (b: CompareBaseline) => void;
  setReturns: (r: 'gross' | 'net') => void;
  setCurrency: (c: CurrencyMode) => void;
  setBrandGroup: (g: string | null) => void;
  setView: (v: DashboardView) => void;
}

// Persisted to localStorage so reloads keep the last view (mirrors spec §7 frontend conventions).
export const useFiltersStore = create<FiltersState>()(
  persist(
    (set) => ({
      period: 'yesterday',
      compareBaseline: 'prior_period',
      returns: 'net',
      currency: 'native',
      brandGroup: null,
      view: 'compact',
      setPeriod: (period) => set({ period }),
      setCompareBaseline: (compareBaseline) => set({ compareBaseline }),
      setReturns: (returns) => set({ returns }),
      setCurrency: (currency) => set({ currency }),
      setBrandGroup: (brandGroup) => set({ brandGroup }),
      setView: (view) => set({ view }),
    }),
    { name: 'helm.filters' }
  )
);
