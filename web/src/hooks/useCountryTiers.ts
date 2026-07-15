import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * M1 (monthly-report-v2-mom.md §M1) — country tiers as a PLATFORM PRIMITIVE.
 * `resolved` is the ISO-2 -> tier map this brand's countries actually use RIGHT
 * NOW (brand override if one exists, else the agency default); a country absent
 * from `resolved` is "Other" — unassigned, not dropped.
 */
export interface CountryTierRow {
  id?: number;
  tierKey: string;
  label: string;
  color: string;
  countries: string[];
  position?: number;
}

export interface ResolvedTier {
  tierKey: string;
  label: string;
  color: string;
}

export interface CountryTiersResponse {
  tiers: CountryTierRow[];
  resolved: Record<string, ResolvedTier>;
  hasOverride: boolean;
}

export function useCountryTiers(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'country-tiers'],
    enabled: !!slug,
    queryFn: async (): Promise<CountryTiersResponse> => {
      const { data } = await api.get<CountryTiersResponse>(`/brands/${slug}/country-tiers`);
      return data;
    },
  });
}

export function useSaveCountryTiers(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (tiers: CountryTierRow[]) => {
      const { data } = await api.put(`/brands/${slug}/country-tiers`, { tiers });
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'country-tiers'] }),
  });
}

/** Reverts the brand to reading the agency-wide default tier set. */
export function useClearCountryTiers(slug: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data } = await api.delete(`/brands/${slug}/country-tiers`);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['brand', slug, 'country-tiers'] }),
  });
}

/** Settings -> General: the agency-wide default set every brand without its own override reads from. */
export function useAgencyDefaultTiers() {
  return useQuery({
    queryKey: ['workspace-country-tiers'],
    queryFn: async (): Promise<{ tiers: CountryTierRow[] }> => {
      const { data } = await api.get<{ tiers: CountryTierRow[] }>('/workspace-country-tiers');
      return data;
    },
  });
}

export function useSaveAgencyDefaultTiers() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (tiers: CountryTierRow[]) => {
      const { data } = await api.put('/workspace-country-tiers', { tiers });
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['workspace-country-tiers'] }),
  });
}

/**
 * M5 addendum (Kanwar, 2026-07-15 — "show list of countries against the
 * brand to group them"): the brand's ACTUAL countries (real Shopify revenue +
 * Meta spend over a trailing 6-month window, CountryTierController::
 * availableCountries) plus their CURRENT tier assignment. This is what the
 * tier sidebar's picker reads instead of a free-text ISO-2 field — a country
 * absent from the trailing window but still tier-assigned is unioned in with
 * `revenue: null`/`spend: null` (honest "no recent data", never a fabricated
 * 0), sorted after the real revenue rows server-side.
 */
export interface AvailableCountry {
  iso2: string;
  label: string;
  revenue: number | null;
  spend: number | null;
  tierKey: string | null;
}

export function useAvailableCountries(slug: string | undefined) {
  return useQuery({
    queryKey: ['brand', slug, 'available-countries'],
    enabled: !!slug,
    queryFn: async (): Promise<{ countries: AvailableCountry[]; windowMonths: number }> => {
      const { data } = await api.get(`/brands/${slug}/country-tiers/available-countries`);
      return data;
    },
  });
}
