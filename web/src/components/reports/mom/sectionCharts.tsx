import type { CSSProperties, ReactNode } from 'react';
import { formatMoney } from '@/lib/formatters';
import { DonutChart, RankedBarChart, StackedBar100, TrendLineChart } from './charts';
import { StatTile, UnavailableTile } from './StatTile';

/**
 * Shared heading tokens so every metric header across the report reads as ONE
 * system (design-system principle: consistency over creativity). This is the
 * exact treatment StatTile uses for the executive tiles, lifted to a constant
 * so a new section/component can't quietly drift from it:
 *   EYEBROW      — a small uppercase muted label that sits directly OVER a
 *                  headline number (Total revenue, New customer sales…).
 *   METRIC_VALUE — that headline number (22px / 650, matching the tiles).
 * A chart CAPTION (a label DESCRIBING a chart, e.g. "Revenue & spend, 2026")
 * stays plain `muted text-sm` — captions and eyebrows are deliberately
 * different so the eye can tell "this labels a chart" from "this labels a KPI".
 */
const EYEBROW: CSSProperties = { fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.4, fontWeight: 600 };
const METRIC_VALUE: CSSProperties = { fontSize: 22, fontWeight: 650, lineHeight: 1.1 };

/**
 * REV2 R1 (monthly-report-v2-mom.md) — "Every table section gets a chart
 * twin: S1 -> monthly trend lines... S4 tiers -> stacked area... S5/S6
 * countries -> ranked bar... S7/S8 -> donut + bars w/ YoY arrows... S13-S16 ->
 * donut/stacked bars."
 *
 * This registry maps a section KEY to a chart-twin renderer built from that
 * section's OWN actual payload shape (verified against each Sections/*.php
 * file, not guessed). A key with no entry here falls back to
 * MomSectionCard's generic table-only render — an honest "no chart twin yet"
 * rather than a fabricated one. Sections intentionally NOT chart-twinned:
 * S0 (a checklist, not a metric), S19 (free text). (S3 "New vs returning
 * evolution" was retired 2026-07-15 — its split now lives in the S-EX tiles.)
 */
