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
    const count = (v: number | null) => (v == null ? '—' : v.toLocaleString());
    const share = (v: number | null) => (v == null ? '—' : `${v.toFixed(0)}%`);
    const pct1 = (v: number | null) => (v == null ? '—' : `${v.toFixed(1)}%`);
    const delta = (v: number | null) => (v == null ? '—' : `${v > 0 ? '+' : ''}${v.toFixed(1)}%`);
    const columns = (): HeatColumn<any>[] => [
      { key: 'label', label: 'Month', render: (r) => r.label ?? r.month },
      { key: 'orders', label: 'Orders', align: 'right', render: (r) => (r.orders ?? 0).toLocaleString() },
      { key: 'aov', label: 'AOV', align: 'right', render: (r) => money(r.aov) },
      { key: 'returnsPct', label: '% Returns', align: 'right', render: (r) => pct1(r.returnsPct) },
      {
        key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue),
        gradeOf: (r) => heatFromDeltaPct(r.deltaRevenuePct),
      },
      { key: 'spend', label: 'Spend', align: 'right', render: (r) => money(r.spend) },
      { key: 'googleSharePct', label: 'Google %', align: 'right', render: (r) => share(r.googleSharePct) },
      { key: 'metaSharePct', label: 'Meta %', align: 'right', render: (r) => share(r.metaSharePct) },
      { key: 'tiktokSharePct', label: 'TikTok %', align: 'right', render: (r) => share(r.tiktokSharePct) },
      {
        key: 'roas', label: 'ROAS', align: 'right', render: (r) => (r.roas == null ? '—' : formatRoas(r.roas)),
        gradeOf: (r) => heatFromDeltaPct(r.deltaRoasPct),
      },
      // Customer split — real Shopify counts; '—' when the counts aren't available.
      { key: 'new', label: 'New', align: 'right', render: (r) => count(r.new) },
      { key: 'returning', label: 'Returning', align: 'right', render: (r) => count(r.returning) },
      { key: 'retPctCustomers', label: '% Ret', align: 'right', render: (r) => pct1(r.retPctCustomers) },
      { key: 'totalCustomers', label: 'Total', align: 'right', render: (r) => count(r.totalCustomers) },
      { key: 'cac', label: 'CAC', align: 'right', render: (r) => money(r.cac) },
      { key: 'roasNc', label: 'ROAS-nc*', align: 'right', render: (r) => (r.roasNc == null ? '—' : formatRoas(r.roasNc)) },
      { key: 'goalPct', label: 'Goal', align: 'right', render: (r) => delta(r.goalPct) },
      // YoY comparison columns (vs same month last year) — matches the reference.
      { key: 'captacionYoYPct', label: 'Captación', align: 'right', render: (r) => delta(r.captacionYoYPct), gradeOf: (r) => heatFromDeltaPct(r.captacionYoYPct) },
      { key: 'retentionYoYPct', label: 'Ret Δ', align: 'right', render: (r) => delta(r.retentionYoYPct), gradeOf: (r) => heatFromDeltaPct(r.retentionYoYPct) },
      { key: 'revenueYoYPct', label: 'Δ Revenue', align: 'right', render: (r) => delta(r.revenueYoYPct), gradeOf: (r) => heatFromDeltaPct(r.revenueYoYPct) },
      { key: 'budgetYoYPct', label: 'Δ Budget', align: 'right', render: (r) => delta(r.budgetYoYPct) },
    ];
    const okRows = (rows: any[]) => rows.filter((r) => r.status === 'ok');
    const hasRoasNc = [...(p.currentYearRows ?? []), ...(p.priorYearRows ?? [])].some((r: any) => r.roasNc != null);

    return (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
        <div style={{ overflowX: 'auto' }}>
          <div className="muted text-sm" style={{ marginBottom: 4 }}>{p.reportYear}{p.monthsWindow ? ` — last ${p.monthsWindow} months` : ''}</div>
          <HeatTable
            columns={columns()}
            rows={okRows(p.currentYearRows ?? [])}
            rowKey={(r) => r.month}
            title={`${p.reportYear} financial matrix`}
          />
        </div>
        <div style={{ overflowX: 'auto' }}>
          <div className="muted text-sm" style={{ marginBottom: 4 }}>{p.priorYear}{p.monthsWindow ? ` — same ${p.monthsWindow} months last year` : ''}</div>
          <HeatTable
            columns={columns()}
            rows={okRows(p.priorYearRows ?? [])}
            rowKey={(r) => r.month}
            title={`${p.priorYear} financial matrix`}
          />
        </div>
        {hasRoasNc && (
          <div className="muted" style={{ fontSize: 10, fontStyle: 'italic' }}>
            * ROAS-nc is modeled — new customers × blended AOV ÷ spend (Shopify can’t split sales by customer type; runs slightly high). Captación / Ret Δ / Δ Revenue / Δ Budget are year-over-year, vs the same month last year.
          </div>
        )}
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

  // S13 — audience mix (new vs existing spend). Row-graded, not column-graded:
  // "Existing" spend above the benchmark is BAD, so a plain column-wide heat
  // (which always paints the biggest number green) would mislead — instead
  // only the 'existing' row is graded, red when the section's own `alarm`
  // flag is set, green when it's comfortably under benchmark.
  S13: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows: any[] = p.segments ?? [];
    return (
      <HeatTable
        columns={[
          { key: 'label', label: 'Segment', render: (r) => r.label },
          { key: 'spend', label: 'Spend', align: 'right', render: (r) => money(r.spend) },
          { key: 'share', label: 'Share', align: 'right', render: (r) => (r.share == null ? '—' : `${r.share.toFixed(1)}%`) },
          {
            key: 'flag', label: `vs ${p.benchmark}% benchmark`, align: 'right',
            render: (r) => (r.key === 'existing' ? (p.alarm ? 'Above benchmark' : 'Within benchmark') : '—'),
            gradeOf: (r) => (r.key !== 'existing' ? '' : p.alarm ? 'r2' : 'g2'),
          },
        ]}
        rows={rows}
        rowKey={(r) => r.key}
        title="Audience: new vs existing spend"
      />
    );
  },

  // S14 — placement mix. Vertical (Stories/Reels) rows graded green — they're
  // the section's own goal metric (Goal >80% vertical) — everything else
  // column-graded on CTR (engagement quality is the more useful read per row
  // than raw spend rank here).
  S14: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows: any[] = p.rows ?? [];
    return (
      <HeatTable
        columns={[
          { key: 'label', label: 'Placement', render: (r) => r.label },
          { key: 'spend', label: 'Spend', align: 'right', render: (r) => money(r.spend) },
          { key: 'pctSpend', label: '% Spend', align: 'right', render: (r) => (r.pctSpend == null ? '—' : `${r.pctSpend.toFixed(1)}%`) },
          { key: 'cpc', label: 'CPC', align: 'right', render: (r) => money(r.cpc) },
          { key: 'ctr', label: 'CTR', align: 'right', render: (r) => (r.ctr == null ? '—' : `${r.ctr.toFixed(2)}%`), heat: { mode: 'column', dir: 'high', value: (r) => r.ctr } },
          { key: 'cpm', label: 'CPM', align: 'right', render: (r) => money(r.cpm) },
          {
            key: 'isVertical', label: 'Vertical', align: 'right', render: (r) => (r.isVertical ? 'Yes' : '—'),
            gradeOf: (r) => (r.isVertical ? 'g1' : ''),
          },
        ]}
        rows={rows}
        rowKey={(r) => r.key}
        title="Placement mix"
        footer={`Vertical (Stories + Reels): ${p.verticalPct?.value ?? '—'}% vs the ${p.goal}% goal — ${p.goalHit ? 'goal hit' : 'below goal'}`}
      />
    );
  },

  // S15 — gender mix. No `rows` array on this payload (just male/female
  // summary objects) — synthesized into a 2-row table here so it gets the
  // same HeatTable treatment (expand/heat) as every other section rather
  // than a bespoke one-off layout.
  S15: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows = [
      { key: 'female', label: 'Female', ...p.female },
      { key: 'male', label: 'Male', ...p.male },
    ];
    return (
      <HeatTable
        columns={[
          { key: 'label', label: 'Gender', render: (r) => r.label },
          { key: 'spend', label: 'Spend', align: 'right', render: (r) => money(r.spend) },
          { key: 'pct', label: 'Share', align: 'right', render: (r) => (r.pct == null ? '—' : `${r.pct.toFixed(1)}%`), heat: { mode: 'column', dir: 'high', value: (r) => r.pct } },
        ]}
        rows={rows}
        rowKey={(r) => r.key}
        title="Gender mix"
        footer={p.unavailable?.note}
      />
    );
  },

  // S16 — awareness country concentration. The top-share row is graded
  // against the section's own concentration threshold (fixed-benchmark
  // grading, like S5/S6's ROAS-vs-blended pattern) rather than a column-wide
  // grade, since "high" here is the thing being flagged, not celebrated.
  S16: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const threshold = p.threshold ?? 50;
    const rows: any[] = p.rows ?? [];
    return (
      <HeatTable
        columns={[
          { key: 'label', label: 'Country', render: (r) => r.label },
          { key: 'spend', label: 'Spend', align: 'right', render: (r) => money(r.spend) },
          {
            key: 'sharePct', label: 'Share of awareness spend', align: 'right',
            render: (r) => (r.sharePct == null ? '—' : `${r.sharePct.toFixed(1)}%`),
            gradeOf: (r) => (r.sharePct == null ? '' : r.sharePct > threshold ? 'r2' : ''),
          },
          { key: 'impressions', label: 'Impressions', align: 'right', render: (r) => (r.impressions ?? 0).toLocaleString() },
        ]}
        rows={rows}
        rowKey={(r) => r.iso2}
        title="Awareness country concentration"
        footer={p.alert ? `${p.topCountry} carries ${p.topSharePct?.value}% of awareness spend — above the ${threshold}% concentration threshold.` : undefined}
      />
    );
  },

  // S17 — landing spend x best sellers. The mismatch row (highest-spend vs
  // highest-revenue product) is called out via the footer, matching the
  // PDF's own "spending on X, best seller is Y" framing.
  S17: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows: any[] = p.rows ?? [];
    return (
      <HeatTable
        columns={[
          { key: 'title', label: 'Product', render: (r) => r.title ?? (r.unattributed ? `Unattributed (${r.handle})` : r.handle) },
          { key: 'spend', label: 'Ad spend', align: 'right', render: (r) => money(r.spend), heat: { mode: 'column', dir: 'high', value: (r) => r.spend } },
          { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue) },
          { key: 'stock', label: 'Stock', align: 'right', render: (r) => (r.stock == null ? '—' : r.stock.toLocaleString()), gradeOf: (r) => (r.stock === 0 ? 'r2' : '') },
        ]}
        rows={rows}
        rowKey={(r) => r.handle}
        title="Landing spend x best sellers"
        footer={p.mismatch ? `Spending on ${p.mismatch.spendingOn}, best seller is ${p.mismatch.bestSeller}.` : undefined}
      />
    );
  },

  // S18 — Klaviyo attribution. Flow vs campaign rows, revenue column-graded;
  // the honesty box (Klaviyo revenue is its OWN channel, never summed into
  // store/ad revenue) renders as the footer, always.
  S18: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const rows: any[] = p.rows ?? [];
    return (
      <HeatTable
        columns={[
          { key: 'name', label: 'Flow / campaign', render: (r) => r.name ?? r.id },
          { key: 'source', label: 'Type', render: (r) => (r.source === 'flow' ? 'Flow' : 'Campaign') },
          { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
          { key: 'orders', label: 'Orders', align: 'right', render: (r) => (r.orders ?? 0).toLocaleString() },
        ]}
        rows={rows}
        rowKey={(r) => `${r.source}-${r.id}`}
        title="Klaviyo attribution"
        footer={p.honestyBox}
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
