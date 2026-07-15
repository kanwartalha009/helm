import { formatMoney, formatRoas } from '@/lib/formatters';
import { HeatTable, type HeatColumn } from './HeatTable';
import { heatFromDeltaPct, heatVsBenchmark } from './heat';

/**
 * M5 addendum (Kanwar, 2026-07-15 — "basic tables of monthly report as we
 * have previously") — the color-coded table twin for every mom section that
 * had a real heat-table equivalent in v1's MonthlyReportDocument.tsx.
 * Mirrors this codebase's existing `sectionCharts.tsx` pattern exactly (a
 * `Record<sectionKey, renderer>` map), so `MomSectionCard`'s `SectionBody`
 * can look a section's table twin up the same way it already looks up its
 * chart twin, falling back to the generic table when no bespoke twin exists
 * (S9-S12 and the Meta breakdown sections are NOT covered this pass — see
 * the tracker entry for what's deferred).
 */
export const SECTION_TABLE_RENDERERS: Record<string, (payload: any, currency: string) => React.ReactNode> = {
  // S1 — the financial matrix (Kanwar's own reference table). Two stacked
  // HeatTables: current window, then the same window one year earlier —
  // matches the original PDF's "two stacked tables" shape whether that
  // window is the full year (default) or a trailing N months (M5 selector).
  S1: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const columns = (): HeatColumn<any>[] => [
      { key: 'label', label: 'Month', render: (r) => r.label ?? r.month },
      { key: 'orders', label: 'Orders', align: 'right', render: (r) => (r.orders ?? 0).toLocaleString() },
      { key: 'aov', label: 'AOV', align: 'right', render: (r) => money(r.aov) },
      { key: 'returnsPct', label: '% Returns', align: 'right', render: (r) => (r.returnsPct == null ? '—' : `${r.returnsPct.toFixed(1)}%`) },
      {
        key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue),
        gradeOf: (r) => heatFromDeltaPct(r.deltaRevenuePct),
      },
      { key: 'spend', label: 'Spend', align: 'right', render: (r) => money(r.spend) },
      { key: 'googleSharePct', label: 'Google %', align: 'right', render: (r) => (r.googleSharePct == null ? '—' : `${r.googleSharePct.toFixed(0)}%`) },
      {
        key: 'roas', label: 'ROAS', align: 'right', render: (r) => (r.roas == null ? '—' : formatRoas(r.roas)),
        gradeOf: (r) => heatFromDeltaPct(r.deltaRoasPct),
      },
      { key: 'deltaRevenuePct', label: 'Δ Revenue', align: 'right', render: (r) => (r.deltaRevenuePct == null ? '—' : `${r.deltaRevenuePct > 0 ? '+' : ''}${r.deltaRevenuePct.toFixed(1)}%`) },
      { key: 'deltaRoasPct', label: 'Δ ROAS', align: 'right', render: (r) => (r.deltaRoasPct == null ? '—' : `${r.deltaRoasPct > 0 ? '+' : ''}${r.deltaRoasPct.toFixed(1)}%`) },
    ];
    const okRows = (rows: any[]) => rows.filter((r) => r.status === 'ok');

    return (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
        <div>
          <div className="muted text-sm" style={{ marginBottom: 4 }}>{p.reportYear}{p.monthsWindow ? ` — last ${p.monthsWindow} months` : ''}</div>
          <HeatTable
            columns={columns()}
            rows={okRows(p.currentYearRows ?? [])}
            rowKey={(r) => r.month}
            title={`${p.reportYear} financial matrix`}
          />
        </div>
        <div>
          <div className="muted text-sm" style={{ marginBottom: 4 }}>{p.priorYear}{p.monthsWindow ? ` — same ${p.monthsWindow} months last year` : ''}</div>
          <HeatTable
            columns={columns()}
            rows={okRows(p.priorYearRows ?? [])}
            rowKey={(r) => r.month}
            title={`${p.priorYear} financial matrix`}
          />
        </div>
      </div>
    );
  },

  // S4 — market revenue by tier. One row per tier for the current month;
  // ΔMoM graded per-row against its own delta (there's no month-series here,
  // just one snapshot month, so column-wide grading on revenue/share reads
  // more usefully than a fabricated row-delta).
  S4: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows: any[] = p.rows ?? [];
    return (
      <HeatTable
        columns={[
          { key: 'label', label: 'Tier', render: (r) => r.label },
          { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
          { key: 'share', label: 'Share', align: 'right', render: (r) => (r.share == null ? '—' : `${r.share.toFixed(1)}%`) },
          { key: 'spend', label: 'Spend', align: 'right', render: (r) => money(r.spend) },
          { key: 'roas', label: 'ROAS', align: 'right', render: (r) => (r.roas == null ? '—' : formatRoas(r.roas)), heat: { mode: 'column', dir: 'high', value: (r) => r.roas } },
          { key: 'deltaMoMPct', label: 'Δ MoM', align: 'right', render: (r) => (r.deltaMoMPct == null ? '—' : `${r.deltaMoMPct > 0 ? '+' : ''}${r.deltaMoMPct.toFixed(1)}%`), gradeOf: (r) => heatFromDeltaPct(r.deltaMoMPct) },
        ]}
        rows={rows}
        rowKey={(r) => r.tierKey}
        title="Market revenue by tier"
      />
    );
  },

  // S5 — country revenue, ROAS-graded against the brand's own blended ROAS
  // (v1's roasHeat pattern) rather than a column-wide grade, since "good"
  // here means "above what this brand's spend actually returns", not just
  // "the best of this month's countries".
  S5: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows: any[] = p.rows ?? [];
    const blended = p.total?.spend > 0 ? p.total.revenue / p.total.spend : null;
    return (
      <HeatTable
        columns={[
          { key: 'label', label: 'Country', render: (r) => r.label },
          { key: 'tierLabel', label: 'Tier', render: (r) => r.tierLabel ?? '—' },
          { key: 'status', label: 'Status', render: (r) => r.status ?? '—' },
          { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue), gradeOf: (r) => heatFromDeltaPct(r.deltaPct) },
          { key: 'spendPct', label: 'Spend %', align: 'right', render: (r) => (r.spendPct == null ? '—' : `${r.spendPct.toFixed(1)}%`) },
          { key: 'roas', label: 'ROAS', align: 'right', render: (r) => (r.roas == null ? '—' : formatRoas(r.roas)), gradeOf: (r) => heatVsBenchmark(r.roas, blended) },
          { key: 'deltaPct', label: 'Δ Revenue', align: 'right', render: (r) => (r.deltaPct == null ? '—' : `${r.deltaPct > 0 ? '+' : ''}${r.deltaPct.toFixed(1)}%`) },
        ]}
        rows={rows}
        rowKey={(r) => r.iso2}
        title="Country revenue"
        footer={p.suggestedTitle}
      />
    );
  },

  // S6 — ROAS by country, same blended-benchmark grading as S5 (shares the
  // same underlying join, per the section's own docblock).
  S6: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows: any[] = p.rows ?? [];
    const totalSpend = rows.reduce((s, r) => s + (r.spend ?? 0), 0);
    const totalRevenue = rows.reduce((s, r) => s + (r.revenue ?? 0), 0);
    const blended = totalSpend > 0 ? totalRevenue / totalSpend : null;
    return (
      <HeatTable
        columns={[
          { key: 'label', label: 'Country', render: (r) => r.label },
          { key: 'roas', label: 'ROAS', align: 'right', render: (r) => formatRoas(r.roas), gradeOf: (r) => heatVsBenchmark(r.roas, blended) },
          { key: 'spend', label: 'Spend', align: 'right', render: (r) => money(r.spend) },
          { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue) },
          { key: 'deltaPct', label: 'Δ ROAS', align: 'right', render: (r) => (r.deltaPct == null ? '—' : `${r.deltaPct > 0 ? '+' : ''}${r.deltaPct.toFixed(1)}%`), gradeOf: (r) => heatFromDeltaPct(r.deltaPct) },
        ]}
        rows={rows}
        rowKey={(r) => r.iso2}
        title="ROAS by country"
      />
    );
  },

  // S7 — best categories, stock chip reused from the payload's own
  // lowStock flag (a presence check, not a real cover figure — see the
  // section's own docblock) rather than re-deriving anything client-side.
  S7: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows: any[] = p.rows ?? [];
    return (
      <HeatTable
        columns={[
          { key: 'label', label: 'Category', render: (r) => r.label },
          { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
          // share is stored as a 0-1 fraction (CommerceBreakdown::forDimension) — ×100 for display.
          { key: 'share', label: 'Share', align: 'right', render: (r) => (r.share == null ? '—' : `${(r.share * 100).toFixed(1)}%`) },
          { key: 'deltaPct', label: 'Δ vs compare', align: 'right', render: (r) => (r.deltaPct == null ? '—' : `${r.deltaPct > 0 ? '+' : ''}${r.deltaPct.toFixed(1)}%`), gradeOf: (r) => heatFromDeltaPct(r.deltaPct) },
          { key: 'stock', label: 'Stock', align: 'right', render: (r) => (r.stock == null ? '—' : r.stock.toLocaleString()), gradeOf: (r) => (r.lowStock ? 'r1' : '') },
        ]}
        rows={rows}
        rowKey={(r) => r.key ?? r.label}
        title="Best categories"
      />
    );
  },

  // S8 — best sellers, same stock-flag pattern as S7 but keyed on the
  // section's own precomputed `stockFlag` ('red' | null) rather than a
  // client-side threshold, since S8 uses a different LOW_STOCK_FLOOR than S7.
  S8: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows: any[] = p.rows ?? [];
    return (
      <HeatTable
        columns={[
          { key: 'label', label: 'Product', render: (r) => r.label },
          { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
          // share is stored as a 0-1 fraction (CommerceBreakdown::forDimension) — ×100 for display.
          { key: 'share', label: 'Share', align: 'right', render: (r) => (r.share == null ? '—' : `${(r.share * 100).toFixed(1)}%`) },
          { key: 'deltaPct', label: 'Δ vs compare', align: 'right', render: (r) => (r.deltaPct == null ? '—' : `${r.deltaPct > 0 ? '+' : ''}${r.deltaPct.toFixed(1)}%`), gradeOf: (r) => heatFromDeltaPct(r.deltaPct) },
          { key: 'stock', label: 'Stock', align: 'right', render: (r) => (r.stock == null ? '—' : r.stock.toLocaleString()), gradeOf: (r) => (r.stockFlag === 'red' ? 'r1' : '') },
        ]}
        rows={rows}
        rowKey={(r) => r.key ?? r.label}
        title="Best sellers"
      />
    );
  },
};

