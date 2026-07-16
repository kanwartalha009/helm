import { formatMoney, formatRoas } from '@/lib/formatters';
import { HeatTable, type HeatColumn } from './HeatTable';
import { heatFromDeltaPct, heatVsBenchmark, type HeatGrade } from './heat';

/** % change a→b, null-safe (shared by the month-by-month matrices). */
const pctChange = (a: number | null | undefined, b: number | null | undefined): number | null =>
  a == null || b == null || b === 0 ? null : ((a - b) / b) * 100;

/** A signed percent, e.g. +12.3% / −4.0% / — . */
const deltaFmt = (v: number | null | undefined): string => (v == null ? '—' : `${v > 0 ? '+' : ''}${v.toFixed(1)}%`);

/**
 * Build one column per month from a payload's `months`/`monthLabels`, reading
 * `row.monthly[i]`. By default each cell is graded by its month-over-month
 * change (green climbing / red dropping) so momentum reads down the row —
 * pass `gradeCell` to grade differently (e.g. vs a benchmark).
 */
function monthColumns(
  months: string[],
  monthLabels: string[],
  fmt: (v: number | null | undefined) => string,
  gradeCell?: (row: any, i: number) => HeatGrade,
): HeatColumn<any>[] {
  return months.map((ym, i) => ({
    key: `m_${ym}`,
    label: monthLabels[i] ?? ym,
    align: 'right',
    render: (r) => fmt(r.monthly?.[i]),
    gradeOf: (r) => (gradeCell ? gradeCell(r, i) : i === 0 ? '' : heatFromDeltaPct(pctChange(r.monthly?.[i], r.monthly?.[i - 1]))),
  }));
}

/**
 * Shared detailed ad-metrics table for the Meta breakdown sections (S14
 * placement, S15 gender): Cost/Reach/Freq/Clicks/CTR/CPM/Purch/ROAS/CPA/Share,
 * coloured like the reference (CTR/ROAS higher = greener; CPM/CPA lower = greener).
 */
function renderAdMetrics(p: any, currency: string, firstLabel: string, title: string): React.ReactNode {
  const money = (v: number | null | undefined) => (v == null ? '—' : formatMoney(v, currency, { whole: true }));
  const num = (v: number | null | undefined) => (v == null ? '—' : Math.round(v).toLocaleString());
  const rows: any[] = p.rows ?? [];
  return (
    <HeatTable
      columns={[
        { key: 'label', label: firstLabel, render: (r) => r.label },
        { key: 'spend', label: 'Cost', align: 'right', render: (r) => money(r.spend) },
        { key: 'reach', label: 'Reach', align: 'right', render: (r) => num(r.reach) },
        { key: 'frequency', label: 'Freq', align: 'right', render: (r) => (r.frequency == null ? '—' : r.frequency.toFixed(2)) },
        { key: 'clicks', label: 'Clicks', align: 'right', render: (r) => num(r.clicks) },
        { key: 'ctr', label: 'CTR', align: 'right', render: (r) => (r.ctr == null ? '—' : `${r.ctr.toFixed(2)}%`), heat: { mode: 'column', dir: 'high', value: (r) => r.ctr } },
        { key: 'cpm', label: 'CPM', align: 'right', render: (r) => money(r.cpm), heat: { mode: 'column', dir: 'low', value: (r) => r.cpm } },
        { key: 'purchases', label: 'Purch.', align: 'right', render: (r) => num(r.purchases) },
        { key: 'roas', label: 'ROAS', align: 'right', render: (r) => (r.roas == null ? '—' : formatRoas(r.roas)), heat: { mode: 'column', dir: 'high', value: (r) => r.roas } },
        { key: 'cpa', label: 'CPA', align: 'right', render: (r) => money(r.cpa), heat: { mode: 'column', dir: 'low', value: (r) => r.cpa } },
        { key: 'sharePct', label: 'Share', align: 'right', render: (r) => (r.sharePct == null ? '—' : `${r.sharePct.toFixed(0)}%`) },
      ]}
      rows={rows}
      rowKey={(r) => r.key ?? r.label}
      title={p.platform === 'tiktok' ? `${title} · TikTok` : title}
      previewRows={15}
    />
  );
}

/**
 * Shared funnel table for S10/S11 — the stages (Add to cart / Checkout /
 * Purchase) shown as % of sessions (Kanwar, 2026-07-16), higher = greener.
 */