export const SECTION_CHART_RENDERERS: Record<string, (payload: any, currency: string) => ReactNode> = {
  // REV2 R4 — the full executive stat-tile grid. Every tile the backend
  // supplies renders in spec order; a tile the backend marks `unavailable`
  // renders greyed with its reason; a tile it omits entirely (e.g. email when
  // Klaviyo isn't connected — Kanwar 2026-07-15) simply doesn't appear.
  // Data-driven off the payload's own `format`, so new tiles need no frontend
  // change beyond this order list.
  'S-EX': (p, currency) => {
    const tiles: Record<string, any> = p.tiles ?? {};
    const unavailable: Record<string, string> = p.unavailable ?? {};
    const ORDER: { key: string; label: string }[] = [
      { key: 'revenue', label: 'Revenue' },
      { key: 'adSpend', label: 'Ad spend' },
      { key: 'mer', label: 'MER' },
      { key: 'blendedRoas', label: 'Blended ROAS' },
      { key: 'aov', label: 'AOV' },
      { key: 'orders', label: 'Orders' },
      // New vs returning split (Kanwar, 2026-07-15) — the standalone S3 section
      // was retired; the tile value is the NEW-customer share and the RETURNING
      // share rides underneath (backend `returningPct`).
      { key: 'newVsReturningPct', label: 'New vs returning' },
      { key: 'cac', label: 'CAC' },
      { key: 'conversionRate', label: 'Conversion rate' },
      { key: 'sessions', label: 'Sessions' },
      { key: 'emailRevenue', label: 'Email revenue' },
    ];
    return (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        {/* Goals vs actual, moved into the executive overview (Kanwar,
            2026-07-15). Rendered as the first cards — revenue/ROAS vs target —
            only when the backend supplies goals (a target is set). */}
        <GoalCardsRow goals={p.goals} currency={currency} />
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
          {ORDER.map(({ key, label }) => {
            if (tiles[key]) {
              const t = tiles[key];
              const benchmarkLabel =
                key === 'newVsReturningPct' && t.returningPct != null ? `Returning ${Number(t.returningPct).toFixed(1)}%` : undefined;
              return <StatTile key={key} label={label} tile={t} currency={currency} benchmarkLabel={benchmarkLabel} />;
            }
            if (unavailable[key]) return <UnavailableTile key={key} label={label} reason={unavailable[key]} />;
            return null;
          })}
        </div>
      </div>
    );
  },

  'S-GOALS': (p, currency) => {
    if (!p.revenue && !p.roas) return null;
    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 16 }}>
        {p.revenue && (
          <GoalBar label="Revenue vs target" actual={p.revenue.actual} target={p.revenue.target} pct={p.revenue.pctOfTarget} hit={p.revenue.goalHit} currency={currency} />
        )}
        {p.roas && (
          <GoalBar label="ROAS vs target" actual={p.roas.actual} target={p.roas.target} pct={p.roas.actual && p.roas.target ? (p.roas.actual / p.roas.target) * 100 : null} hit={p.roas.goalHit} suffix="x" />
        )}
      </div>
    );
  },

  // Revenue & spend and Blended ROAS side by side in ONE row (Kanwar,
  // 2026-07-15), each with a legend marking which line is which metric.
  S1: (p, currency) => {
    const rows: any[] = p.currentYearRows ?? [];
    const ok = rows.filter((r) => r.status === 'ok');
    const labels = ok.map((r) => r.label?.slice(0, 3) ?? r.month);
    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 20 }}>
        <div style={{ flex: '1 1 45%', minWidth: 280 }}>
          <div className="muted text-sm" style={{ marginBottom: 4 }}>Revenue &amp; spend, {p.reportYear}</div>
          <TrendLineChart
            labels={labels}
            series={ok.map((r) => r.revenue)}
            compareSeries={ok.map((r) => r.spend)}
            valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
            seriesLabel="Revenue"
            compareLabel="Ad spend"
            height={150}
          />
        </div>
        <div style={{ flex: '1 1 45%', minWidth: 280 }}>
          <div className="muted text-sm" style={{ marginBottom: 4 }}>Blended ROAS, {p.reportYear}</div>
          <TrendLineChart labels={labels} series={ok.map((r) => r.roas)} valueFormatter={(n) => `${n.toFixed(1)}x`} seriesLabel="Blended ROAS" height={150} />
        </div>
      </div>
    );
  },

  // Total sales evolution — total revenue at the top, x-axis = days of the
  // month, y-axis = sales (Kanwar, 2026-07-15). Below it, the MODELED new-vs-
  // returning sales split (Shopify can't split sales by customer type; see the
  // backend docblock — this uses v1's new × AOV estimate).
  S2: (p, currency) => {
    const series: { day: number; revenue: number }[] = p.series ?? [];
    const compare: { day: number; revenue: number }[] | null = p.compareSeries ?? null;
    // Thin the day labels so a 30-day month doesn't crowd: day 1, then every
    // 5th, then the last day.
    const dayLabels = series.map((s, i) =>
      i === 0 || i === series.length - 1 || s.day % 5 === 0 ? String(s.day) : '',
    );
    return (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        <div>
          <div className="muted" style={EYEBROW}>Total revenue</div>
          <div style={METRIC_VALUE}>{formatMoney(p.total, currency, { whole: true })}</div>
        </div>
        <TrendLineChart
          labels={dayLabels}
          series={series.map((s) => s.revenue)}
          compareSeries={compare ? compare.map((s) => s.revenue) : null}
          valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
          seriesLabel={monthName(p.month) ?? 'This month'}
          compareLabel={compare ? monthName(p.compareMonth) ?? 'Comparison' : undefined}
          height={170}
        />
        {p.customerSalesSplit && (
          <ModeledCustomerSplit split={p.customerSalesSplit} daily={p.customerSalesDaily ?? null} currency={currency} />
        )}
      </div>
    );
  },

  S4: (p, currency) => {
    const rows: any[] = p.rows ?? [];
    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'flex-start' }}>
        <DonutChart rows={rows.map((r) => ({ label: r.label, value: r.revenue, color: r.color }))} />
        <div style={{ flex: '1 1 220px' }}>
          <RankedBarChart
            rows={rows.map((r) => ({ label: r.label, value: r.revenue, deltaPct: r.deltaMoMPct, color: r.color }))}
            valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
          />
        </div>
      </div>
    );
  },

  S5: (p, currency) => (
    <RankedBarChart
      rows={(p.rows ?? []).slice(0, 12).map((r: any) => ({ label: r.label, value: r.revenue, deltaPct: r.deltaPct }))}
      valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
    />
  ),

  S6: (p) => (
    <RankedBarChart
      rows={(p.rows ?? []).slice(0, 12).map((r: any) => ({ label: r.label, value: r.roas, deltaPct: r.deltaPct }))}
      valueFormatter={(n) => `${n.toFixed(2)}x`}
    />
  ),

  S7: (p, currency) => <ProductMixCharts p={p} currency={currency} mixLabel="Revenue mix by product type · 100% stacked" />,

  S8: (p, currency) => <ProductMixCharts p={p} currency={currency} mixLabel="Revenue mix by product · 100% stacked" />,

  S9: (p) => {
    const series: { day: number; sessions: number; purchase: number }[] = p.dailySessions ?? [];
    return <TrendLineChart labels={series.map((s) => String(s.day))} series={series.map((s) => s.sessions)} />;
  },

  S10: (p) => (
    <RankedBarChart rows={(p.rows ?? []).slice(0, 12).map((r: any) => ({ label: r.label, value: r.sessions }))} />
  ),

  S11: (p) => (
    <RankedBarChart rows={(p.rows ?? []).slice(0, 12).map((r: any) => ({ label: r.label, value: r.sessions }))} />
  ),

  S12: (p) => {
    const series: { day: number; revenue: number }[] = p.series ?? [];
    return <TrendLineChart labels={series.map((s) => String(s.day))} series={series.map((s) => s.revenue)} />;
  },

  S13: (p) => <DonutChart rows={(p.segments ?? []).map((s: any) => ({ label: s.label, value: s.spend }))} />,

  S14: (p, currency) => (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'flex-start' }}>
      <DonutChart rows={(p.rows ?? []).slice(0, 6).map((r: any) => ({ label: r.label, value: r.spend }))} />
      <div style={{ flex: '1 1 220px' }}>
        <RankedBarChart
          rows={(p.rows ?? []).slice(0, 8).map((r: any) => ({ label: r.label, value: r.spend }))}
          valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
        />
      </div>
    </div>
  ),

  S15: (p) => (
    <DonutChart
      rows={[
        { label: 'Male', value: p.male?.spend ?? 0, color: '#3B5BFB' },
        { label: 'Female', value: p.female?.spend ?? 0, color: '#EC4899' },
      ]}
    />
  ),

  S17: (p, currency) => (
    <RankedBarChart
      rows={(p.rows ?? [])
        .filter((r: any) => !r.unattributed)
        .slice(0, 10)
        .map((r: any) => ({ label: r.title ?? r.handle, value: r.spend }))}
      valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
    />
  ),

  // M5 addendum — S16 unblocked this pass (see MomSectionRegistry); donut of
  // awareness spend by country, matching R1's "S13-S16 -> donut/stacked bars".
  S16: (p, currency) => (
    <RankedBarChart
      rows={(p.rows ?? []).slice(0, 10).map((r: any) => ({ label: r.label, value: r.spend }))}
      valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
    />
  ),

  // M5 addendum — S18 Klaviyo attribution: flow vs campaign split.
  S18: (p) => (
    <DonutChart
      rows={[
        { label: 'Flow', value: p.splits?.flow?.revenue ?? 0, color: '#3B5BFB' },
        { label: 'Campaign', value: p.splits?.campaign?.revenue ?? 0, color: '#F59E0B' },
      ]}
    />
  ),
};

