import { formatMoney, formatRoas } from '@/lib/formatters';
import { DeltaChip, Sparkline } from './charts';

export interface TileValue {
  value: number | string | null;
  compare?: number | string | null;
  deltaPct?: number | null;
  format?: 'money' | 'ratio' | 'count' | 'pct';
}

function formatValue(v: number | string | null | undefined, format?: TileValue['format'], currency?: string): string {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'string') return v;
  switch (format) {
    case 'money':
      return formatMoney(v, currency, { whole: true });
    case 'ratio':
      return formatRoas(v);
    case 'pct':
      return `${v.toFixed(1)}%`;
    default:
      return v.toLocaleString();
  }
}

/** REV2 R4 — "Each tile: value, compare delta (arrow + %), 12-month sparkline." */
export function StatTile({
  label,
  tile,
  currency,
  sparkline,
  benchmarkLabel,
}: {
  label: string;
  tile: TileValue;
  currency?: string;
  sparkline?: (number | null)[];
  benchmarkLabel?: string;
}) {
  return (
    <div
      style={{
        border: '1px solid var(--border, #E7E9F0)',
        borderRadius: 10,
        padding: '14px 16px',
        display: 'flex',
        flexDirection: 'column',
        gap: 6,
        minWidth: 140,
        flex: '1 1 160px',
      }}
    >
      <span className="muted text-sm" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.4 }}>
        {label}
      </span>
      <span style={{ fontSize: 22, fontWeight: 650, lineHeight: 1.1 }}>{formatValue(tile.value, tile.format, currency)}</span>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
        <DeltaChip pct={tile.deltaPct} />
        {sparkline && sparkline.length > 1 && <Sparkline values={sparkline} width={72} height={22} />}
      </div>
      {benchmarkLabel && (
        <span className="muted" style={{ fontSize: 10 }}>
          {benchmarkLabel}
        </span>
      )}
    </div>
  );
}

/** A tile the backend explicitly marked unavailable — greyed out with the honest reason, never hidden. */
export function UnavailableTile({ label, reason }: { label: string; reason: string }) {
  return (
    <div
      style={{
        border: '1px dashed var(--border, #E7E9F0)',
        borderRadius: 10,
        padding: '14px 16px',
        display: 'flex',
        flexDirection: 'column',
        gap: 6,
        minWidth: 140,
        flex: '1 1 160px',
        opacity: 0.55,
      }}
      title={reason}
    >
      <span className="muted text-sm" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.4 }}>
        {label}
      </span>
      <span style={{ fontSize: 22, fontWeight: 650 }}>—</span>
      <span className="muted" style={{ fontSize: 10 }}>
        Not built yet
      </span>
    </div>
  );
}
