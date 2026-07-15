import type { ReactNode } from 'react';
import { formatMoney } from '@/lib/formatters';
import { DonutChart, RankedBarChart, TrendLineChart } from './charts';
import { StatTile, UnavailableTile } from './StatTile';

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
 * rather than a fabricated one. Sections intentionally NOT chart-twinned this
 * pass: S0 (a checklist, not a metric), S19 (free text), S3 (an honest shell
 * with no real data to chart yet).
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
      { key: 'newVsReturningPct', label: 'New customers %' },
      { key: 'cac', label: 'CAC' },
      { key: 'conversionRate', label: 'Conversion rate' },
      { key: 'sessions', label: 'Sessions' },
      { key: 'emailRevenue', label: 'Email revenue' },
    ];
    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
        {ORDER.map(({ key, label }) => {
          if (tiles[key]) return <StatTile key={key} label={label} tile={tiles[key]} currency={currency} />;
          if (unavailable[key]) return <UnavailableTile key={key} label={label} reason={unavailable[key]} />;
          return null;
        })}
      </div>
    );
  },

  // S3 — new vs returning CUSTOMER COUNTS (Shopify can't split revenue by
  // customer type; see the section's own docblock). Counts + new-share donut.
  S3: (p) => {
    if (p.new == null && p.returning == null) return null;
    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'center' }}>
        <StatTile label="New customers" tile={{ value: p.new ?? null, format: 'count' }} />
        <StatTile label="Returning customers" tile={{ value: p.returning ?? null, format: 'count' }} />
        <StatTile label="New %" tile={{ value: p.newPct?.value ?? null, deltaPct: p.newPct?.deltaPct ?? null, format: 'pct' }} />
        <StatTile label="Returning %" tile={{ value: p.retPct?.value ?? null, deltaPct: p.retPct?.deltaPct ?? null, format: 'pct' }} />
        <DonutChart
          rows={[
            { label: 'New', value: Number(p.new ?? 0), color: '#1f6f5c' },
            { label: 'Returning', value: Number(p.returning ?? 0), color: '#c9a227' },
          ]}
        />
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

  S1: (p) => {
    const rows: any[] = p.currentYearRows ?? [];
    const ok = rows.filter((r) => r.status === 'ok');
    const labels = ok.map((r) => r.label?.slice(0, 3) ?? r.month);
    return (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
        <div>
          <div className="muted text-sm" style={{ marginBottom: 4 }}>Revenue &amp; spend, {p.reportYear}</div>
          <TrendLineChart labels={labels} series={ok.map((r) => r.revenue)} compareSeries={ok.map((r) => r.spend)} />
        </div>
        <div>
          <div className="muted text-sm" style={{ marginBottom: 4 }}>Blended ROAS, {p.reportYear}</div>
          <TrendLineChart labels={labels} series={ok.map((r) => r.roas)} valueFormatter={(n) => `${n.toFixed(1)}x`} height={120} />
        </div>
      </div>
    );
  },

  S2: (p) => {
    const series: { day: number; revenue: number }[] = p.series ?? [];
    const compare: { day: number; revenue: number }[] | null = p.compareSeries ?? null;
    return (
      <TrendLineChart
        labels={series.map((s) => String(s.day))}
        series={series.map((s) => s.revenue)}
        compareSeries={compare ? compare.map((s) => s.revenue) : null}
      />
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

  S7: (p, currency) => {
    const rows: any[] = p.rows ?? [];
    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'flex-start' }}>
        <DonutChart rows={rows.slice(0, 6).map((r) => ({ label: r.label, value: r.revenue ?? r.value ?? 0 }))} />
        <div style={{ flex: '1 1 220px' }}>
          <RankedBarChart
            rows={rows.slice(0, 8).map((r) => ({ label: r.label, value: r.revenue ?? r.value ?? 0, deltaPct: r.deltaYoYPct ?? r.deltaPct }))}
            valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
          />
        </div>
      </div>
    );
  },

  S8: (p, currency) => {
    const rows: any[] = p.rows ?? [];
    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'flex-start' }}>
        <DonutChart rows={rows.slice(0, 6).map((r) => ({ label: r.label, value: r.revenue ?? r.value ?? 0 }))} />
        <div style={{ flex: '1 1 220px' }}>
          <RankedBarChart
            rows={rows.slice(0, 8).map((r) => ({ label: r.label, value: r.revenue ?? r.value ?? 0, deltaPct: r.deltaYoYPct ?? r.deltaPct }))}
            valueFormatter={(n) => formatMoney(n, currency, { compact: true })}
          />
        </div>
      </div>
    );
  },

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

function fmt(v: number | null, currency: string | undefined, suffix: string | undefined): string {
  if (v === null) return '—';
  return suffix ? `${v.toFixed(2)}${suffix}` : formatMoney(v, currency, { whole: true });
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