/**
 * S7/S8 product charts (Kanwar, 2026-07-17 — item 6): a 100%-stacked revenue-mix
 * bar over the month window (by product for S8, by product type for S7), on top
 * of the existing donut + ranked-bar twins. The stacked bar reads each product's
 * SHARE of the shown mix per month, normalised to 100% — the "revenue by product
 * stacked 100%" reference. Renders only when the section carries a month series;
 * in custom-range mode the section collapses to a table instead (no monthly bars).
 */
function ProductMixCharts({ p, currency, mixLabel }: { p: any; currency: string; mixLabel: string }) {
  const rows: any[] = p.rows ?? [];
  const labels: string[] = p.monthLabels ?? [];
  const canStack = labels.length > 1 && rows.some((r) => Array.isArray(r.monthly));
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      {canStack && (
        <div>
          <div className="muted text-sm" style={{ marginBottom: 4 }}>{mixLabel}</div>
          <StackedBar100
            labels={labels}
            series={rows.map((r) => ({ label: r.label, values: r.monthly ?? [] }))}
          />
        </div>
      )}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'flex-start' }}>
        <DonutChart rows={rows.slice(0, 6).map((r) => ({ label: r.label, value: r.revenue ?? r.value ?? 0 }))} />
        <div style={{ flex: '1 1 220px' }}>
          <RankedBarChart
            rows={rows.slice(0, 8).map((r) => ({ label: r.label, value: r.revenue ?? r.value ?? 0, deltaPct: r.deltaYoYPct ?? r.deltaPct }))}
            valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
          />
        </div>
      </div>
    </div>
  );
}

