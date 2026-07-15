import { useState } from 'react';
import { Card, Button, Segmented } from '@/components/ui';
import { useTriggerBackfill } from '@/hooks/useApiData';
import type { MomFiltersInput, MomSectionManifestEntry } from '@/hooks/useMomReport';
import { useMomCommentary, useMomSection, useSaveMomCommentary } from '@/hooks/useMomReport';
import { SECTION_CHART_RENDERERS } from './sectionCharts';
import { SECTION_TABLE_RENDERERS } from './sectionTables';
import { GenericKeyValue, GenericTable } from './GenericTable';
import { SNextStepsCard } from './SNextSteps';
import { SNovedadesCard } from './SNovedades';

// M5 addendum (Kanwar, 2026-07-15) — S1's own trailing-window control (last
// 3/4/6/12 months vs the same months last year). Lives here, not on the
// shared MomFilterBar, since it's specific to S1's financial matrix (see
// SFinancialMatrixSection's own M5 docblock) — every other section ignores
// the `months` param entirely.
const MONTHS_OPTIONS: { value: string; label: string }[] = [
  { value: '', label: 'Full year' },
  { value: '3', label: '3mo' },
  { value: '4', label: '4mo' },
  { value: '6', label: '6mo' },
  { value: '12', label: '12mo' },
];

/**
 * REV2 R1/R2 — one CARD per section: header (label + ready state), the
 * chart-as-hero + table-as-secondary view per the section's resolved `view`
 * ('chart'|'table'|'both'), and the M2 commentary/To-Do annotation block.
 *
 * Status handling mirrors the backend's own honesty contract exactly:
 *   not_built_yet -> "coming soon" placeholder, never an error
 *   needs_source  -> a "Backfill this data" chip (M5, monthly-report-v2-mom.md
 *                    §M5) wired to the SAME backfill-dataset endpoint/job v1
 *                    reports use, keyed off `backfillDataset` — the section's
 *                    own hint from MomSectionRegistry::datasetFor(), never a
 *                    frontend-side copy of that mapping.
 *   no_data       -> a plain "nothing to show" note
 *   ok            -> the real chart/table
 * A real fetch failure (network/5xx, not an honest status) gets its own Retry
 * button — distinct from the above, all of which are successful HTTP 200s
 * carrying an honest non-'ok' status.
 */
export function MomSectionCard({
  slug,
  section,
  filters,
  currency,
}: {
  slug: string;
  section: MomSectionManifestEntry;
  filters: MomFiltersInput;
  currency: string;
}) {
  // S0/S19 have bespoke editorial UIs (checklist / free text) rather than the
  // generic chart+table+commentary shape every metric section uses — they
  // don't go through useMomSection/SECTION_CHART_RENDERERS at all.
  if (section.key === 'S0') return <SNextStepsCard slug={slug} filters={filters} label={section.label} />;
  if (section.key === 'S19') return <SNovedadesCard slug={slug} filters={filters} label={section.label} />;

  return <MetricSectionCard slug={slug} section={section} filters={filters} currency={currency} />;
}

