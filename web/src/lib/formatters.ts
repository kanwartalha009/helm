// Money + percent + delta formatters. Keep these pure and synchronous —
// the dashboard table can call them thousands of times per render.

export function formatMoney(
  value: number | null | undefined,
  currency: string = 'USD',
  opts: { compact?: boolean; whole?: boolean } = {}
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  const formatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    // Show cents in standard notation (Bosco, 2026-06-21). Compact ($1.2K) and
    // `whole` (clean report hero numbers) drop the cents.
    minimumFractionDigits: opts.compact || opts.whole ? 0 : 2,
    maximumFractionDigits: opts.whole ? 0 : 2,
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

// Relative time — "2 hours ago", "3 days ago". Null/invalid → "—". Used for
// last-sync columns where an exact timestamp is noise.
export function timeAgo(iso: string | null | undefined): string {
  if (!iso) return '—';
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '—';
  const mins = Math.round((Date.now() - then) / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins} min ago`;
  const hours = Math.round(mins / 60);
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
  const days = Math.round(hours / 24);
  if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`;
  const months = Math.round(days / 30);
  if (months < 12) return `${months} month${months === 1 ? '' : 's'} ago`;
  return `${Math.round(months / 12)} year${Math.round(months / 12) === 1 ? '' : 's'} ago`;
}

export type DeltaDirection = 'up' | 'down' | 'flat';

export function deltaDirection(value: number | null): DeltaDirection {
  if (value === null) return 'flat';
  if (value > 0.05) return 'up';
  if (value < -0.05) return 'down';
  return 'flat';
}