function fmt(v: number | null, currency: string | undefined, suffix: string | undefined): string {
  if (v === null) return '—';
  return suffix ? `${v.toFixed(2)}${suffix}` : formatMoney(v, currency, { whole: true });
}

/** 'YYYY-MM' → 'MMM YYYY' (e.g. '2026-05' → 'May 2026'); null-safe. */
function monthName(ym: string | null | undefined): string | null {
  if (!ym) return null;
  const [y, m] = ym.split('-').map(Number);
  if (!y || !m) return null;
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return `${MONTHS[m - 1] ?? ym} ${y}`;
}

/**
 * Modeled new-vs-returning sales beneath S2's Total sales chart (Kanwar,
 * 2026-07-15). Amounts at the top, then TWO SEPARATE graphs — one for New,
 * one for Returning — each across the DAYS of the month (same x-axis as the
 * sales line) but on its OWN independent y-axis, so the smaller "returning"
 * series is readable instead of flattening against the larger one on a shared
 * scale (Kanwar: "make 2 separate graphs so preview will be easy to see
 * numbers against y-axis"). Both use the app's blue accent for consistency
 * with the sales / revenue charts. Shopify can't split sales by customer type,
 * so each day's revenue is allocated by the month's modeled new-share (v1's
 * new × AOV basis), clearly marked "Modeled".
 */
function ModeledCustomerSplit({
  split,
  daily,
  currency,
}: {
  split: {
    method?: string;
    new: { customers: number; sales: number; pct: number | null };
    returning: { customers: number; sales: number; pct: number | null };
  };
  daily: { day: number; new: number; returning: number }[] | null;
  currency: string;
}) {
  const n = split.new;
  const r = split.returning;
  const BLUE = '#3B5BFB'; // app accent — same as the sales / revenue charts
  const hasDaily = daily != null && daily.length > 0;
  // Same day-label thinning as the sales chart above: day 1, every 5th, last.
  const dayLabels = (daily ?? []).map((d, i) =>
    i === 0 || i === (daily?.length ?? 0) - 1 || d.day % 5 === 0 ? String(d.day) : '',
  );

  return (
    <div style={{ borderTop: '1px solid var(--border)', paddingTop: 12 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 8 }}>
        <div className="muted text-sm">New vs returning customer sales</div>
        <span className="chip" style={{ fontSize: 9, textTransform: 'uppercase', letterSpacing: 0.4 }}>Modeled — estimate</span>
      </div>

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 20 }}>
        <div style={{ flex: '1 1 45%', minWidth: 280 }}>
          <SplitAmount label="New customer sales" sales={n.sales} customers={n.customers} pct={n.pct} currency={currency} />
          {hasDaily && (
            <TrendLineChart
              labels={dayLabels}
              series={daily!.map((d) => d.new)}
              valueFormatter={(v) => formatMoney(v, currency, { compact: true })}
              seriesLabel="New customer sales"
              seriesColor={BLUE}
              height={150}
            />
          )}
        </div>
        <div style={{ flex: '1 1 45%', minWidth: 280 }}>
          <SplitAmount label="Returning customer sales" sales={r.sales} customers={r.customers} pct={r.pct} currency={currency} />
          {hasDaily && (
            <TrendLineChart
              labels={dayLabels}
              series={daily!.map((d) => d.returning)}
              valueFormatter={(v) => formatMoney(v, currency, { compact: true })}
              seriesLabel="Returning customer sales"
              seriesColor={BLUE}
              height={150}
            />
          )}
        </div>
      </div>

      {split.method && (
        <div className="muted" style={{ fontSize: 10, marginTop: 6, fontStyle: 'italic' }}>{split.method}</div>
      )}
    </div>
  );
}

/**
 * A metric header (eyebrow + headline + subline) matching the executive-tile
 * treatment, so the New / Returning amounts read as the same kind of KPI the
 * rest of the report uses. No color swatch: both series now share the app blue,
 * so a swatch would carry no distinguishing meaning — the chart legend beneath
 * already ties the label to its line.
 */