function MetricSectionCard({
  slug,
  section,
  filters,
  currency,
}: {
  slug: string;
  section: MomSectionManifestEntry;
  filters: MomFiltersInput;
  currency: string;
}) {
  // S1-only trailing-window selector; '' means "unset" (the default full-year
  // tables) — kept as a plain string so it drops cleanly out of extraParams.
  const [monthsWindow, setMonthsWindow] = useState('');
  const extraParams = section.key === 'S1' && monthsWindow ? { months: monthsWindow } : undefined;
  const { data, isLoading, isError, refetch, isRefetching } = useMomSection(slug, section.key, filters, section.ready, extraParams);
  const [showNotes, setShowNotes] = useState(false);
  const backfill = useTriggerBackfill(slug);

  if (!section.ready) {
    return (
      <Card style={{ padding: 18, opacity: 0.6 }}>
        <SectionHeader label={section.label} sub="Coming soon — not built yet" />
      </Card>
    );
  }

  return (
    <Card style={{ padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <SectionHeader label={section.label} sub={data?.status && data.status !== 'ok' ? statusNote(data) : undefined} />
        {section.key === 'S1' && (
          <Segmented options={MONTHS_OPTIONS} value={monthsWindow} onChange={setMonthsWindow} />
        )}
      </div>

      {isLoading && <div className="muted text-sm">Loading…</div>}

      {/* A real fetch failure (network/5xx) is distinct from the backend's own
          honest non-'ok' statuses below — those are successful 200s. Retry
          here re-fires just THIS section's query, never the whole report. */}
      {isError && !isLoading && (
        <div className="muted text-sm" style={{ padding: '8px 0', display: 'flex', alignItems: 'center', gap: 8 }}>
          <span>Couldn’t load this section.</span>
          <Button size="sm" variant="ghost" type="button" disabled={isRefetching} onClick={() => refetch()}>
            {isRefetching ? 'Retrying…' : 'Retry'}
          </Button>
        </div>
      )}

      {data && data.status === 'ok' && (
        <SectionBody payload={data} sectionKey={section.key} view={section.view} currency={currency} />
      )}

      {data && data.status !== 'ok' && (
        <div className="muted text-sm" style={{ padding: '8px 0' }}>
          <div>{(data as any).note ?? statusNote(data)}</div>
          {/* M5 — the section's own backfillDataset hint (from
              MomSectionRegistry::datasetFor(), attached by MomSectionController
              only on 'needs_source') drives the SAME backfill-dataset
              endpoint/job v1's DataCoverageCard already triggers. No dataset
              hint (e.g. S16's genuine schema gap) means no button — never a
              CTA that would 202 into a job that can't fill the gap. */}
          {data.status === 'needs_source' && (data as any).backfillDataset && (
            <Button
              size="sm"
              variant="secondary"
              type="button"
              style={{ marginTop: 6 }}
              disabled={backfill.isPending}
              onClick={() => backfill.mutate((data as any).backfillDataset)}
            >
              {backfill.isPending ? 'Starting backfill…' : 'Backfill this data'}
            </Button>
          )}
        </div>
      )}

      {data && data.status === 'ok' && (
        <div style={{ marginTop: 10 }}>
          <Button size="sm" variant="ghost" type="button" onClick={() => setShowNotes((s) => !s)}>
            {showNotes ? 'Hide notes' : 'Commentary & To-Do'}
          </Button>
          {showNotes && filters.month && <CommentaryEditor slug={slug} sectionKey={section.key} month={filters.month} />}
        </div>
      )}
    </Card>
  );
}

function SectionHeader({ label, sub }: { label: string; sub?: string }) {
  return (
    <div style={{ marginBottom: 10 }}>
      <div style={{ fontSize: 14, fontWeight: 650 }}>{label}</div>
      {sub && <div className="muted text-sm">{sub}</div>}
    </div>
  );
}

function statusNote(data: { status: string }): string {
  switch (data.status) {
    case 'needs_source':
      return 'Needs a data sync that hasn’t run for this brand yet.';
    case 'no_data':
      return 'Nothing to show for this month.';
    case 'not_built_yet':
      return 'Coming soon.';
    default:
      return '';
  }
}

// Exported so PublicMomSectionCard (the share-link view, M5 addendum) can
// reuse the exact same chart/table twin lookup for a section — one render
// path, not a second copy that could quietly drift from this one.
export function SectionBody({
  payload,
  sectionKey,
  view,
  currency,
}: {
  payload: Record<string, any>;
  sectionKey: string;
  view: 'chart' | 'table' | 'both';
  currency: string;
}) {
  const chartRenderer = SECTION_CHART_RENDERERS[sectionKey];
  const chart = chartRenderer ? chartRenderer(payload, currency) : null;
  // M5 (Kanwar, 2026-07-15 — "table primary, chart secondary") — a bespoke
  // color-coded HeatTable twin (sectionTables.tsx) takes priority over the
  // plain uncolored GenericTable fallback wherever one exists.
  const tableRenderer = SECTION_TABLE_RENDERERS[sectionKey];
  const rows: Record<string, unknown>[] | null = Array.isArray(payload.rows) ? payload.rows : null;
  const table = tableRenderer ? tableRenderer(payload, currency) : (rows ? <GenericTable rows={rows} /> : <GenericKeyValue payload={payload} />);

  const showChart = (view === 'chart' || view === 'both') && chart;
  // Falls back to the table when the requested view is 'chart' but this
  // section has no bespoke chart twin yet — a blank card is never acceptable.
  const showTable = view === 'table' || view === 'both' || !chart;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      {payload.unavailable && typeof payload.unavailable === 'object' && !Array.isArray(payload.unavailable) && (
        <UnavailableNote unavailable={payload.unavailable} />
      )}
      {/* Table renders before the chart — the color-coded numbers are the
          primary view, the chart is the secondary/visual-summary view. */}
      {showTable && <div>{table}</div>}
      {showChart && <div>{chart}</div>}
    </div>
  );
}

function UnavailableNote({ unavailable }: { unavailable: Record<string, unknown> }) {
  const entries = Object.entries(unavailable).filter(([, v]) => typeof v === 'string');
  if (entries.length === 0) return null;
  return (
    <details style={{ fontSize: 11 }}>
      <summary className="muted" style={{ cursor: 'pointer' }}>
        {entries.length} field{entries.length === 1 ? '' : 's'} not available this pass
      </summary>
      <ul style={{ margin: '4px 0 0', paddingLeft: 16 }}>
        {entries.map(([k, v]) => (
          <li key={k} className="muted">
            <b>{k}:</b> {String(v)}
          </li>
        ))}
      </ul>
    </details>
  );
}

function CommentaryEditor({ slug, sectionKey, month }: { slug: string; sectionKey: string; month: string }) {
  const { data } = useMomCommentary(slug, sectionKey, month);
  const save = useSaveMomCommentary(slug, sectionKey);
  const [text, setText] = useState<string | null>(null);
  const value = text ?? data?.commentary ?? '';

  return (
    <div style={{ marginTop: 8 }}>
      <textarea
        className="input"
        style={{ width: '100%', minHeight: 60, fontSize: 12 }}
        placeholder="Notes for this section, shown in the meeting…"
        value={value}
        onChange={(e) => setText(e.target.value)}
      />
      <Button
        size="sm"
        variant="secondary"
        type="button"
        style={{ marginTop: 6 }}
        disabled={save.isPending}
        onClick={() => save.mutate({ month, commentary: value, todo: data?.todo ?? [] })}
      >
        {save.isPending ? 'Saving…' : 'Save note'}
      </Button>
    </div>
  );
}
