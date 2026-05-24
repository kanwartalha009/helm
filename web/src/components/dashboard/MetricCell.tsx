import { cn } from '@/lib/cn';
import { deltaDirection, formatPercent, pctDelta } from '@/lib/formatters';

interface MetricCellProps {
  current: string | null;
  prior: string | null;
  /** Numeric current / prior used to compute the % delta. */
  currentValue: number | null;
  priorValue: number | null;
  /** When true, render an absolute delta (e.g. +0.18) instead of percent. Used for ROAS. */
  absoluteDelta?: boolean;
}

export function MetricCell({ current, prior, currentValue, priorValue, absoluteDelta }: MetricCellProps) {
  if (current === null) return <span className="muted">—</span>;

  let deltaLabel: string;
  let direction: 'up' | 'down' | 'flat';

  if (absoluteDelta) {
    const diff = currentValue !== null && priorValue !== null ? currentValue - priorValue : null;
    if (diff === null) {
      deltaLabel = '—';
      direction = 'flat';
    } else {
      direction = diff > 0.005 ? 'up' : diff < -0.005 ? 'down' : 'flat';
      const sign = diff > 0 ? '+' : '';
      deltaLabel = `${sign}${diff.toFixed(2)}`;
    }
  } else {
    const pct = pctDelta(currentValue, priorValue);
    direction = deltaDirection(pct);
    deltaLabel = formatPercent(pct, { signed: true });
  }

  return (
    <div className="m">
      <div className="m-cur">{current}</div>
      <div className="m-prior">
        {prior ?? '—'} <span className={cn('m-delta', direction)}>{deltaLabel}</span>
      </div>
    </div>
  );
}