function SplitAmount({
  label,
  sales,
  customers,
  pct,
  currency,
}: {
  label: string;
  sales: number;
  customers: number;
  pct: number | null;
  currency: string;
}) {
  return (
    <div style={{ marginBottom: 8 }}>
      <div className="muted" style={EYEBROW}>{label}</div>
      <div style={METRIC_VALUE}>~{formatMoney(sales, currency, { whole: true })}</div>
      <div className="muted" style={{ fontSize: 11 }}>
        {customers.toLocaleString()} {customers === 1 ? 'customer' : 'customers'}
        {pct != null ? ` · ${pct.toFixed(1)}%` : ''}
      </div>
    </div>
  );
}

/**
 * Goals vs actual, surfaced as executive-overview cards (Kanwar, 2026-07-15 —
 * "move it to Executive overview cards"). A "mixed" card: the tile eyebrow +
 * a big "% of target" value (matching the KPI tiles) AND the fuller goal detail
 * — a progress bar plus actual-vs-target and a hit badge. Renders nothing when
 * the backend omits goals (no target set), so it never fabricates a 0%-of-goal.
 */
function GoalCardsRow({ goals, currency }: { goals: any; currency: string }) {
  if (!goals || (!goals.revenue && !goals.roas)) return null;
  const goalCurrency: string = goals.currency ?? currency;
  const rev = goals.revenue;
  const roas = goals.roas;
  const roasPct = roas && roas.actual != null && roas.target ? (roas.actual / roas.target) * 100 : null;
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
      {rev && (
        <GoalTile
          label="Revenue vs target"
          pct={rev.pctOfTarget}
          hit={rev.goalHit}
          actualLabel={formatMoney(rev.actual, goalCurrency, { whole: true })}
          targetLabel={formatMoney(rev.target, goalCurrency, { whole: true })}
        />
      )}
      {roas && (
        <GoalTile
          label="ROAS vs target"
          pct={roasPct}
          hit={roas.goalHit}
          actualLabel={roas.actual != null ? `${roas.actual.toFixed(1)}x` : '—'}
          targetLabel={`${Number(roas.target).toFixed(1)}x`}
        />
      )}
    </div>
  );
}

function GoalTile({
  label,
  pct,
  hit,
  actualLabel,
  targetLabel,
}: {
  label: string;
  pct: number | null;
  hit: boolean;
  actualLabel: string;
  targetLabel: string;
}) {
  const clamped = pct == null ? 0 : Math.min(100, Math.max(0, pct));
  return (
    <div
      style={{
        border: '1px solid var(--border, #E7E9F0)',
        borderRadius: 10,
        padding: '14px 16px',
        display: 'flex',
        flexDirection: 'column',
        gap: 8,
        minWidth: 220,
        flex: '1 1 240px',
      }}
    >
      <span className="muted" style={EYEBROW}>{label}</span>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
        <span style={METRIC_VALUE}>{pct == null ? '—' : `${Math.round(pct)}%`}</span>
        {hit && <span style={{ color: '#1F6F5C', fontSize: 11, fontWeight: 600 }}>✓ hit</span>}
      </div>
      <div style={{ background: '#E7E9F0', borderRadius: 6, height: 8, overflow: 'hidden' }}>
        <div style={{ width: `${clamped}%`, height: '100%', background: hit ? '#1F6F5C' : '#3B5BFB' }} />
      </div>
      <span className="muted" style={{ fontSize: 11 }}>
        {actualLabel} of {targetLabel}
      </span>
    </div>
  );
}

function GoalBar({
  label,
  actual,
  target,
  pct,
  hit,
  currency,
  suffix,
}: {
  label: string;
  actual: number | null;
  target: number | null;
  pct: number | null;
  hit: boolean;
  currency?: string;
  suffix?: string;
}) {
  const clamped = pct === null ? 0 : Math.min(100, Math.max(0, pct));
  return (
    <div style={{ minWidth: 220 }}>
      <div className="muted text-sm" style={{ marginBottom: 4 }}>{label}</div>
      <div style={{ background: '#E7E9F0', borderRadius: 6, height: 14, position: 'relative', overflow: 'hidden' }}>
        <div style={{ width: `${clamped}%`, height: '100%', background: hit ? '#1F6F5C' : '#3B5BFB' }} />
      </div>
      <div style={{ fontSize: 12, marginTop: 4 }}>
        {fmt(actual, currency, suffix)}
        {target !== null && (
          <span className="muted"> {' '}of {fmt(target, currency, suffix)} target ({pct !== null ? pct.toFixed(0) : '—'}%)</span>
        )}
        {hit && <span style={{ color: '#1F6F5C', marginLeft: 6 }}>✓ hit</span>}
      </div>
    </div>
  );
}
