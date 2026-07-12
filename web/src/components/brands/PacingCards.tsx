import { Card } from '@/components/ui';
import { useBrandTargets } from '@/hooks/useTargets';
import { formatMoney, formatRoas } from '@/lib/formatters';

const GREEN = 'var(--success, #1f6f5c)';
const RED   = 'var(--danger, #b3261e)';
const AMBER = 'var(--warning, #9a6700)';

/**
 * Goal pacing on the brand overview (Bosco spec §A.3).
 *
 * Two rules this component exists to honour:
 *
 *  1. **No goals → no cards.** Not an empty state, not a 0% bar — absent entirely. A 0%
 *     bar reads as failure; a brand with no goal set is not failing, it simply has no goal.
 *
 *  2. **Pacing counts only COMPLETE days.** "Day 12 of 31" means twelve days that have
 *     finished AND synced. If it counted today, every brand would read "behind" every
 *     morning as a pure artefact of the clock — a number that cries wolf daily is one
 *     people stop reading. Missing data → "—" and an amber note, never a fake 0.
 */
export function PacingCards({ slug }: { slug?: string }) {
  const { data } = useBrandTargets(slug);

  const p = data?.pacing;
  if (!p) return null;                       // no goal set → no cards at all

  const rev  = p.revenue;
  const roas = p.roas;

  if (!rev && !roas) return null;

  return (
    <div className="form-grid form-grid-2" style={{ marginBottom: 16 }}>
      {rev && <RevenueCard p={p} />}
      {roas && <RoasCard p={p} />}
    </div>
  );
}

function RevenueCard({ p }: { p: NonNullable<ReturnType<typeof useBrandTargets>['data']>['pacing'] }) {
  if (!p?.revenue) return null;
  const r = p.revenue;

  const pct      = r.target > 0 ? Math.min(100, Math.round((r.actual / r.target) * 100)) : 0;
  const unknown  = r.status === 'unknown';
  const onPace   = r.status === 'on_pace';
  const color    = unknown ? AMBER : onPace ? GREEN : RED;

  return (
    <Card style={{ padding: 16 }}>
      <div className="muted text-xs" style={{ marginBottom: 4 }}>Revenue MTD vs monthly target</div>

      <div style={{ fontSize: 20, fontWeight: 700 }}>
        {unknown ? '—' : formatMoney(r.actual, p.currency)}
        <span className="muted" style={{ fontSize: 14, fontWeight: 400 }}> / {formatMoney(r.target, p.currency)}</span>
      </div>

      {/* Never a fake bar: with nothing measured, there is nothing to draw. */}
      {!unknown && (
        <div style={{ height: 6, borderRadius: 3, background: 'var(--surface-subtle)', overflow: 'hidden', margin: '8px 0 6px' }}>
          <div style={{ width: `${pct}%`, height: '100%', background: color }} />
        </div>
      )}

      {unknown ? (
        <div className="text-xs" style={{ color: AMBER, lineHeight: 1.5 }}>
          No complete days synced this month yet — nothing to pace against. This is not a zero.
        </div>
      ) : (
        <div className="text-xs" style={{ lineHeight: 1.5 }}>
          <span style={{ color, fontWeight: 600 }}>
            {onPace ? 'on pace' : `behind by ${formatMoney(Math.abs(r.delta), p.currency)}`}
          </span>
          <span className="muted">
            {' · '}day {p.completeDays}/{p.daysInMonth}
            {p.neededPerDay !== null && p.remainingDays > 0 && (
              <> · needs {formatMoney(p.neededPerDay, p.currency)}/day to hit goal</>
            )}
          </span>
        </div>
      )}

      {p.isStandingDefault && (
        <div className="muted text-xs" style={{ marginTop: 4, opacity: 0.8 }}>Standing goal (applies every month)</div>
      )}
    </Card>
  );
}

function RoasCard({ p }: { p: NonNullable<ReturnType<typeof useBrandTargets>['data']>['pacing'] }) {
  if (!p?.roas) return null;
  const r = p.roas;

  // null actual = no ad spend this month → no ratio exists. "—", never 0×.
  const unknown = r.actual === null;
  const hit     = !unknown && r.actual! >= r.target;
  const color   = unknown ? AMBER : hit ? GREEN : RED;

  return (
    <Card style={{ padding: 16 }}>
      <div className="muted text-xs" style={{ marginBottom: 4 }}>ROAS vs target</div>

      <div style={{ fontSize: 20, fontWeight: 700, color: unknown ? undefined : color }}>
        {unknown ? '—' : formatRoas(r.actual)}
        <span className="muted" style={{ fontSize: 14, fontWeight: 400 }}> / {formatRoas(r.target)}</span>
      </div>

      <div className="text-xs" style={{ marginTop: 6, lineHeight: 1.5 }}>
        {unknown ? (
          <span style={{ color: AMBER }}>No ad spend recorded this month — there is no ratio to compute.</span>
        ) : hit ? (
          <span style={{ color: GREEN, fontWeight: 600 }}>✓ goal hit</span>
        ) : (
          <span style={{ color: RED, fontWeight: 600 }}>below target</span>
        )}
        <span className="muted"> · month to date, complete days only</span>
      </div>
    </Card>
  );
}
