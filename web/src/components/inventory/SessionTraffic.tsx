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

      {/* PAID vs ORGANIC first — the split Bosco actually acts on. Same two colours as the
          per-product cells, so the eye carries the meaning down the table. */}
      <PaidOrganicBar split={s.byType} total={total} />

      {/* The full five-type breakdown stays underneath: it's the honest detail, and it's how you
          see WHERE the organic half is coming from. Muted, because it's reference, not the headline. */}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 14, marginTop: 12 }}>
        {TYPES.map((t) => {
          const n = s.byType![t];
          const pct = total > 0 ? Math.round((n / total) * 100) : 0;
          return (
            <span key={t} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
              <i style={{ width: 8, height: 8, borderRadius: 2, background: COLOR[t], display: 'inline-block' }} />
              <span className="muted">{LABEL[t]}</span>
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

/* ---- Per-row cell: PAID vs ORGANIC (Bosco, 2026-07-13) ------------------- */

const PAID_COLOR    = '#2563EB';   // blue — bought
const ORGANIC_COLOR = '#16A34A';   // green — earned

/**
 * Collapse the five Shopify traffic types into the only split a media buyer acts on:
 * **paid** (traffic you bought) vs **organic** (traffic you earned).
 *
 * "Organic" here means everything that is not paid — organic search/social, direct, unknown and
 * unattributed. That is deliberate and it is the honest grouping: Shopify's `direct` and `unknown`
 * buckets are not *paid*, so lumping them with organic understates nothing and invents nothing.
 * Dropping them instead would silently shrink the denominator and inflate the paid share, which is
 * exactly the number Bosco would act on.
 */
function paidVsOrganic(split: SessionSplit): { paid: number; organic: number } {
  const paid = split.paid;
  const organic = split.direct + split.organic + split.unknown + split.unattributed;
  return { paid, organic };
}

/**
 * The per-row cell: a two-colour bar with both numbers and both percentages.
 *
 * Blue = paid, green = organic. Deliberately high-contrast rather than a subtle palette — this is
 * scanned down a column of 3,892 products, so it has to read at a glance.
 *
 * "—" when the window isn't reconciled. Never a 0-length bar, which would read as "no traffic"
 * rather than "we don't know".
 */
export function SessionCell({ total, split }: { total: number | null | undefined; split: SessionSplit | null | undefined }) {
  if (total === null || total === undefined || !split) {
    return <span className="muted">—</span>;
  }

  const { paid, organic } = paidVsOrganic(split);
  const paidPct = total > 0 ? Math.round((paid / total) * 100) : 0;
  const orgPct  = total > 0 ? 100 - paidPct : 0;   // complement, so the two always sum to 100

  // FIXED width, not minWidth. This cell holds a bar plus two nowrap labels, so with only a
  // minimum it grew to whatever its content wanted and dragged the whole table past its
  // container — the overflow you could see in the screenshot. A fixed box means the column is
  // predictable and the table's width is decided by the table, not by one long label.
  return (
    <div style={{ width: 190, textAlign: 'left' }}>
      <div style={{ fontVariantNumeric: 'tabular-nums', fontWeight: 600, fontSize: 13 }}>{fmt(total)}</div>

      {total > 0 ? (
        <>
          <div
            style={{
              display: 'flex',
              height: 7,
              width: '100%',
              borderRadius: 4,
              overflow: 'hidden',
              background: 'var(--surface-subtle)',
              margin: '4px 0 3px',
            }}
          >
            {paid > 0 && <span style={{ width: `${paidPct}%`, background: PAID_COLOR }} />}
            {organic > 0 && <span style={{ width: `${orgPct}%`, background: ORGANIC_COLOR }} />}
          </div>

          {/* Wraps to a second line on a narrow viewport instead of shoving the table sideways. */}
          <div style={{ display: 'flex', flexWrap: 'wrap', columnGap: 10, rowGap: 1, fontSize: 11 }}>
            <span style={{ color: PAID_COLOR, fontWeight: 500, whiteSpace: 'nowrap' }}>
              Paid {fmt(paid)} · {paidPct}%
            </span>
            <span style={{ color: ORGANIC_COLOR, fontWeight: 500, whiteSpace: 'nowrap' }}>
              Organic {fmt(organic)} · {orgPct}%
            </span>
          </div>
        </>
      ) : (
        // A covered window with genuinely zero landings. A real 0, not missing data — so we say
        // so in words rather than drawing an empty bar that looks like a rendering failure.
        <div className="muted" style={{ fontSize: 11, marginTop: 2 }}>no landings</div>
      )}
    </div>
  );
}

/** The headline bar: paid vs organic, big, with both numbers and both percentages. */
function PaidOrganicBar({ split, total }: { split: SessionSplit; total: number }) {
  const { paid, organic } = paidVsOrganic(split);
  const paidPct = total > 0 ? Math.round((paid / total) * 100) : 0;
  const orgPct  = total > 0 ? 100 - paidPct : 0;

  return (
    <>
      <div
        style={{
          display: 'flex',
          height: 12,
          borderRadius: 6,
          overflow: 'hidden',
          background: 'var(--surface-subtle)',
        }}
      >
        {paid > 0 && <span style={{ width: `${paidPct}%`, background: PAID_COLOR }} />}
        {organic > 0 && <span style={{ width: `${orgPct}%`, background: ORGANIC_COLOR }} />}
      </div>

      <div style={{ display: 'flex', gap: 20, marginTop: 8, fontSize: 13 }}>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7 }}>
          <i style={{ width: 10, height: 10, borderRadius: 3, background: PAID_COLOR, display: 'inline-block' }} />
          <strong>Paid</strong>
          <span style={{ fontVariantNumeric: 'tabular-nums' }}>{fmt(paid)}</span>
          <span className="muted">· {paidPct}%</span>
        </span>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7 }}>
          <i style={{ width: 10, height: 10, borderRadius: 3, background: ORGANIC_COLOR, display: 'inline-block' }} />
          <strong>Organic</strong>
          <span style={{ fontVariantNumeric: 'tabular-nums' }}>{fmt(organic)}</span>
          <span className="muted">· {orgPct}%</span>
        </span>
      </div>
    </>
  );
}