function renderFunnel(p: any, firstLabel: string): React.ReactNode {
  const rows: any[] = p.rows ?? [];
  const pct = (v: number | null | undefined) => (v == null ? '—' : `${v.toFixed(1)}%`);
  return (
    <HeatTable
      columns={[
        { key: 'label', label: firstLabel, render: (r) => r.label },
        { key: 'sessions', label: 'Sessions', align: 'right', render: (r) => (r.sessions ?? 0).toLocaleString() },
        { key: 'cartPct', label: 'Add to cart', align: 'right', render: (r) => pct(r.cartPct), heat: { mode: 'column', dir: 'high', value: (r) => r.cartPct } },
        { key: 'checkoutPct', label: 'Checkout', align: 'right', render: (r) => pct(r.checkoutPct), heat: { mode: 'column', dir: 'high', value: (r) => r.checkoutPct } },
        { key: 'purchasePct', label: 'Purchase', align: 'right', render: (r) => pct(r.purchasePct), heat: { mode: 'column', dir: 'high', value: (r) => r.purchasePct } },
        { key: 'deltaPct', label: 'Δ CVR', align: 'right', render: (r) => deltaFmt(r.deltaPct), gradeOf: (r) => heatFromDeltaPct(r.deltaPct) },
      ]}
      rows={rows}
      rowKey={(r) => r.key}
      title={`Funnel by ${firstLabel.toLowerCase()}`}
      previewRows={15}
    />
  );
}

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
    // Which ad-platform share columns to show (backend condition: connected OR
    // has spend). Falls back to all three if the backend didn't say.
    const adPlatforms: string[] = Array.isArray(p.adPlatforms) ? p.adPlatforms : ['google', 'meta', 'tiktok'];
    const platformLabel: Record<string, string> = { google: 'Google %', meta: 'Meta %', tiktok: 'TikTok %' };
    const platformField: Record<string, string> = { google: 'googleSharePct', meta: 'metaSharePct', tiktok: 'tiktokSharePct' };
    const columns = (): HeatColumn<any>[] => {
      const cols: HeatColumn<any>[] = [
        { key: 'label', label: 'Month', render: (r) => r.label ?? r.month },
        { key: 'orders', label: 'Orders', align: 'right', render: (r) => (r.orders ?? 0).toLocaleString(), heat: { mode: 'column', dir: 'high', value: (r) => r.orders } },
        { key: 'aov', label: 'AOV', align: 'right', render: (r) => money(r.aov), heat: { mode: 'column', dir: 'high', value: (r) => r.aov } },
        { key: 'returnsPct', label: '% Returns', align: 'right', render: (r) => pct1(r.returnsPct), heat: { mode: 'column', dir: 'low', value: (r) => r.returnsPct } },
        { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
        { key: 'spend', label: 'Spend', align: 'right', render: (r) => money(r.spend) },
      ];
      // Ad-platform share columns — only the connected/spending ones.
      adPlatforms.forEach((pf) => {
        const field = platformField[pf];
        if (field) cols.push({ key: field, label: platformLabel[pf] ?? pf, align: 'right', render: (r) => share(r[field]) });
      });
      cols.push({ key: 'roas', label: 'ROAS', align: 'right', render: (r) => (r.roas == null ? '—' : formatRoas(r.roas)), heat: { mode: 'column', dir: 'high', value: (r) => r.roas } });
      // Customer split — real Shopify counts; '—' when the counts aren't available.
      cols.push(
        { key: 'new', label: 'New', align: 'right', render: (r) => count(r.new), heat: { mode: 'column', dir: 'high', value: (r) => r.new } },
        { key: 'returning', label: 'Returning', align: 'right', render: (r) => count(r.returning), heat: { mode: 'column', dir: 'high', value: (r) => r.returning } },
        { key: 'retPctCustomers', label: '% Ret', align: 'right', render: (r) => pct1(r.retPctCustomers) },
        { key: 'totalCustomers', label: 'Total', align: 'right', render: (r) => count(r.totalCustomers), heat: { mode: 'column', dir: 'high', value: (r) => r.totalCustomers } },
        { key: 'cac', label: 'CAC', align: 'right', render: (r) => money(r.cac), heat: { mode: 'column', dir: 'low', value: (r) => r.cac } },
        { key: 'roasNc', label: 'ROAS-nc*', align: 'right', render: (r) => (r.roasNc == null ? '—' : formatRoas(r.roasNc)), heat: { mode: 'column', dir: 'high', value: (r) => r.roasNc } },
      );
      // Goal column only when the brand has a target (backend `hasGoals`).
      if (p.hasGoals) cols.push({ key: 'goalPct', label: 'Goal', align: 'right', render: (r) => delta(r.goalPct), gradeOf: (r) => heatFromDeltaPct(r.goalPct) });
      // Comparison columns — month-over-month.
      cols.push(
        { key: 'captacionMoMPct', label: 'Captación', align: 'right', render: (r) => delta(r.captacionMoMPct), gradeOf: (r) => heatFromDeltaPct(r.captacionMoMPct) },
        { key: 'retentionMoMPct', label: 'Ret Δ', align: 'right', render: (r) => delta(r.retentionMoMPct), gradeOf: (r) => heatFromDeltaPct(r.retentionMoMPct) },
        { key: 'revMoM', label: 'Δ Revenue', align: 'right', render: (r) => delta(r.deltaRevenuePct), gradeOf: (r) => heatFromDeltaPct(r.deltaRevenuePct) },
        { key: 'budgetMoM', label: 'Δ Budget', align: 'right', render: (r) => delta(r.deltaSpendPct), gradeOf: (r) => heatFromDeltaPct(r.deltaSpendPct) },
      );
      return cols;
    };
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
            * ROAS-nc is modeled — new customers × blended AOV ÷ spend (Shopify can’t split sales by customer type; runs slightly high). Captación / Ret Δ / Δ Revenue / Δ Budget are month-over-month (vs the previous month).
          </div>
        )}
      </div>
    );
  },

  // S4 — market revenue by tier, MONTH-BY-MONTH matrix (Kanwar, 2026-07-16):
  // one revenue column per month (colored by MoM change), then Total / Share /
  // ROAS / ΔYoY / ΔMoM. Month count set by the section's window control.
  S4: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const compact = (v: number | null | undefined) => (v == null ? '—' : formatMoney(v, currency, { compact: true }));
    const delta = (v: number | null) => (v == null ? '—' : `${v > 0 ? '+' : ''}${v.toFixed(1)}%`);
    const pctChange = (a: number | null | undefined, b: number | null | undefined): number | null =>
      a == null || b == null || b === 0 ? null : ((a - b) / b) * 100;
    const rows: any[] = p.rows ?? [];
    const months: string[] = Array.isArray(p.months) ? p.months : [];
    const monthLabels: string[] = Array.isArray(p.monthLabels) ? p.monthLabels : months;

    const columns: HeatColumn<any>[] = [{ key: 'label', label: 'Tier', render: (r) => r.label }];
    months.forEach((ym, i) => {
      columns.push({
        key: `m_${ym}`,
        label: monthLabels[i] ?? ym,
        align: 'right',
        render: (r) => compact(r.monthly?.[i]),
        gradeOf: (r) => (i === 0 ? '' : heatFromDeltaPct(pctChange(r.monthly?.[i], r.monthly?.[i - 1]))),
      });
    });
    columns.push(
      { key: 'revenue', label: 'Total', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
      { key: 'share', label: 'Share', align: 'right', render: (r) => (r.share == null ? '—' : `${r.share.toFixed(1)}%`) },
      { key: 'roas', label: 'ROAS', align: 'right', render: (r) => (r.roas == null ? '—' : formatRoas(r.roas)), heat: { mode: 'column', dir: 'high', value: (r) => r.roas } },
      { key: 'deltaYoYPct', label: 'Δ YoY', align: 'right', render: (r) => delta(r.deltaYoYPct), gradeOf: (r) => heatFromDeltaPct(r.deltaYoYPct) },
      { key: 'deltaMoMPct', label: 'Δ MoM', align: 'right', render: (r) => delta(r.deltaMoMPct), gradeOf: (r) => heatFromDeltaPct(r.deltaMoMPct) },
    );

    return <HeatTable columns={columns} rows={rows} rowKey={(r) => r.tierKey} title="Market revenue by tier" />;
  },

  // S5 — country revenue MONTH-BY-MONTH matrix (Kanwar, 2026-07-16): one column
  // per month (colored by that month's MoM change, so momentum reads down each
  // row like the reference), then Total / Share / ROAS / ΔYoY / ΔMoM / status.
  // Month count is set by the section's own window control. ROAS graded vs the
  // brand's blended ROAS (v1's roasHeat), not a column-wide best-of.
  S5: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const compact = (v: number | null | undefined) => (v == null ? '—' : formatMoney(v, currency, { compact: true }));
    const delta = (v: number | null) => (v == null ? '—' : `${v > 0 ? '+' : ''}${v.toFixed(1)}%`);
    const pctChange = (a: number | null | undefined, b: number | null | undefined): number | null =>
      a == null || b == null || b === 0 ? null : ((a - b) / b) * 100;
    const rows: any[] = p.rows ?? [];
    const months: string[] = Array.isArray(p.months) ? p.months : [];
    const monthLabels: string[] = Array.isArray(p.monthLabels) ? p.monthLabels : months;
    const blended = p.total?.spend > 0 ? p.total.revenue / p.total.spend : null;

    const columns: HeatColumn<any>[] = [
      { key: 'label', label: 'Country', render: (r) => r.label },
      { key: 'tierLabel', label: 'Tier', render: (r) => r.tierLabel ?? '—' },
    ];
    months.forEach((ym, i) => {
      columns.push({
        key: `m_${ym}`,
        label: monthLabels[i] ?? ym,
        align: 'right',
        render: (r) => compact(r.monthly?.[i]),
        // Color each month cell by its change vs the previous month in the row.
        gradeOf: (r) => (i === 0 ? '' : heatFromDeltaPct(pctChange(r.monthly?.[i], r.monthly?.[i - 1]))),
      });
    });
    columns.push(
      { key: 'revenue', label: 'Total', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
      { key: 'sharePct', label: 'Share', align: 'right', render: (r) => (r.sharePct == null ? '—' : `${r.sharePct.toFixed(1)}%`) },
      { key: 'roas', label: 'ROAS', align: 'right', render: (r) => (r.roas == null ? '—' : formatRoas(r.roas)), gradeOf: (r) => heatVsBenchmark(r.roas, blended) },
      { key: 'deltaYoYPct', label: 'Δ YoY', align: 'right', render: (r) => delta(r.deltaYoYPct), gradeOf: (r) => heatFromDeltaPct(r.deltaYoYPct) },
      { key: 'deltaMoMPct', label: 'Δ MoM', align: 'right', render: (r) => delta(r.deltaMoMPct), gradeOf: (r) => heatFromDeltaPct(r.deltaMoMPct) },
      { key: 'status', label: 'Status', render: (r) => r.status ?? '—' },
    );

    return (
      <HeatTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.iso2}
        title="Country revenue MoM"
        footer={p.suggestedTitle}
        previewRows={15}
      />
    );
  },

  // S6 — ROAS by country MONTH-BY-MONTH matrix (Kanwar, 2026-07-16): one ROAS
  // column per month, each cell graded against the (configurable) benchmark —
  // green above, red below — then window ROAS / Revenue / Meta spend / tier /
  // ΔYoY / ΔMoM / status. Benchmark + month count come from the section controls.
  S6: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const delta = (v: number | null) => (v == null ? '—' : `${v > 0 ? '+' : ''}${v.toFixed(1)}%`);
    const rows: any[] = p.rows ?? [];
    const months: string[] = Array.isArray(p.months) ? p.months : [];
    const monthLabels: string[] = Array.isArray(p.monthLabels) ? p.monthLabels : months;
    const benchmark: number | null = typeof p.benchmark === 'number' ? p.benchmark : null;

    const columns: HeatColumn<any>[] = [
      { key: 'label', label: 'Country', render: (r) => r.label },
      { key: 'tierLabel', label: 'Tier', render: (r) => r.tierLabel ?? '—' },
    ];
    months.forEach((ym, i) => {
      columns.push({
        key: `m_${ym}`,
        label: monthLabels[i] ?? ym,
        align: 'right',
        render: (r) => (r.monthly?.[i] == null ? '—' : formatRoas(r.monthly[i])),
        gradeOf: (r) => heatVsBenchmark(r.monthly?.[i], benchmark),
      });
    });
    columns.push(
      { key: 'roas', label: 'ROAS', align: 'right', render: (r) => (r.roas == null ? '—' : formatRoas(r.roas)), gradeOf: (r) => heatVsBenchmark(r.roas, benchmark) },
      { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
      { key: 'spend', label: 'Meta', align: 'right', render: (r) => money(r.spend) },
      { key: 'deltaYoYPct', label: 'Δ YoY', align: 'right', render: (r) => delta(r.deltaYoYPct), gradeOf: (r) => heatFromDeltaPct(r.deltaYoYPct) },
      { key: 'deltaMoMPct', label: 'Δ MoM', align: 'right', render: (r) => delta(r.deltaMoMPct), gradeOf: (r) => heatFromDeltaPct(r.deltaMoMPct) },
      { key: 'status', label: 'Status', render: (r) => r.status ?? '—' },
    );

    return (
      <HeatTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.iso2}
        title="ROAS by country MoM"
        footer={benchmark != null ? `Benchmark ${benchmark.toFixed(1)}× — green above / red below.` : undefined}
        previewRows={15}
      />
    );
  },

  // S7 — best categories MONTH-BY-MONTH (Kanwar, 2026-07-16): per-month revenue
  // (coloured by MoM) + Total / Share / ΔYoY / ΔMoM + stock.
  S7: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const compact = (v: number | null | undefined) => (v == null ? '—' : formatMoney(v, currency, { compact: true }));
    const months: string[] = Array.isArray(p.months) ? p.months : [];
    const monthLabels: string[] = Array.isArray(p.monthLabels) ? p.monthLabels : months;
    const columns: HeatColumn<any>[] = [
      { key: 'label', label: 'Category', render: (r) => r.label },
      ...monthColumns(months, monthLabels, compact),
      { key: 'revenue', label: 'Total', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
      { key: 'share', label: 'Share', align: 'right', render: (r) => (r.share == null ? '—' : `${r.share.toFixed(1)}%`) },
      { key: 'deltaYoYPct', label: 'Δ YoY', align: 'right', render: (r) => deltaFmt(r.deltaYoYPct), gradeOf: (r) => heatFromDeltaPct(r.deltaYoYPct) },
      { key: 'deltaMoMPct', label: 'Δ MoM', align: 'right', render: (r) => deltaFmt(r.deltaMoMPct), gradeOf: (r) => heatFromDeltaPct(r.deltaMoMPct) },
      { key: 'stock', label: 'Stock', align: 'right', render: (r) => (r.stock == null ? '—' : r.stock.toLocaleString()), gradeOf: (r) => (r.lowStock ? 'r1' : '') },
    ];
    return <HeatTable columns={columns} rows={p.rows ?? []} rowKey={(r) => r.key ?? r.label} title="Best categories MoM/YoY" previewRows={12} />;
  },

  // S8 — best sellers MONTH-BY-MONTH (Kanwar, 2026-07-16): the "last-6-months"
  // trend, now real — per-month revenue (coloured by MoM) + Total/Share/ΔYoY/ΔMoM
  // + the stock flag.
  S8: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const compact = (v: number | null | undefined) => (v == null ? '—' : formatMoney(v, currency, { compact: true }));
    const months: string[] = Array.isArray(p.months) ? p.months : [];
    const monthLabels: string[] = Array.isArray(p.monthLabels) ? p.monthLabels : months;
    const columns: HeatColumn<any>[] = [
      { key: 'label', label: 'Product', render: (r) => r.label },
      ...monthColumns(months, monthLabels, compact),
      { key: 'revenue', label: 'Total', align: 'right', render: (r) => money(r.revenue), heat: { mode: 'column', dir: 'high', value: (r) => r.revenue } },
      { key: 'share', label: 'Share', align: 'right', render: (r) => (r.share == null ? '—' : `${r.share.toFixed(1)}%`) },
      { key: 'deltaYoYPct', label: 'Δ YoY', align: 'right', render: (r) => deltaFmt(r.deltaYoYPct), gradeOf: (r) => heatFromDeltaPct(r.deltaYoYPct) },
      { key: 'deltaMoMPct', label: 'Δ MoM', align: 'right', render: (r) => deltaFmt(r.deltaMoMPct), gradeOf: (r) => heatFromDeltaPct(r.deltaMoMPct) },
      { key: 'stock', label: 'Stock', align: 'right', render: (r) => (r.stock == null ? '—' : r.stock.toLocaleString()), gradeOf: (r) => (r.stockFlag === 'red' ? 'r1' : '') },
    ];
    return <HeatTable columns={columns} rows={p.rows ?? []} rowKey={(r) => r.key ?? r.label} title="Best sellers MoM" previewRows={12} />;
  },

  // S13 — audience new vs existing spend MONTH-BY-MONTH (Kanwar, 2026-07-16):
  // segment × per-month spend (coloured by MoM) + Total/Share/ΔYoY/ΔMoM. The
  // existing-vs-benchmark alarm rides in the footer.
  S13: (p, currency) => {
    const money = (v: number | null) => formatMoney(v, currency, { whole: true });
    const compact = (v: number | null | undefined) => (v == null ? '—' : formatMoney(v, currency, { compact: true }));
    const months: string[] = Array.isArray(p.months) ? p.months : [];
    const monthLabels: string[] = Array.isArray(p.monthLabels) ? p.monthLabels : months;
    const columns: HeatColumn<any>[] = [
      { key: 'label', label: 'Segment', render: (r) => r.label },
      ...monthColumns(months, monthLabels, compact),
      { key: 'spend', label: 'Total', align: 'right', render: (r) => money(r.spend), heat: { mode: 'column', dir: 'high', value: (r) => r.spend } },
      { key: 'share', label: 'Share', align: 'right', render: (r) => (r.share == null ? '—' : `${r.share.toFixed(1)}%`) },
      { key: 'deltaYoYPct', label: 'Δ YoY', align: 'right', render: (r) => deltaFmt(r.deltaYoYPct), gradeOf: (r) => heatFromDeltaPct(r.deltaYoYPct) },
      { key: 'deltaMoMPct', label: 'Δ MoM', align: 'right', render: (r) => deltaFmt(r.deltaMoMPct), gradeOf: (r) => heatFromDeltaPct(r.deltaMoMPct) },
    ];
    const existing = p.existingPct != null ? `Existing customers: ${p.existingPct}% of spend vs the ${p.benchmark}% benchmark${p.alarm ? ' — above benchmark' : ''}.` : undefined;
    return <HeatTable columns={columns} rows={p.rows ?? []} rowKey={(r) => r.key} title="Audience: new vs existing spend MoM" footer={existing} />;
  },

  // S14 — Ad spend by placement (Kanwar, 2026-07-16): detailed metrics table.
  S14: (p, currency) => renderAdMetrics(p, currency, 'Placement', 'Ad spend by placement'),

  // S15 — Ad spend by gender (Kanwar, 2026-07-16): same detailed metrics table.
  S15: (p, currency) => renderAdMetrics(p, currency, 'Gender', 'Ad spend by gender'),

  // S10 — Funnel by country: stages as % of sessions (Kanwar, 2026-07-16).
  S10: (p) => renderFunnel(p, 'Country'),

  // S11 — Funnel by landing path: same funnel-as-percentages table.
  S11: (p) => renderFunnel(p, 'Landing path'),

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

  // S17 — landing spend x best sellers, MONTH-BY-MONTH (Kanwar, 2026-07-16):
  // per-month landing SPEND (coloured by MoM) + window spend total / revenue /
  // stock / ΔYoY / ΔMoM. The mismatch (highest-spend vs highest-revenue product)
  // rides in the footer, matching the PDF's "spending on X, best seller is Y".
  S17: (p, currency) => {
    const money = (v: number | null | undefined) => (v == null ? '—' : formatMoney(v, currency, { whole: true }));
    const compact = (v: number | null | undefined) => (v == null ? '—' : formatMoney(v, currency, { compact: true }));
    const months: string[] = Array.isArray(p.months) ? p.months : [];
    const monthLabels: string[] = Array.isArray(p.monthLabels) ? p.monthLabels : months;
    const columns: HeatColumn<any>[] = [
      { key: 'title', label: 'Product', render: (r) => r.title ?? (r.unattributed ? `Unattributed (${r.handle})` : r.handle) },
      ...monthColumns(months, monthLabels, compact),
      { key: 'spend', label: 'Ad spend', align: 'right', render: (r) => money(r.spend), heat: { mode: 'column', dir: 'high', value: (r) => r.spend } },
      { key: 'revenue', label: 'Revenue', align: 'right', render: (r) => money(r.revenue) },
      { key: 'stock', label: 'Stock', align: 'right', render: (r) => (r.stock == null ? '—' : r.stock.toLocaleString()), gradeOf: (r) => (r.stock === 0 ? 'r2' : '') },
      { key: 'deltaYoYPct', label: 'Δ YoY', align: 'right', render: (r) => deltaFmt(r.deltaYoYPct), gradeOf: (r) => heatFromDeltaPct(r.deltaYoYPct) },
      { key: 'deltaMoMPct', label: 'Δ MoM', align: 'right', render: (r) => deltaFmt(r.deltaMoMPct), gradeOf: (r) => heatFromDeltaPct(r.deltaMoMPct) },
    ];
    return (
      <HeatTable
        columns={columns}
        rows={p.rows ?? []}
        rowKey={(r) => r.handle}
        title="Landing spend x best sellers MoM"
        footer={p.mismatch ? `Spending on ${p.mismatch.spendingOn}, best seller is ${p.mismatch.bestSeller}.` : undefined}
        previewRows={15}
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
