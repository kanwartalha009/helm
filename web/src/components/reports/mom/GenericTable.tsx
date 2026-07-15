import { Fragment } from 'react';

/**
 * Fallback table renderer — any section's `rows: Array<Record<string, any>>`
 * (or a single flat object with no `rows` key) renders here automatically.
 * Every bespoke section component in sectionCharts.tsx pairs with this same
 * generic table for its 'table'/'both' view, so a chart twin is never the
 * ONLY way to see a section's numbers — the underlying data is always
 * inspectable, per REV2 R1 ("chart as the hero and the table as the
 * secondary view").
 */
function humanizeKey(k: string): string {
  return k
    .replace(/([A-Z])/g, ' $1')
    .replace(/^./, (c) => c.toUpperCase())
    .trim();
}

function formatCell(v: unknown): string {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'boolean') return v ? 'Yes' : 'No';
  if (typeof v === 'number') return Number.isInteger(v) ? v.toLocaleString() : v.toFixed(2);
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

export function GenericTable({ rows }: { rows: Record<string, unknown>[] }) {
  if (rows.length === 0) {
    return <div className="muted text-sm">No rows.</div>;
  }
  // Union of keys across the first N rows (rows can have slightly different
  // shapes, e.g. S1's 'no_data' placeholder months) — never crashes on a
  // ragged array.
  const keys: string[] = [];
  for (const r of rows.slice(0, 30)) {
    for (const k of Object.keys(r)) {
      if (!keys.includes(k) && k !== 'key') keys.push(k);
    }
  }
  const shown = keys.slice(0, 10); // keep wide matrices readable — a "see full data" affordance is a later increment

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="table" style={{ fontSize: 12, width: '100%' }}>
        <thead>
          <tr>
            {shown.map((k) => (
              <th key={k} style={{ textAlign: 'left', whiteSpace: 'nowrap' }}>
                {humanizeKey(k)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i}>
              {shown.map((k) => (
                <td key={k} style={{ whiteSpace: 'nowrap' }}>
                  {formatCell(r[k])}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** A section payload with no `rows` array — render its scalar fields as a key/value list. */
export function GenericKeyValue({ payload }: { payload: Record<string, unknown> }) {
  const entries = Object.entries(payload).filter(
    ([k, v]) => !['key', 'status', 'rows', 'unavailable', 'note'].includes(k) && typeof v !== 'object',
  );
  if (entries.length === 0) return <div className="muted text-sm">No data.</div>;
  return (
    <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '4px 12px', fontSize: 13 }}>
      {entries.map(([k, v]) => (
        <Fragment key={k}>
          <dt className="muted">{humanizeKey(k)}</dt>
          <dd style={{ margin: 0 }}>{formatCell(v)}</dd>
        </Fragment>
      ))}
    </dl>
  );
}
