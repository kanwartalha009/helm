import { Link } from 'react-router-dom';
import { Avatar, Card, Dot, Tag } from '@/components/ui';
import { formatMoney, formatPercent } from '@/lib/formatters';
import type { AudienceColumn, AudienceResponse, AudienceRow } from '@/types/domain';

/**
 * Audience view table — each brand's Meta spend split across the breakdown's
 * segments, with the Non-ASC (or Other) remainder as the trailing column.
 *
 * Design (Bosco-approved, 2026-06-29): monochrome stone ramp, NOT a categorical
 * palette — segments read as one quantity sliced, not unrelated buckets. Each
 * value cell shows the number first, then a thin share-of-total bar (the client
 * asked for "number then progress bar"). The final Composition column stacks the
 * whole mix into one bar so the split is legible at a glance, remainder included.
 *
 * Missing data is never €0 (spec rule #9): a brand with spend but no breakdown
 * rows for this axis shows an amber "Breakdown pending" state instead of dumping
 * all spend into the remainder; a brand with zero spend renders muted.
 */

// Warm-stone ramp, darkest first — the most strategic segment (e.g. New) reads
// heaviest. Six shades cover audience (≤5) and the top-N high-cardinality axes.
const SEGMENT_SHADES = ['#1C1917', '#44403C', '#57534E', '#78716C', '#A8A29E', '#D6D3D1'];
// The remainder isn't a real segment — flat light stone in cells, a hatch in the
// stacked bar so "Non-ASC / Other" is visually distinct from measured segments.
const REMAINDER_SHADE = '#E7E5E4';
const REMAINDER_HATCH =
  'repeating-linear-gradient(45deg, #E7E5E4, #E7E5E4 3px, #D6D3D1 3px, #D6D3D1 6px)';

const PERIOD_LABEL: Record<string, string> = {
  last7: 'last 7 days',
  last30: 'last 30 days',
  mtd: 'month to date',
};

interface Props {
  data: AudienceResponse;
}

export function AudienceTable({ data }: Props) {
  const { columns, rows, currency } = data;

  // Stable shade per column key: segment columns consume the ramp in order;
  // the remainder always gets its own light stone regardless of position.
  const shadeByKey = new Map<string, string>();
  let segIdx = 0;
  for (const col of columns) {
    if (col.kind === 'remainder') {
      shadeByKey.set(col.key, REMAINDER_SHADE);
    } else {
      shadeByKey.set(col.key, SEGMENT_SHADES[segIdx % SEGMENT_SHADES.length]);
      segIdx += 1;
    }
  }

  return (
    <Card style={{ overflowX: 'auto' }}>
      <table className="data-table wide-table audience-table">
        <thead>
          <tr>
            <th className="brand-col group-head">Brand</th>
            {columns.map((col) => (
              <th key={col.key} className="num group-head" style={{ minWidth: 116 }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, justifyContent: 'flex-end' }}>
                  <ColorSwatch column={col} shade={shadeByKey.get(col.key)!} />
                  {col.label}
                </span>
              </th>
            ))}
            <th className="num group-head group-start">Total</th>
            <th className="group-head" style={{ minWidth: 180 }}>Composition</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <AudienceTableRow
              key={row.brand.id}
              row={row}
              columns={columns}
              shadeByKey={shadeByKey}
              currencyMode={currency}
            />
          ))}
        </tbody>
      </table>

      <div className="text-xs muted" style={{ padding: '10px 16px', borderTop: '1px solid var(--border)' }}>
        Meta spend split by {breakdownLabel(data.breakdown)} over the {PERIOD_LABEL[data.period] ?? data.period}, ending
        yesterday in each brand&rsquo;s timezone. {data.breakdown === 'audience' ? (
          <>
            <strong>Non-ASC</strong> is spend outside Advantage+ Shopping campaigns — the account total minus the ASC
            segments — so the mix always reconciles to 100% of real spend.
          </>
        ) : (
          <>
            <strong>Other</strong> folds together the smaller segments beyond the top {SEGMENT_SHADES.length}.
          </>
        )}
      </div>
    </Card>
  );
}

function breakdownLabel(b: string): string {
  switch (b) {
    case 'audience':
      return 'ASC audience segment';
    case 'age_gender':
      return 'age & gender';
    case 'placement':
      return 'placement';
    case 'country':
      return 'country';
    case 'device':
      return 'device';
    default:
      return b;
  }
}

function ColorSwatch({ column, shade }: { column: AudienceColumn; shade: string }) {
  return (
    <span
      aria-hidden
      style={{
        width: 9,
        height: 9,
        borderRadius: 2,
        flex: '0 0 auto',
        background: column.kind === 'remainder' ? REMAINDER_HATCH : shade,
        border: column.kind === 'remainder' ? '1px solid var(--border-strong)' : 'none',
      }}
    />
  );
}