// S10/S11 — web funnel by country / by landing path. Identical row shape
// (SFunnelCountrySection and SFunnelLandingSection both build off the same
// shopify_funnel_daily aggregation), so one shared renderer covers both —
// CVR graded column-wide (v1's FunnelTable gradeCol pattern) since there's
// no fixed benchmark to grade a conversion rate against.
const funnelTable = (title: string) => (p: any) => {
  const rows: any[] = p.rows ?? [];
  const n = (v: number) => (v ?? 0).toLocaleString();
  return (
    <HeatTable
      columns={[
        { key: 'label', label: title === 'Web funnel by country' ? 'Country' : 'Landing page', render: (r) => r.label },
        { key: 'sessions', label: 'Sessions', align: 'right', render: (r) => n(r.sessions) },
        { key: 'cart', label: 'Add to cart', align: 'right', render: (r) => n(r.cart) },
        { key: 'checkout', label: 'Checkout', align: 'right', render: (r) => n(r.checkout) },
        { key: 'purchase', label: 'Purchase', align: 'right', render: (r) => n(r.purchase) },
        { key: 'cvr', label: 'CVR', align: 'right', render: (r) => (r.cvr == null ? '—' : `${r.cvr.toFixed(2)}%`), heat: { mode: 'column', dir: 'high', value: (r) => r.cvr } },
        { key: 'deltaPct', label: 'Δ CVR', align: 'right', render: (r) => (r.deltaPct == null ? '—' : `${r.deltaPct > 0 ? '+' : ''}${r.deltaPct.toFixed(1)}%`), gradeOf: (r) => heatFromDeltaPct(r.deltaPct) },
      ]}
      rows={rows}
      rowKey={(r) => r.key ?? r.label}
      title={title}
    />
  );
};

SECTION_TABLE_RENDERERS.S10 = funnelTable('Web funnel by country');
SECTION_TABLE_RENDERERS.S11 = funnelTable('Web funnel by landing path');
