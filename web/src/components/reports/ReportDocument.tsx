import { formatMoney, formatRoas } from '@/lib/formatters';
import type { CommerceSection, OverallPerformanceReportData } from '@/types/reports';

const PLATFORM_LABEL: Record<string, string> = {
  meta: 'Meta Ads',
  google: 'Google Ads',
  tiktok: 'TikTok Ads',
};

const DEFAULT_COMMENTARY =
  'Summarise the period for the client here — what moved, why, and the plan for next period. This is editable before you send, and is where the analyst narrative will be generated once the AI layer is on.';

/**
 * The white-label client report — Roasdriven's native agency design (Fraunces
 * display, Inter body, JetBrains Mono figures, the agency accent applied as a
 * CSS variable). Brand name on top, agency footer at the bottom. Print-friendly.
 *
 * Slice 2.0 renders what today's data supports — executive summary, revenue vs
 * ad spend, by-platform. The by-region / by-product / by-collection / ads-audit
 * sections light up as slices 2.1–2.2 land the granular data, and the narrative
 * block is where the LLM (2.3) writes.
 */
export function ReportDocument({
  data,
  editable = false,
  onCommentaryChange,
}: {
  data: OverallPerformanceReportData;
  editable?: boolean;
  onCommentaryChange?: (value: string) => void;
}) {
  const { brand, currency, period, comparison, kpis, revenueVsSpend, byPlatform, spendComplete } = data;

  // Slice 2.1 commerce sections — each present only when the backfill has data.
  // Numbered contiguously from 03 so gaps never appear if one is absent.
  const commerceSections: Array<{
    id: string;
    title: string;
    noun: string;
    subtitle: string;
    section: CommerceSection;
    note: string | null;
  }> = [];
  if (data.byRegion) {
    commerceSections.push({ id: 'region', title: 'By region', noun: 'Market', subtitle: 'Revenue by billing country', section: data.byRegion, note: null });
  }
  if (data.byProduct) {
    commerceSections.push({ id: 'product', title: 'By product', noun: 'Product', subtitle: 'Top products by revenue', section: data.byProduct, note: 'Revenue is allocated per line item — an order spanning several products appears under each, so product orders can exceed total orders.' });
  }
  if (data.byCategory) {
    commerceSections.push({ id: 'category', title: 'By category', noun: 'Category', subtitle: 'Shopify product type (the closest native equivalent to collection)', section: data.byCategory, note: 'Revenue is allocated per line item — an order spanning several categories appears under each.' });
  }

  const accent = data.branding?.accent || '#1f6f5c';
  const agencyName = data.branding?.agency_name || 'Roasdriven';
  const footerText = data.branding?.footer_text || 'Powered by novasolution.ae';
  const initialCommentary = data.content?.commentary ?? DEFAULT_COMMENTARY;

  const fmt = (v: number | null, kind: 'money' | 'ratio' | 'int' = 'money'): string =>
    v === null ? '—' : kind === 'ratio' ? formatRoas(v) : kind === 'int' ? v.toLocaleString() : formatMoney(v, currency);
  const kpiMoney = (v: number | null): string => (v === null ? '—' : formatMoney(v, currency, { whole: true }));

  return (
    <div className="rpt" style={{ ['--rpt-accent' as never]: accent }}>
      <style>{REPORT_CSS}</style>

      <header className="rpt-head">
        <div>
          <div className="rpt-eyebrow">Overall performance report · {period.label}</div>
          <h1 className="rpt-brand">{brand.name}</h1>
          <div className="rpt-brand-sub">
            Revenue vs ad spend vs blended ROAS · {period.label.toLowerCase()}
            {comparison ? ` ${comparison.label}` : ''}
          </div>
        </div>
        <div className="rpt-meta">
          <div><strong>Period</strong> {period.start} – {period.end}</div>
          {comparison && <div><strong>{comparison.label}</strong> {comparison.start} – {comparison.end}</div>}
          <div><strong>Revenue</strong> {kpiMoney(kpis.revenue.value)}</div>
          <div><strong>Currency</strong> {currency}</div>
        </div>
      </header>
      <div className="rpt-rule" />

      <section className="rpt-sec">
        <div className="rpt-sec-head"><span className="rpt-sec-num">00</span><h2>Executive summary</h2></div>
        <div className="rpt-kpis">
          <Kpi label="Total revenue" value={kpiMoney(kpis.revenue.value)} delta={<Delta pct={kpis.revenue.deltaPct} abs={null} kind="money" />} />
          <Kpi label="Ad spend" value={kpiMoney(kpis.adSpend.value)} delta={<Delta pct={kpis.adSpend.deltaPct} abs={null} kind="money" />} />
          <Kpi
            label="Blended ROAS"
            value={fmt(kpis.blendedRoas.value, 'ratio')}
            delta={<Delta pct={null} abs={kpis.blendedRoas.deltaAbs} kind="ratio" />}
            note={spendComplete ? undefined : 'connected platforms only'}
          />
          <Kpi label="Orders" value={fmt(kpis.orders.value, 'int')} delta={<Delta pct={kpis.orders.deltaPct} abs={null} kind="int" />} />
          <Kpi label="Avg order value" value={kpiMoney(kpis.aov.value)} delta={<Delta pct={kpis.aov.deltaPct} abs={null} kind="money" />} />
        </div>
        <div className="rpt-narrative">
          <div className="rpt-ai-tag">Analyst summary{editable ? ' · editable' : ''}</div>
          {editable ? (
            <div
              className="rpt-note"
              contentEditable
              suppressContentEditableWarning
              onBlur={(e) => onCommentaryChange?.(e.currentTarget.textContent ?? '')}
            >
              {initialCommentary}
            </div>
          ) : (
            <div className="rpt-note">{data.content?.commentary ?? DEFAULT_COMMENTARY}</div>
          )}
        </div>
      </section>

      <section className="rpt-sec">
        <div className="rpt-sec-head"><span className="rpt-sec-num">01</span><h2>Revenue against ad spend</h2></div>
        <div className="rpt-tbl-wrap">
          <table className="rpt-tbl">
            <thead>
              <tr>
                <th>Metric</th>
                <th className="r">{comparison ? comparison.label : 'Comparison'}</th>
                <th className="r">{period.label}</th>
                <th className="r">Δ</th>
              </tr>
            </thead>
            <tbody>
              {revenueVsSpend.map((row) => (
                <tr key={row.label}>
                  <td className="name">{row.label}</td>
                  <td className="r prior">{fmt(row.previous, row.kind)}</td>
                  <td className="r">{fmt(row.value, row.kind)}</td>
                  <td className="r"><Delta pct={row.deltaPct} abs={row.deltaAbs} kind={row.kind} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="rpt-sec">
        <div className="rpt-sec-head"><span className="rpt-sec-num">02</span><h2>By ad platform</h2></div>
        <div className="rpt-plat-grid">
          {byPlatform.map((p) => (
            <div className="rpt-plat" key={p.platform}>
              <div className="rpt-plat-name">
                {PLATFORM_LABEL[p.platform] ?? p.platform}
                <span className={`rpt-tag ${p.connected ? 'live' : 'pending'}`}>{p.connected ? 'Live' : 'Not connected'}</span>
              </div>
              {p.connected ? (
                <div className="rpt-plat-spend">{fmt(p.spend)}</div>
              ) : (
                <div className="rpt-plat-empty">No connection on this brand yet — connect it and spend appears here, never as €0.</div>
              )}
            </div>
          ))}
        </div>
      </section>

      {commerceSections.map((cs, i) => (
        <CommerceSectionBlock
          key={cs.id}
          num={String(3 + i).padStart(2, '0')}
          title={cs.title}
          noun={cs.noun}
          subtitle={cs.subtitle}
          section={cs.section}
          currency={currency}
          hasComparison={!!comparison}
          note={cs.note}
        />
      ))}

      <footer className="rpt-foot">
        <div>
          <div className="rpt-foot-brand">{agencyName}</div>
          <div className="rpt-foot-powered">{footerText}</div>
        </div>
        <div className="rpt-foot-note">{brand.name} · Overall performance · {period.start} – {period.end}</div>
      </footer>
    </div>
  );
}

function Kpi({ label, value, delta, note }: { label: string; value: string; delta: React.ReactNode; note?: string }) {
  return (
    <div className="rpt-kpi">
      <div className="rpt-kpi-l">{label}</div>
      <div className="rpt-kpi-v">{value}</div>
      <div className="rpt-kpi-d">{delta}{note ? <span className="rpt-kpi-note"> · {note}</span> : null}</div>
    </div>
  );
}

function Delta({ pct, abs, kind }: { pct: number | null; abs: number | null; kind: string }) {
  if (kind === 'ratio') {
    if (abs === null) return <span className="flat">—</span>;
    const dir = abs > 0.005 ? 'up' : abs < -0.005 ? 'down' : 'flat';
    return <span className={dir}>{abs > 0 ? '+' : ''}{abs.toFixed(2)}×</span>;
  }
  if (pct === null) return <span className="flat">—</span>;
  const dir = pct > 0.05 ? 'up' : pct < -0.05 ? 'down' : 'flat';
  return <span className={dir}>{pct > 0 ? '+' : ''}{pct.toFixed(1)}%</span>;
}

function CommerceSectionBlock({
  num,
  title,
  noun,
  subtitle,
  section,
  currency,
  hasComparison,
  note,
}: {
  num: string;
  title: string;
  noun: string;
  subtitle: string;
  section: CommerceSection;
  currency: string;
  hasComparison: boolean;
  note: string | null;
}) {
  const money = (v: number | null): string => (v === null ? '—' : formatMoney(v, currency));
  const share = (v: number | null): string => (v === null ? '—' : `${(v * 100).toFixed(1)}%`);

  return (
    <section className="rpt-sec">
      <div className="rpt-sec-head"><span className="rpt-sec-num">{num}</span><h2>{title}</h2></div>
      <div className="rpt-sec-sub">{subtitle}</div>
      <div className="rpt-tbl-wrap">
        <table className="rpt-tbl rpt-tbl-dim">
          <thead>
            <tr>
              <th>{noun}</th>
              <th className="r">Revenue</th>
              <th className="r">Share</th>
              <th className="r">Orders</th>
              <th className="r">AOV</th>
              <th className="r">{hasComparison ? 'Δ rev' : ''}</th>
            </tr>
          </thead>
          <tbody>
            {section.rows.map((r) => (
              <tr key={r.key}>
                <td className="name">
                  <div className="rpt-dim-label">{r.label}</div>
                  <div className="rpt-bar"><span style={{ width: `${Math.round((r.share ?? 0) * 100)}%` }} /></div>
                </td>
                <td className="r">{money(r.revenue)}</td>
                <td className="r">{share(r.share)}</td>
                <td className="r">{r.orders.toLocaleString()}</td>
                <td className="r">{money(r.aov)}</td>
                <td className="r">{hasComparison ? <Delta pct={r.deltaPct} abs={null} kind="money" /> : '—'}</td>
              </tr>
            ))}
            {section.other && (
              <tr className="rpt-other">
                <td className="name"><div className="rpt-dim-label">Other</div><div className="rpt-dim-sub">{section.other.count} more</div></td>
                <td className="r">{money(section.other.revenue)}</td>
                <td className="r">{share(section.other.share)}</td>
                <td className="r">{section.other.orders.toLocaleString()}</td>
                <td className="r">—</td>
                <td className="r">—</td>
              </tr>
            )}
          </tbody>
          <tfoot>
            <tr>
              <td className="name"><div className="rpt-dim-label">Total</div></td>
              <td className="r">{money(section.total.revenue)}</td>
              <td className="r">100%</td>
              <td className="r">{section.total.orders.toLocaleString()}</td>
              <td className="r">—</td>
              <td className="r">—</td>
            </tr>
          </tfoot>
        </table>
      </div>
      {note && <div className="rpt-cap">{note}</div>}
    </section>
  );
}

const REPORT_CSS = `
.rpt{--ink:#161514;--ink-2:#45433f;--ink-3:#7a766f;--ink-4:#a8a39a;--line:#e7e4dd;--line-2:#d6d2c8;
  --paper:#fff;--bg:#f7f6f3;--red:#bb2d2d;--green:#1f7a48;--amber:#a8730a;--accent:var(--rpt-accent,#1f6f5c);
  --display:'Fraunces',Georgia,serif;--sans:'Inter',system-ui,sans-serif;--mono:'JetBrains Mono',monospace;
  background:var(--bg);color:var(--ink);font-family:var(--sans);line-height:1.6;-webkit-font-smoothing:antialiased;
  max-width:1080px;margin:0 auto;padding:52px 48px;border-radius:14px;font-variant-numeric:tabular-nums}
.rpt *{box-sizing:border-box}
.rpt-head{display:grid;grid-template-columns:1fr auto;gap:32px;align-items:flex-end;padding-bottom:26px;border-bottom:1.5px solid var(--ink)}
.rpt-eyebrow{font-family:var(--mono);font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--accent);font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.rpt-eyebrow::before{content:'';width:20px;height:2px;background:var(--accent)}
.rpt-brand{font-family:var(--display);font-size:58px;font-weight:600;line-height:.92;letter-spacing:-.02em}
.rpt-brand-sub{font-size:13px;color:var(--ink-3);margin-top:12px}
.rpt-meta{text-align:right;font-family:var(--mono);font-size:11px;color:var(--ink-3);line-height:1.95;letter-spacing:.02em}
.rpt-meta strong{color:var(--ink);font-weight:600;font-family:var(--sans)}
.rpt-rule{height:3px;background:linear-gradient(90deg,var(--accent),color-mix(in srgb,var(--accent) 55%,#000));border-radius:2px;margin:0 0 40px}
.rpt-sec{margin-bottom:48px}
.rpt-sec-head{display:flex;align-items:baseline;gap:13px;margin-bottom:22px}
.rpt-sec-num{font-family:var(--mono);font-size:12px;color:var(--accent);letter-spacing:.1em;font-weight:600}
.rpt-sec-head h2{font-family:var(--display);font-size:30px;font-weight:600;letter-spacing:-.015em;margin:0}
.rpt-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:13px;margin-bottom:22px}
.rpt-kpi{background:var(--paper);border:1px solid var(--line);border-radius:13px;padding:18px 20px}
.rpt-kpi-l{font-size:10px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-3);margin-bottom:11px}
.rpt-kpi-v{font-family:var(--display);font-size:36px;font-weight:600;line-height:1;letter-spacing:-.02em}
.rpt-kpi-d{font-size:11.5px;margin-top:9px;font-weight:600}
.rpt-kpi-note{color:var(--ink-4);font-weight:400}
.rpt .up{color:var(--green)} .rpt .down{color:var(--red)} .rpt .flat{color:var(--ink-3)}
.rpt-narrative{background:var(--paper);border:1px solid var(--line);border-left:3px solid var(--accent);border-radius:12px;padding:20px 24px}
.rpt-ai-tag{display:inline-flex;align-items:center;gap:6px;font-family:var(--mono);font-size:9.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);font-weight:600;margin-bottom:12px}
.rpt-ai-tag::before{content:'✦'}
.rpt-note{font-size:14px;line-height:1.75;color:var(--ink-2);outline:none;min-height:44px}
.rpt-note[contenteditable]:hover{background:#fcfbf8}
.rpt-note[contenteditable]:focus{background:#fffef9}
.rpt-tbl-wrap{background:var(--paper);border:1px solid var(--line);border-radius:13px;overflow:hidden}
.rpt-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.rpt-tbl thead tr{background:var(--ink);color:#fff}
.rpt-tbl th{padding:12px 18px;text-align:left;font-size:9.5px;font-weight:600;letter-spacing:.1em;text-transform:uppercase}
.rpt-tbl th.r{text-align:right}
.rpt-tbl td{padding:12px 18px;border-bottom:1px solid var(--line)}
.rpt-tbl td.r{text-align:right;font-family:var(--mono);font-size:11.5px}
.rpt-tbl td.name{font-weight:600}
.rpt-tbl td.prior{color:var(--ink-3)}
.rpt-tbl tr:last-child td{border-bottom:none}
.rpt-plat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:13px}
.rpt-plat{background:var(--paper);border:1px solid var(--line);border-radius:13px;padding:18px 20px}
.rpt-plat-name{font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.rpt-tag{font-size:9px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:20px}
.rpt-tag.live{color:var(--green);background:#eef8f1;border:1px solid #bfe0c8}
.rpt-tag.pending{color:var(--amber);background:#fbf5e8;border:1px solid #ecdcb4}
.rpt-plat-spend{font-family:var(--display);font-size:27px;font-weight:600}
.rpt-plat-empty{font-size:11px;color:var(--ink-3);line-height:1.55}
.rpt-foot{display:flex;justify-content:space-between;align-items:flex-end;border-top:1.5px solid var(--line-2);padding-top:24px;margin-top:54px}
.rpt-foot-brand{font-family:var(--display);font-size:23px;font-weight:600}
.rpt-foot-powered{font-size:10px;color:var(--ink-4);margin-top:3px}
.rpt-foot-note{font-family:var(--mono);font-size:10px;color:var(--ink-4);text-align:right;line-height:1.7}
.rpt-sec-sub{font-size:12px;color:var(--ink-3);margin:-12px 0 16px}
.rpt-tbl-dim td.name{min-width:200px}
.rpt-dim-label{font-weight:600;font-size:13px}
.rpt-dim-sub{font-size:10px;color:var(--ink-4);margin-top:2px}
.rpt-bar{height:3px;background:var(--line);border-radius:2px;margin-top:6px;max-width:200px}
.rpt-bar span{display:block;height:100%;background:var(--accent);border-radius:2px;min-width:2px}
.rpt-tbl-dim tbody tr:hover{background:#fcfbf8}
.rpt-other td{color:var(--ink-3)}
.rpt-tbl-dim tfoot td{padding:12px 18px;border-top:1.5px solid var(--ink);font-weight:700;background:var(--bg)}
.rpt-tbl-dim tfoot td.r{text-align:right;font-family:var(--mono);font-size:11.5px}
.rpt-cap{font-size:10.5px;color:var(--ink-4);margin-top:10px;line-height:1.5;font-style:italic}
@media print{.rpt{background:#fff;padding:24px;max-width:none}.rpt-sec{page-break-inside:avoid}}
`;
