import type { InventorySessions, SessionSplit, TrafficType } from '@/types/inventory';

/**
 * Sessions by traffic type (Bosco item B) — the store-level strip and the per-row split bar.
 *
 * Three rules this file exists to hold:
 *
 *  1. **Five types.** Paid / Direct / Organic / Unknown / Unattributed — matching Shopify's own
 *     report, which is what Bosco reads. `unattributed` is 0.0001% of traffic (7 sessions in a
 *     year on a 6.9M-session store) and is invisible in a 30-day sample; it is here because a
 *     rare bucket is not an absent one, and dropping it would break the invariant that the
 *     parts sum to the store's own total.
 *
 *  2. **Landing-page attribution is a real limitation, so we say it out loud.** A visitor who
 *     lands on the homepage and then browses to a product is counted under Store-wide, NOT
 *     under that product. Measured on a live store, ~51% of sessions land somewhere other than
 *     a product page — that is half the traffic, not a rounding error, so the store-wide row is
 *     shown rather than quietly dropped.
 *
 *  3. **An unreconciled window renders "—".** Never a short sum: a 30-day window holding 12
 *     synced days would under-report every product by ~60% while looking perfectly precise.
 */

/** The canonical order + the single source of the type list. Exported so aggregation code
 *  (e.g. the collection blend on the Inventory page) can never miss a bucket by hand. */
export const TRAFFIC_TYPES: TrafficType[] = ['paid', 'direct', 'organic', 'unknown', 'unattributed'];

export const EMPTY_SPLIT: SessionSplit = {
  paid: 0, direct: 0, organic: 0, unknown: 0, unattributed: 0,
};

const TYPES = TRAFFIC_TYPES;

const LABEL: Record<TrafficType, string> = {
  paid: 'Paid',
  direct: 'Direct',
  organic: 'Organic',
  unknown: 'Unknown',
  unattributed: 'Unattributed',
};

// Distinct hues, not a gradient — these are categories, not a scale.
const COLOR: Record<TrafficType, string> = {
  paid: 'var(--accent, #4f46e5)',
  direct: 'var(--text-muted, #6b7280)',
  organic: 'var(--success, #1f6f5c)',
  unknown: 'var(--border-strong, #cbd5e1)',
  unattributed: 'var(--warning, #9a6700)',
};

const fmt = (n: number) => n.toLocaleString();

/** The store-level strip Bosco screenshotted: the four types + the store-wide honesty row. */
export function SessionTrafficStrip({ s, windowTo }: { s: InventorySessions; windowTo: string }) {
  if (!s.complete || !s.byType || s.total === null) {
    // Not reconciled → say what's missing and how to fix it. Never a number.
    const missing = s.windowDays - s.completeDays;
    return (
      <div
        className="mb-12"
        style={{
          fontSize: 12,
          color: 'var(--warning)',
          border: '1px solid var(--border)',
          borderRadius: 8,
          padding: '10px 12px',
        }}
      >
        Sessions by traffic type — <strong>not shown for this window</strong>. {missing} of {s.windowDays} day
        {s.windowDays === 1 ? '' : 's'} {missing === 1 ? 'is' : 'are'} missing or did not reconcile against Shopify’s
        own store total{s.through ? `; sessions are synced through ${s.through}` : ' and nothing is synced yet'}.
        Showing a partial sum here would under-report every product while looking exact — so it shows nothing.
        Run <code>php artisan shopify:backfill-session-traffic</code> to fill the gap.
      </div>
    );
  }

  const total = s.total;
  const productTotal = s.productTotal ?? 0;
  const productPct = total > 0 ? Math.round((productTotal / total) * 100) : 0;

  return (
    <div
      className="mb-12"
      style={{ border: '1px solid var(--border)', borderRadius: 8, padding: '12px 14px' }}
    >
      <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 12, marginBottom: 10 }}>
        <span style={{ fontSize: 13, fontWeight: 600 }}>Sessions by traffic type</span>
        <span className="muted" style={{ fontSize: 12 }}>
          {fmt(total)} sessions · through {s.through ?? windowTo}
        </span>
      </div>

      <SplitBar split={s.byType} total={total} height={8} />

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 14, marginTop: 10 }}>
        {TYPES.map((t) => {
          const n = s.byType![t];
          const pct = total > 0 ? Math.round((n / total) * 100) : 0;
          return (
            <span key={t} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
              <i style={{ width: 8, height: 8, borderRadius: 2, background: COLOR[t], display: 'inline-block' }} />
              <span>{LABEL[t]}</span>
              <span className="muted">{fmt(n)} · {pct}%</span>
            </span>
          );
        })}
      </div>

      {/* The limitation, stated rather than hidden. */}
      <div className="muted" style={{ fontSize: 12, marginTop: 10, lineHeight: 1.5 }}>
        {fmt(productTotal)} of these ({productPct}%) landed directly on a product page — the rest landed on the
        homepage, a collection, search or checkout and are counted under <strong>Store-wide</strong>, not against a
        product. Sessions are attributed by <strong>landing page</strong>: someone who arrives on the homepage and
        then views a product is not counted for that product.
      </div>
    </div>
  );
}

/** The per-row cell: total + a mini stacked bar. "—" when the window isn't reconciled. */
export function SessionCell({ total, split }: { total: number | null | undefined; split: SessionSplit | null | undefined }) {
  if (total === null || total === undefined || !split) {
    return <span className="muted">—</span>;
  }

  return (
    <div style={{ minWidth: 92 }} title={TYPES.map((t) => `${LABEL[t]}: ${fmt(split[t])}`).join('\n')}>
      <div style={{ fontVariantNumeric: 'tabular-nums' }}>{fmt(total)}</div>
      {total > 0 && <SplitBar split={split} total={total} height={4} />}
    </div>
  );
}

function SplitBar({ split, total, height }: { split: SessionSplit; total: number; height: number }) {
  if (total <= 0) return null;

  return (
    <div
      style={{
        display: 'flex',
        height,
        borderRadius: height / 2,
        overflow: 'hidden',
        background: 'var(--surface-subtle)',
        marginTop: 4,
      }}
    >
      {TYPES.map((t) => {
        const pct = (split[t] / total) * 100;
        if (pct <= 0) return null;
        return <span key={t} style={{ width: `${pct}%`, background: COLOR[t] }} />;
      })}
    </div>
  );
}