function AudienceTableRow({
  row,
  columns,
  shadeByKey,
  currencyMode,
}: {
  row: AudienceRow;
  columns: AudienceColumn[];
  shadeByKey: Map<string, string>;
  currencyMode: 'native' | 'usd';
}) {
  const { brand } = row;
  const currency = currencyMode === 'usd' ? 'USD' : brand.baseCurrency || 'USD';
  const total = row.total ?? 0;

  // Three honest states (spec rule #9 — missing is never €0):
  //   no spend           → muted, the brand simply didn't run Meta this period
  //   spend, no breakdown → amber "pending", don't fake a composition
  //   spend + breakdown   → the real split
  const noSpend = !row.hasSpend || total <= 0;
  const pending = row.hasSpend && total > 0 && !row.hasBreakdown;

  return (
    <tr>
      <td className="brand-col">
        <Link to={`/brands/${brand.slug}`} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Avatar initials={brand.initials} />
          <div>
            <div style={{ fontWeight: 500 }}>{brand.name}</div>
            <div className="brand-meta">
              {brand.region} · {brand.baseCurrency}
            </div>
          </div>
        </Link>
      </td>

      {noSpend ? (
        <td className="num" colSpan={Math.max(columns.length, 1)} style={{ textAlign: 'center' }}>
          <span className="muted">No Meta spend this period</span>
        </td>
      ) : pending ? (
        <td className="num" colSpan={Math.max(columns.length, 1)} style={{ textAlign: 'center' }}>
          <Tag variant="warning">
            <Dot variant="warning" />
            Breakdown pending
          </Tag>
        </td>
      ) : (
        columns.map((col) => {
          const value = row.segments[col.key] ?? 0;
          const pct = total > 0 ? (value / total) * 100 : 0;
          const shade = shadeByKey.get(col.key)!;
          return (
            <td key={col.key} className="num">
              <div style={{ fontVariantNumeric: 'tabular-nums' }}>{formatMoney(value, currency)}</div>
              <ShareBar pct={pct} shade={shade} remainder={col.kind === 'remainder'} />
            </td>
          );
        })
      )}

      <td className="num group-start" style={{ fontVariantNumeric: 'tabular-nums', fontWeight: 500 }}>
        {noSpend ? <span className="muted">—</span> : formatMoney(total, currency)}
      </td>

      <td>
        {noSpend || pending ? (
          <div
            className="aud-compose"
            style={{
              height: 8,
              borderRadius: 4,
              background: 'var(--surface-subtle)',
              border: pending ? '1px solid var(--warning-border)' : '1px solid var(--border)',
            }}
          />
        ) : (
          <StackedBar row={row} columns={columns} shadeByKey={shadeByKey} total={total} />
        )}
      </td>
    </tr>
  );
}

// Thin share-of-total bar under each value (the "progress bar" in every column).
function ShareBar({ pct, shade, remainder }: { pct: number; shade: string; remainder: boolean }) {
  const w = Math.max(0, Math.min(100, pct));
  return (
    <div
      title={formatPercent(pct, { decimals: 1 })}
      style={{ height: 4, borderRadius: 2, background: 'var(--surface-subtle)', marginTop: 5, overflow: 'hidden' }}
    >
      <div
        style={{
          height: '100%',
          width: `${w}%`,
          background: remainder ? REMAINDER_HATCH : shade,
          borderRadius: 2,
        }}
      />
    </div>
  );
}

// The whole mix in one stacked bar — every column laid left→right by its share,
// remainder hatched. This is where "Non-ASC in the stacked bar" lives.
function StackedBar({
  row,
  columns,
  shadeByKey,
  total,
}: {
  row: AudienceRow;
  columns: AudienceColumn[];
  shadeByKey: Map<string, string>;
  total: number;
}) {
  return (
    <div
      className="aud-compose"
      style={{
        display: 'flex',
        height: 8,
        borderRadius: 4,
        overflow: 'hidden',
        border: '1px solid var(--border)',
        background: 'var(--surface-subtle)',
      }}
    >
      {columns.map((col) => {
        const value = row.segments[col.key] ?? 0;
        const pct = total > 0 ? (value / total) * 100 : 0;
        if (pct <= 0) return null;
        const shade = shadeByKey.get(col.key)!;
        return (
          <div
            key={col.key}
            title={`${col.label}: ${formatPercent(pct, { decimals: 1 })}`}
            style={{ width: `${pct}%`, background: col.kind === 'remainder' ? REMAINDER_HATCH : shade }}
          />
        );
      })}
    </div>
  );
}
