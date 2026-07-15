import { useEffect, useState } from 'react';
import { Button, Drawer } from '@/components/ui';
import { formatMoney } from '@/lib/formatters';
import {
  useClearCountryTiers,
  useCountryTiers,
  useSaveCountryTiers,
  useAvailableCountries,
  type CountryTierRow,
} from '@/hooks/useCountryTiers';
import { toast } from '@/stores/toastStore';

interface EditRow {
  tierKey: string;
  label: string;
  color: string;
  countries: string[];
}

const PALETTE = ['#2563eb', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#65a30d', '#db2777'];

/**
 * M5 addendum (Kanwar, 2026-07-15 — "tier system move to side bar and button
 * to create tiers... show list of countries against the brand to group them...
 * accessible with button in brand level and report as well"). This EXPLICITLY
 * SUPERSEDES M1's own prior ratified decision ("PRIMARY UI = brand Settings",
 * Kanwar 2026-07-12, still noted in CountryTierController.php's docblock for
 * the record) — the old always-rendered inline form (CountryTiersSection.tsx)
 * is retired; brand Settings now shows a compact read-only summary
 * (CountryTiersSummary.tsx) with a button into this same drawer.
 *
 * Two entry points share this ONE component (never a second copy that could
 * drift): a button on the brand detail page, and a button on the mom report
 * itself (MomReportDocument) — both just render <CountryTierDrawer open .../>.
 *
 * Countries come from `useAvailableCountries` — the brand's REAL Shopify-
 * revenue/Meta-spend countries (CountryRevenueSpend, the same join S5/S6
 * read), not free-typed ISO-2 text. Assigning is "move" semantics: picking a
 * country into tier B removes it from whichever tier it was in before, since
 * CountryTiers::resolve() would otherwise silently let position order decide
 * — this UI never lets that ambiguity exist in the first place.
 */
export function CountryTierDrawer({
  slug,
  canEdit,
  open,
  onClose,
  currency = 'USD',
}: {
  slug: string | undefined;
  canEdit: boolean;
  open: boolean;
  onClose: () => void;
  currency?: string;
}) {
  const { data } = useCountryTiers(slug);
  const { data: available } = useAvailableCountries(slug);
  const save = useSaveCountryTiers(slug);
  const clear = useClearCountryTiers(slug);

  const [rows, setRows] = useState<EditRow[]>([]);

  useEffect(() => {
    if (!data || !open) return;
    setRows(data.tiers.map((t) => ({ tierKey: t.tierKey, label: t.label, color: t.color, countries: [...t.countries] })));
  }, [data, open]);

  const countries = available?.countries ?? [];
  const byIso2 = new Map(countries.map((c) => [c.iso2, c]));
  const assigned = new Set(rows.flatMap((r) => r.countries));
  const unassigned = countries.filter((c) => !assigned.has(c.iso2));

  const addTier = () => {
    const color = PALETTE[rows.length % PALETTE.length];
    setRows((r) => [...r, { tierKey: '', label: '', color, countries: [] }]);
  };
  const removeTier = (i: number) => setRows((r) => r.filter((_, idx) => idx !== i));
  const updateTier = (i: number, patch: Partial<EditRow>) =>
    setRows((r) => r.map((row, idx) => (idx === i ? { ...row, ...patch } : row)));

  // Move semantics — a country can only ever sit in one tier's list at a time.
  const assignCountry = (iso2: string, tierIndex: number) => {
    setRows((r) => r.map((row, idx) => {
      if (idx === tierIndex) return { ...row, countries: row.countries.includes(iso2) ? row.countries : [...row.countries, iso2] };
      return row.countries.includes(iso2) ? { ...row, countries: row.countries.filter((c) => c !== iso2) } : row;
    }));
  };
  const unassignCountry = (iso2: string, tierIndex: number) => {
    setRows((r) => r.map((row, idx) => (idx === tierIndex ? { ...row, countries: row.countries.filter((c) => c !== iso2) } : row)));
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
      countries: r.countries,
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
    <Drawer
      open={open}
      onClose={onClose}
      size="lg"
      title="Country tiers"
      footer={
        canEdit ? (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Button size="sm" variant="ghost" type="button" onClick={addTier}>
              + Add tier
            </Button>
            <span style={{ flex: 1 }} />
            {data?.hasOverride && (
              <Button size="sm" variant="ghost" type="button" disabled={clear.isPending} onClick={onResetToAgencyDefault}>
                Reset to agency default
              </Button>
            )}
            <Button size="sm" variant="secondary" type="button" disabled={save.isPending} onClick={onSave}>
              {save.isPending ? 'Saving…' : 'Save tiers'}
            </Button>
          </div>
        ) : undefined
      }
    >
      <div className="muted text-sm" style={{ marginBottom: 14 }}>
        {data?.hasOverride ? 'This brand has its own tier set.' : 'Following the agency default set — edit below to customize for this brand.'}
        {' '}Countries below are pulled from this brand's real Shopify/Meta data over the last {available?.windowMonths ?? 6} months.
        A country you don't assign anywhere reads as "Other" in the report — never dropped.
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {rows.map((row, i) => (
          <div key={i} style={{ border: '1px solid var(--border)', borderRadius: 10, padding: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10, flexWrap: 'wrap' }}>
              <input
                className="input"
                style={{ width: 90, fontSize: 12 }}
                value={row.tierKey}
                onChange={(e) => updateTier(i, { tierKey: e.target.value })}
                placeholder="Key (T1)"
                maxLength={24}
                disabled={!canEdit}
              />
              <input
                className="input"
                style={{ flex: 1, minWidth: 120, fontSize: 12 }}
                value={row.label}
                onChange={(e) => updateTier(i, { label: e.target.value })}
                placeholder="Label (Tier 1)"
                maxLength={48}
                disabled={!canEdit}
              />
              <input
                className="input"
                type="color"
                style={{ width: 34, padding: 2 }}
                value={row.color}
                onChange={(e) => updateTier(i, { color: e.target.value })}
                disabled={!canEdit}
              />
              {canEdit && (
                <Button size="sm" variant="ghost" type="button" onClick={() => removeTier(i)}>
                  Remove
                </Button>
              )}
            </div>

            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: canEdit ? 8 : 0 }}>
              {row.countries.length === 0 && <span className="muted text-sm">No countries assigned yet.</span>}
              {row.countries.map((iso2) => {
                const c = byIso2.get(iso2);
                return (
                  <span
                    key={iso2}
                    className="chip"
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: `${row.color}1a`, borderColor: row.color }}
                    title={c?.revenue != null ? `${formatMoney(c.revenue, currency, { compact: true })} revenue (last ${available?.windowMonths ?? 6}mo)` : 'No recent revenue data'}
                  >
                    {c?.label ?? iso2} <span className="muted" style={{ fontSize: 10 }}>{iso2}</span>
                    {canEdit && (
                      <button
                        type="button"
                        onClick={() => unassignCountry(iso2, i)}
                        aria-label={`Remove ${iso2}`}
                        style={{ border: 0, background: 'transparent', cursor: 'pointer', fontSize: 12, lineHeight: 1, color: 'var(--text-muted)' }}
                      >
                        ×
                      </button>
                    )}
                  </span>
                );
              })}
            </div>

            {canEdit && (
              <select
                className="input"
                style={{ fontSize: 12, width: '100%' }}
                value=""
                onChange={(e) => {
                  if (e.target.value) assignCountry(e.target.value, i);
                }}
              >
                <option value="">+ Add a country to this tier…</option>
                {countries
                  .filter((c) => !row.countries.includes(c.iso2))
                  .map((c) => (
                    <option key={c.iso2} value={c.iso2}>
                      {c.label} ({c.iso2}){c.revenue != null ? ` — ${formatMoney(c.revenue, currency, { compact: true })}` : ''}
                      {c.tierKey && c.tierKey !== row.tierKey ? ` — currently ${c.tierKey}` : ''}
                    </option>
                  ))}
              </select>
            )}
          </div>
        ))}

        {rows.length === 0 && <div className="muted text-sm">No tiers yet — add one below to start grouping this brand's countries.</div>}
      </div>

      {unassigned.length > 0 && (
        <div style={{ marginTop: 16 }}>
          <div className="field-label" style={{ marginBottom: 6 }}>
            Unassigned ({unassigned.length}) — reads as "Other" in the report
          </div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {unassigned.map((c) => (
              <span key={c.iso2} className="chip" title={c.revenue != null ? formatMoney(c.revenue, currency, { compact: true }) : 'No recent data'}>
                {c.label} <span className="muted" style={{ fontSize: 10 }}>{c.iso2}</span>
              </span>
            ))}
          </div>
        </div>
      )}
    </Drawer>
  );
}
