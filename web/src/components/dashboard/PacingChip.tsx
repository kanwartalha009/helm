import { useBrandsPacing } from '@/hooks/useTargets';

const COLOR: Record<string, string> = {
  on_pace: 'var(--success, #1f6f5c)',
  behind:  'var(--danger, #b3261e)',
  over:    'var(--warning, #9a6700)',
  unknown: 'var(--text-muted)',
};

/**
 * Monthly pacing chip on the dashboard brand row (GO-2.1).
 *
 * Renders NOTHING for a brand with no target — a brand without a goal is not "behind",
 * it simply has no goal, and inventing one would be a wrong number.
 *
 * Reads a separate endpoint and looks the brand up by id, like QualityDot: the
 * dashboard's two engines sit behind the `helm:dashboard-parity` gate and pacing has
 * no business inside that blast radius.
 */
export function PacingChip({ brandId }: { brandId: number }) {
  const { data } = useBrandsPacing();
  const row = data?.rows.find((r) => r.brandId === brandId);

  if (!row || row.pctOfTarget === null) return null;

  const color = COLOR[row.status] ?? 'var(--text-muted)';
  const word =
    row.status === 'on_pace' ? 'on pace'
    : row.status === 'behind' ? 'behind'
    : row.status === 'over' ? 'over cap'
    : 'no data yet';

  return (
    <span
      title={`${row.month}: ${row.pctOfTarget}% of the revenue target, day ${row.completeDays} of ${row.daysInMonth} (complete days only) — ${word}.`}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 3, color }}
    >
      <span aria-hidden style={{ width: 6, height: 6, borderRadius: '50%', background: color, flexShrink: 0 }} />
      <span>{row.pctOfTarget}%</span>
    </span>
  );
}
