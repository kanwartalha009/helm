import { useState, type ReactNode } from 'react';
import { Button, Modal } from '@/components/ui';
import { gradeColumn, heatCellStyle, type HeatGrade } from './heat';

/**
 * M5 addendum (Kanwar, 2026-07-15) — the shared color-coded table every mom
 * table-style section renders through (S1, S4-S8, S9-S11), replacing the
 * plain uncolored `GenericTable` fallback for those sections. Two things
 * v1's tables already did that this ports: per-column heat grading (via
 * each column's own `heat` config, see heat.ts) and a "View full table"
 * expand for anything longer than `previewRows` — v1's report never needed
 * this because it always rendered the whole document; mom's cards are
 * compact by default, so large matrices (S1's 12/24-row year tables) need an
 * explicit escape hatch rather than always dumping every row inline.
 */
export interface HeatColumn<R> {
  key: string;
  // ReactNode so a period column can carry a two-line "W23 / 1–7 Jun" header.
  label: ReactNode;
  align?: 'left' | 'right';
  render: (row: R, index: number, rows: R[]) => ReactNode;
  /** Column-wide min-max grading (v1's gradeCol) — grades this cell against every OTHER row's value in the same column. */
  heat?: { mode: 'column'; dir?: 'high' | 'low'; value: (row: R) => number | null | undefined };
  /** A grade already resolved per-row (e.g. a backend deltaPct fed through heatFromDeltaPct, or a fixed-benchmark grade) — use when the grading logic isn't a simple column-wide min-max. */
  gradeOf?: (row: R, index: number, rows: R[]) => HeatGrade;
}

export function HeatTable<R>({
  columns,
  rows,
  rowKey,
  previewRows = 8,
  title,
  footer,
  emptyLabel = 'No rows.',
}: {
  columns: HeatColumn<R>[];
  rows: R[];
  rowKey: (row: R, index: number) => string;
  previewRows?: number;
  title?: string;
  footer?: ReactNode;
  emptyLabel?: string;
}) {
  const [expanded, setExpanded] = useState(false);

  if (rows.length === 0) {
    return <div className="muted text-sm">{emptyLabel}</div>;
  }

  const shown = rows.length > previewRows ? rows.slice(0, previewRows) : rows;
  const hasMore = rows.length > previewRows;

  // Column-wide grading always uses the FULL row set as its baseline, even
  // when rendering just the preview slice — otherwise a row's color would
  // change between the compact preview and the "view full table" modal
  // purely because the grading pool shrank, which would read as the numbers
  // themselves having changed.
  const body = (visibleRows: R[]) => (
    <div style={{ overflowX: 'auto' }}>
      <table className="table" style={{ fontSize: 12, width: '100%', borderCollapse: 'collapse' }}>
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.key} style={{ textAlign: c.align ?? 'left', whiteSpace: 'nowrap', padding: '4px 8px' }}>
                {c.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {visibleRows.map((r, i) => (
            <tr key={rowKey(r, i)}>
              {columns.map((c) => {
                const grade = c.gradeOf
                  ? c.gradeOf(r, i, rows)
                  : c.heat
                    ? gradeColumn(c.heat.value(r), rows.map(c.heat!.value), c.heat.dir ?? 'high')
                    : '';
                return (
                  <td
                    key={c.key}
                    style={{ textAlign: c.align ?? 'left', whiteSpace: 'nowrap', padding: '4px 8px', ...heatCellStyle(grade) }}
                  >
                    {c.render(r, i, rows)}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );

  return (
    <div>
      {body(shown)}
      {hasMore && (
        <div style={{ marginTop: 8 }}>
          <Button size="sm" variant="ghost" type="button" onClick={() => setExpanded(true)}>
            View full table ({rows.length} rows)
          </Button>
        </div>
      )}
      {footer && <div className="muted text-sm" style={{ marginTop: 6 }}>{footer}</div>}

      <Modal open={expanded} onClose={() => setExpanded(false)} title={title ?? 'Full table'} size="lg">
        {body(rows)}
      </Modal>
    </div>
  );
}
