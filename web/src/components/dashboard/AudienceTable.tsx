import { Fragment } from 'react';
import { Link } from 'react-router-dom';
import { Avatar, Card, Dot, Tag } from '@/components/ui';
import { formatMoney, formatPercent } from '@/lib/formatters';
import type { AudienceColumn, AudienceResponse, AudienceRow } from '@/types/domain';

/**
 * Audience view table — each brand's Meta spend split across the breakdown's
 * segments, with the Non-ASC (or Other) remainder as the trailing column.
 *
 * Design (Bosco update, 2026-06-30): categorical palette — New = blue, Engaged
 * = purple, Existing = orange, Unknown = gray, Sin breakdown / Other = light
 * gray. Each segment shows the € value AND its share % side by side, with a
 * colored share-of-total bar next to the percentage. The Total column (second,
 * right after the brand) carries the whole mix as one multi-color stacked bar.
 *
 * Missing data is never €0 (spec rule #9): a brand with spend but no breakdown
 * rows for this axis shows an amber "Breakdown pending" state instead of dumping
 * all spend into the remainder; a brand with zero spend renders muted.
 */

// Categorical segment palette, consumed in column order (New, Engaged, …).
// Bootstrap-style hues (Kanwar, 2026-06-30): green / yellow / orange lead, so on
// the audience axis New=green, Engaged=yellow, Existing=orange; blue/purple/teal
// extend it for the higher-cardinality axes (placement, country, …). Unknown and
// the remainder stay grey so colour = measured demand.
const SEGMENT_COLORS = ['#198754', '#ffc107', '#fd7e14', '#0d6efd', '#6f42c1', '#20c997'];
const UNKNOWN_SHADE = '#6c757d';
const REMAINDER_SHADE = '#adb5bd';
const REMAINDER_HATCH =
  'repeating-linear-gradient(45deg, #ced4da, #ced4da 3px, #adb5bd 3px, #adb5bd 6px)';

const PERIOD_LABEL: Record<string, string> = {
  last7: 'last 7 days',
  last30: 'last 30 days',
  mtd: 'month to date',
};

interface Props {
  data: AudienceResponse;
}

const isUnknown = (col: AudienceColumn): boolean =>
  col.kind !== 'remainder' &&
  (String(col.key).toLowerCase() === 'unknown' || col.label.toLowerCase().includes('unknown'));

function buildShades(columns: AudienceColumn[]): Map<string, string> {
  const shadeByKey = new Map<string, string>();
  let segIdx = 0;
  for (const col of columns) {
    if (col.kind === 'remainder') {
      shadeByKey.set(col.key, REMAINDER_SHADE);
    } else if (isUnknown(col)) {
      shadeByKey.set(col.key, UNKNOWN_SHADE);
    } else {
      shadeByKey.set(col.key, SEGMENT_COLORS[segIdx % SEGMENT_COLORS.length]);
      segIdx += 1;
    }
  }
  return shadeByKey;
}

export function AudienceTable({ data }: Props) {
  const { columns, rows, currency } = data;
  const shadeByKey = buildShades(columns);
  const hasRemainder = columns.some((c) => c.kind === 'remainder');

  return (
    <Card style={{ overflowX: 'auto' }}>
      <table className="data-table wide-table audience-table">
        <thead>
          <tr>
            <th className="brand-col group-head" rowSpan={2}>Brand</th>
            <th className="num group-head group-start" rowSpan={2} style={{ minWidth: 150 }}>Total</th>
            {columns.map((col) => (
              <th
                key={col.key}
                className="num group-head group-start"
                colSpan={2}
                style={{ textAlign: 'center', minWidth: 150 }}
              >
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, justifyContent: 'center' }}>
                  <ColorSwatch column={col} shade={shadeByKey.get(col.key)!} />
                  {col.label}
                </span>
              </th>
            ))}
          </tr>
          <tr>
            {columns.map((col) => (
              <Fragment key={col.key}>
                <th className="num group-head group-start" style={{ fontWeight: 400, opacity: 0.7 }}>€</th>
                <th className="num group-head" style={{ fontWeight: 400, opacity: 0.7 }}>%</th>
              </Fragment>
            ))}
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
        yesterday in each brand&rsquo;s timezone.{' '}
        {hasRemainder &&
          (data.breakdown === 'audience' ? (
            <>
              <strong>Non-ASC</strong> is spend outside Advantage+ Shopping campaigns — the account total minus the ASC
              segments — so the mix always reconciles to 100% of real spend.
            </>
          ) : (
            <>
              <strong>Other</strong> folds together the smaller segments beyond the top {SEGMENT_COLORS.length}.
            </>
          ))}
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
    case 'placement_platform':
      return 'placement (by platform)';
    case 'placement':
      return 'placement (by position)';
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
        borderRadius: 9,
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
  const segSpan = Math.max(columns.length * 2, 1);

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

      <td className="num group-start" style={{ verticalAlign: 'middle' }}>
        <div style={{ fontVariantNumeric: 'tabular-nums', fontWeight: 600 }}>
          {noSpend ? <span className="muted">—</span> : formatMoney(total, currency)}
        </div>
        {noSpend || pending ? (
          <div
            style={{
              height: 6,
              borderRadius: 3,
              marginTop: 5,
              background: 'var(--surface-subtle)',
              border: pending ? '1px solid var(--warning-border)' : '1px solid var(--border)',
            }}
          />
        ) : (
          <StackedBar row={row} columns={columns} shadeByKey={shadeByKey} total={total} />
        )}
      </td>

      {noSpend ? (
        <td className="num group-start" colSpan={segSpan} style={{ textAlign: 'center' }}>
          <span className="muted">No Meta spend this period</span>
        </td>
      ) : pending ? (
        <td className="num group-start" colSpan={segSpan} style={{ textAlign: 'center' }}>
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
          const dim = value <= 0;
          return (
            <Fragment key={col.key}>
              <td className="num group-start" style={{ fontVariantNumeric: 'tabular-nums', opacity: dim ? 0.45 : 1 }}>
                {value > 0 ? formatMoney(value, currency) : '—'}
              </td>
              <td className="num">
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, justifyContent: 'flex-end' }}>
                  <span style={{ fontVariantNumeric: 'tabular-nums', opacity: dim ? 0.45 : 1, minWidth: 42, textAlign: 'right' }}>
                    {formatPercent(pct, { decimals: 1 })}
                  </span>
                  <ShareBar pct={pct} shade={shade} remainder={col.kind === 'remainder'} />
                </div>
              </td>
            </Fragment>
          );
        })
      )}
    </tr>
  );
}

// Short colored share-of-total bar shown next to each percentage.
function ShareBar({ pct, shade, remainder }: { pct: number; shade: string; remainder: boolean }) {
  const w = Math.max(0, Math.min(100, pct));
  return (
    <div
      aria-hidden
      style={{ width: 48, height: 6, borderRadius: 3, background: 'var(--surface-subtle)', overflow: 'hidden', flex: '0 0 auto' }}
    >
      <div
        style={{
          height: '100%',
          width: `${w}%`,
          background: remainder ? REMAINDER_HATCH : shade,
          borderRadius: 3,
        }}
      />
    </div>
  );
}

// The whole mix in one stacked bar (lives under the Total) — every column laid
// left→right by its share, in its categorical color, remainder hatched.
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
        height: 6,
        borderRadius: 3,
        marginTop: 5,
        overflow: 'hidden',
        gap: 1,
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
