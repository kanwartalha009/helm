import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { CompareBaseline, DateRangePreset } from '@/types/domain';

export type DashboardView = 'compact' | 'wide';

// Phase 1: currency selection removed. Every brand renders in its own
// native currency on the dashboard, so there's no workspace-level USD vs
// native toggle to persist.
interface FiltersState {
  period: DateRangePreset;
  compareBaseline: CompareBaseline;
  returns: 'gross' | 'net';
  brandGroup: string | null;
  view: DashboardView;
  setPeriod: (p: DateRangePreset) => void;
  setCompareBaseline: (b: CompareBaseline) => void;
  setReturns: (r: 'gross' | 'net') => void;
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
      brandGroup: null,
      view: 'compact',
      setPeriod: (period) => set({ period }),
      setCompareBaseline: (compareBaseline) => set({ compareBaseline }),
      setReturns: (returns) => set({ returns }),
      setBrandGroup: (brandGroup) => set({ brandGroup }),
      setView: (view) => set({ view }),
    }),
    { name: 'helm.filters' }
  )
);
