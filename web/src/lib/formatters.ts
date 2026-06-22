// Money + percent + delta formatters. Keep these pure and synchronous —
// the dashboard table can call them thousands of times per render.

export function formatMoney(
  value: number | null | undefined,
  currency: string = 'USD',
  opts: { compact?: boolean } = {}
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  const formatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    // Show cents in standard notation (Bosco, 2026-06-21). Compact ($1.2K)
    // keeps its own light precision.
    minimumFractionDigits: opts.compact ? 0 : 2,
    maximumFractionDigits: 2,
    notation: opts.compact ? 'compact' : 'standard',
  });
  return formatter.format(value);
}

export function formatNumber(
  value: number | null | undefined,
  opts: { decimals?: number } = {}
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: opts.decimals ?? 0,
    maximumFractionDigits: opts.decimals ?? 0,
  }).format(value);
}

export function formatPercent(
  value: number | null | undefined,
  opts: { decimals?: number; signed?: boolean } = {}
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  const decimals = opts.decimals ?? 1;
  const sign = opts.signed && value > 0 ? '+' : '';
  return `${sign}${value.toFixed(decimals)}%`;
}

export function formatRoas(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  return `${value.toFixed(2)}×`;
}

// Compute percent delta between two values, returning null when prior is 0
// (i.e. the spreadsheet's #DIV/0! case — we render "no data" instead).
export function pctDelta(
  current: number | null | undefined,
  prior: number | null | undefined
): number | null {
  if (
    current === null ||
    current === undefined ||
    prior === null ||
    prior === undefined ||
    prior === 0
  ) {
    return null;
  }
  return ((current - prior) / prior) * 100;
}

export type DeltaDirection = 'up' | 'down' | 'flat';

export function deltaDirection(value: number | null): DeltaDirection {
  if (value === null) return 'flat';
  if (value > 0.05) return 'up';
  if (value < -0.05) return 'down';
  return 'flat';
}
