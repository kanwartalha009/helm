import { useEffect, useState } from 'react';
import { Button } from '@/components/ui';
import {
  useClearCountryTiers,
  useCountryTiers,
  useSaveCountryTiers,
  type CountryTierRow,
} from '@/hooks/useCountryTiers';
import { toast } from '@/stores/toastStore';

/**
 * M1 (monthly-report-v2-mom.md §M1) — country tiers, PRIMARY UI = brand Settings
 * (Kanwar 2026-07-12). Bosco's real tiers are custom agency-defined labels (T1, T4,
 * US, ASIA, SUMMER, ES, NO, ...), not a fixed 1/2/3 — every field here is free text.
 *
 * A country not listed in ANY row below is "Other" at report time — never dropped,
 * just unassigned. Until this brand customizes its own set, it reads the agency-wide
 * default (Settings -> General); saving here COPIES the current view into a brand
 * override, matching the spec's "copies into brand rows, then edits" semantics.
 *
 * Countries are a comma-separated ISO-2 list (e.g. "US, CA, MX") rather than a
 * multi-select widget — the spec permits the simplest correct implementation
 * ("plain HTML5 drag or up/down buttons — no new deps" is the same spirit applied
 * to the reorder UI below); a proper country picker can replace this later without
 * changing the API shape.
 */
export function CountryTiersSection({ slug, canEdit }: { slug?: string; canEdit: boolean }) {
  const { data } = useCountryTiers(slug);
  const save = useSaveCountryTiers(slug);
  const clear = useClearCountryTiers(slug);

  const [rows, setRows] = useState<Array<{ tierKey: string; label: string; color: string; countriesText: string }>>([]);

  useEffect(() => {
    if (!data) return;
    setRows(
      data.tiers.map((t) => ({
        tierKey: t.tierKey,
        label: t.label,
        color: t.color,
        countriesText: t.countries.join(', '),
      })),
    );
  }, [data]);

  const addRow = () => {
    setRows((r) => [...r, { tierKey: '', label: '', color: '#2563eb', countriesText: '' }]);
  };

  const removeRow = (i: number) => setRows((r) => r.filter((_, idx) => idx !== i));

  const updateRow = (i: number, patch: Partial<(typeof rows)[number]>) => {
    setRows((r) => r.map((row, idx) => (idx === i ? { ...row, ...patch } : row)));
  };

  const onSave = () => {
    const keys = rows.map((r) => r.tierKey.trim().toUpperCase());
    if (keys.some((k) => k === '')) {
      toast.error('Every tier needs a key', 'e.g. T1, ASIA, SUMMER — short, unique.');
      return;
    }
    if (new Set(keys).size !== keys.length) {
      toast.error('Duplicate tier key', 'Each tier needs a unique key.');
      return;
    }

    const payload: CountryTierRow[] = rows.map((r) => ({
      tierKey: r.tierKey.trim().toUpperCase(),
      label: r.label.trim() || r.tierKey.trim(),
      color: r.color,
      countries: r.countriesText
        .split(',')
        .map((c) => c.trim().toUpperCase())
        .filter((c) => c.length === 2),
    }));

    save.mutate(payload, {
      onSuccess: () => toast.success('Tiers saved', 'The mom report will group countries using this set.'),
      onError: () => toast.error('Could not save tiers', 'Admins and managers only.'),
    });
  };

  const onResetToAgencyDefault = () => {
    clear.mutate(undefined, {
      onSuccess: () => toast.success('Reverted', 'This brand now follows the agency default tier set.'),
    });
  };

  return (
    <>
      <div className="field" style={{ marginTop: 8 }}>
        <label className="field-label">Country tiers</label>
        <span className="field-hint">
          {data?.hasOverride
            ? 'This brand has its own tier set.'
            : 'Following the agency default set — edit below to customize for this brand.'}
          {' '}A country not listed in any tier reads as "Other" — it is never dropped from a report.
        </span>
      </div>

      {rows.map((row, i) => (
        <div className="form-grid form-grid-2" key={i} style={{ marginBottom: 8 }}>
          <div className="field">
            <label className="field-label">Key</label>
            <input
              className="input"
              value={row.tierKey}
              onChange={(e) => updateRow(i, { tierKey: e.target.value })}
              placeholder="e.g. T1"
              maxLength={24}
              disabled={!canEdit}
            />
          </div>
          <div className="field">
            <label className="field-label">Label</label>
            <input
              className="input"
              value={row.label}
              onChange={(e) => updateRow(i, { label: e.target.value })}
              placeholder="e.g. Tier 1"
              maxLength={48}
              disabled={!canEdit}
            />
          </div>
          <div className="field">
            <label className="field-label">Color</label>
            <input
              className="input"
              type="color"
              value={row.color}
              onChange={(e) => updateRow(i, { color: e.target.value })}
              disabled={!canEdit}
            />
          </div>
          <div className="field">
            <label className="field-label">Countries (ISO-2, comma-separated)</label>
            <input
              className="input"
              value={row.countriesText}
              onChange={(e) => updateRow(i, { countriesText: e.target.value })}
              placeholder="e.g. US, CA, MX"
              disabled={!canEdit}
            />
          </div>
          {canEdit && (
            <div className="field" style={{ gridColumn: '1 / -1' }}>
              <Button size="sm" variant="ghost" type="button" onClick={() => removeRow(i)}>
                Remove tier
              </Button>
            </div>
          )}
        </div>
      ))}

      {canEdit && (
        <div className="flex items-center gap-8" style={{ marginTop: 8 }}>
          <Button size="sm" variant="ghost" type="button" onClick={addRow}>
            + Add tier
          </Button>
          <Button size="sm" variant="secondary" type="button" disabled={save.isPending} onClick={onSave}>
            {save.isPending ? 'Saving…' : 'Save tiers'}
          </Button>
          {data?.hasOverride && (
            <Button size="sm" variant="ghost" type="button" disabled={clear.isPending} onClick={onResetToAgencyDefault}>
              Reset to agency default
            </Button>
          )}
        </div>
      )}
    </>
  );
}
