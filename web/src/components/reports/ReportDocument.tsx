import { formatMoney, formatRoas } from '@/lib/formatters';
import type { OverallPerformanceReportData } from '@/types/reports';

const PLATFORM_LABEL: Record<string, string> = {
  meta: 'Meta Ads',
  google: 'Google Ads',
  tiktok: 'TikTok Ads',
};

const DEFAULT_COMMENTARY =
  'Summarise the period for the client here — what moved, why, and the plan for next period. This text is editable before you send.';

/**
 * The white-label client report. Renders the server payload into the editorial
 * template (its own theme — not the dashboard's Linear design system). The
 * agency accent comes from workspace report_branding, applied as a CSS variable
 * so one setting recolours every report. Brand name sits on top; the agency name
 * + "powered by" sit in the footer. Print-friendly so Export PDF (window.print)
 * produces a clean page.
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
  const accent = data.branding?.accent || '#1f6f5c';
  const agencyName = data.branding?.agency_name || 'Roasdriven';
  const footerText = data.branding?.footer_text || 'Powered by novasolution.ae';
  const initialCommentary = data.content?.commentary ?? DEFAULT_COMMENTARY;

  const fmt = (v: number | null, kind: 'money' | 'ratio' | 'int' = 'money'): string =>
    v === null ? '—' : kind === 'ratio' ? formatRoas(v) : kind === 'int' ? v.toLocaleString() : formatMoney(v, currency);

  return (
    <div className="rpt" style={{ ['--rpt-accent' as never]: accent }}>
      <style>{REPORT_CSS}</style>

      <header className="rpt-header">
        <div>
          <div className="rpt-eyebrow">Overall performance report</div>
          <h1 className="rpt-title">{brand.name}</h1>
          <div className="rpt-sub">Revenue vs ad spend vs blended ROAS · {period.label.toLowerCase()}</div>
        </div>
        <div className="rpt-meta">
          <div><strong>Period:</strong> {period.start} – {period.end}</div>
          {comparison && <div><strong>{comparison.label}:</strong> {comparison.start} – {comparison.end}</div>}
          <div><strong>Currency:</strong> {currency}</div>
          <div><strong>Source:</strong> Shopify · ad platforms</div>
        </div>
      </header>

      <div className="rpt-kpis">
        <Kpi label="Total revenue" value={fmt(kpis.revenue.value)} delta={<Delta pct={kpis.revenue.deltaPct} abs={null} kind="money" />} />
        <Kpi label="Ad spend" value={fmt(kpis.adSpend.value)} delta={<Delta pct={kpis.adSpend.deltaPct} abs={null} kind="money" />} />
        <Kpi
          label="Blended ROAS"
          value={fmt(kpis.blendedRoas.value, 'ratio')}
          delta={<Delta pct={null} abs={kpis.blendedRoas.deltaAbs} kind="ratio" />}
          note={spendComplete ? undefined : 'On connected platforms only'}
        />
        <Kpi label="Orders" value={fmt(kpis.orders.value, 'int')} delta={<Delta pct={kpis.orders.deltaPct} abs={null} kind="int" />} />
        <Kpi label="Avg order value" value={fmt(kpis.aov.value)} delta={<Delta pct={kpis.aov.deltaPct} abs={null} kind="money" />} />
      </div>

      <section className="rpt-section">
        <div className="rpt-section-head"><span className="rpt-num">01</span><h2>Revenue against ad spend</h2><span className="rpt-line" /></div>
        <div className="rpt-table-wrap">
          <table className="rpt-table">
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
                  <td>{row.label}</td>
                  <td className="r prior">{fmt(row.previous, row.kind)}</td>
                  <td className="r">{fmt(row.value, row.kind)}</td>
                  <td className="r"><Delta pct={row.deltaPct} abs={row.deltaAbs} kind={row.kind} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="rpt-section">
        <div className="rpt-section-head"><span className="rpt-num">02</span><h2>By ad platform</h2><span className="rpt-line" /></div>
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

      <section className="rpt-section">
        <div className="rpt-section-head"><span className="rpt-num">03</span><h2>Agency commentary</h2><span className="rpt-line" /></div>
        <div className="rpt-note">
          {editable && <div className="rpt-note-hint">Editable — write or paste your note to the client, then export or share.</div>}
          {editable ? (
            <div
              className="rpt-note-body"
              contentEditable
              suppressContentEditableWarning
              onBlur={(e) => onCommentaryChange?.(e.currentTarget.textContent ?? '')}
            >
              {initialCommentary}
            </div>
          ) : (
            <div className="rpt-note-body">{data.content?.commentary ?? DEFAULT_COMMENTARY}</div>
          )}
        </div>
      </section>

      <footer className="rpt-footer">
        <div>
          <div className="rpt-footer-brand">{agencyName}</div>
          <div className="rpt-footer-powered">{footerText}</div>
        </div>
        <div className="rpt-footer-note">{brand.name} · Overall performance · {period.start} – {period.end}</div>
      </footer>
    </div>
  );
}

function Kpi({ label, value, delta, note }: { label: string; value: string; delta: React.ReactNode; note?: string }) {
  return (
    <div className="rpt-kpi">
      <div className="rpt-kpi-label">{label}</div>
      <div className="rpt-kpi-val">{value}</div>
      <div className="rpt-kpi-sub">{delta}{note ? <span className="rpt-kpi-note"> · {note}</span> : null}</div>
    </div>
  );
}

function Delta({ pct, abs, kind }: { pct: number | null; abs: number | null; kind: string }) {
  if (kind === 'ratio') {
    if (abs === null) return <span className="rpt-delta na">—</span>;
    const dir = abs > 0.005 ? 'up' : abs < -0.005 ? 'down' : 'flat';
    return <span className={`rpt-delta ${dir}`}>{abs > 0 ? '+' : ''}{abs.toFixed(2)}×</span>;
  }
  if (pct === null) return <span className="rpt-delta na">—</span>;
  const dir = pct > 0.05 ? 'up' : pct < -0.05 ? 'down' : 'flat';
  return <span className={`rpt-delta ${dir}`}>{pct > 0 ? '+' : ''}{pct.toFixed(1)}%</span>;
}

const REPORT_CSS = `
.rpt{--ink:#0f0f0f;--ink3:#767676;--ink4:#a8a8a8;--border:#e6e4df;--bg:#f8f7f4;--red:#c93535;--green:#237a45;
  background:var(--bg);color:var(--ink);font-family:'Inter',system-ui,sans-serif;line-height:1.6;
  max-width:1040px;margin:0 auto;padding:48px 44px;border-radius:12px}
.rpt *{box-sizing:border-box}
.rpt-header{display:grid;grid-template-columns:1fr auto;gap:28px;align-items:end;padding-bottom:28px;border-bottom:2px solid var(--ink);margin-bottom:36px}
.rpt-eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--rpt-accent);font-weight:600;margin-bottom:10px}
.rpt-title{font-family:'Cormorant Garamond',Georgia,serif;font-size:52px;font-weight:600;line-height:.95;letter-spacing:-.02em;margin:0}
.rpt-sub{font-size:13px;color:var(--ink3);margin-top:10px}
.rpt-meta{text-align:right;font-size:11px;color:var(--ink3);line-height:1.85;font-variant-numeric:tabular-nums}
.rpt-meta strong{color:var(--ink)}
.rpt-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:40px}
.rpt-kpi{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 18px}
.rpt-kpi-label{font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--ink3);margin-bottom:10px}
.rpt-kpi-val{font-family:'Cormorant Garamond',Georgia,serif;font-size:34px;font-weight:600;line-height:1;letter-spacing:-.02em}
.rpt-kpi-sub{font-size:11px;color:var(--ink3);margin-top:8px}
.rpt-kpi-note{color:var(--ink4)}
.rpt-section{margin-bottom:40px}
.rpt-section-head{display:flex;align-items:baseline;gap:12px;margin-bottom:18px}
.rpt-num{font-size:11px;color:var(--ink4);letter-spacing:.1em}
.rpt-section-head h2{font-family:'Cormorant Garamond',Georgia,serif;font-size:25px;font-weight:600;margin:0}
.rpt-line{flex:1;height:1px;background:var(--border)}
.rpt-table-wrap{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
.rpt-table{width:100%;border-collapse:collapse;font-size:12.5px}
.rpt-table thead tr{background:var(--ink);color:#fff}
.rpt-table th{padding:11px 16px;text-align:left;font-size:9px;font-weight:600;letter-spacing:.1em;text-transform:uppercase}
.rpt-table th.r{text-align:right}
.rpt-table td{padding:11px 16px;border-bottom:1px solid var(--border);font-variant-numeric:tabular-nums}
.rpt-table td.r{text-align:right}
.rpt-table td.prior{color:var(--ink3)}
.rpt-table tr:last-child td{border-bottom:none}
.rpt-plat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.rpt-plat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 18px}
.rpt-plat-name{font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.rpt-tag{font-size:9px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;padding:2px 8px;border-radius:20px}
.rpt-tag.live{color:var(--green);background:#f0faf4;border:1px solid #b0dfc0}
.rpt-tag.pending{color:#b87a00;background:#fdf8ed;border:1px solid #f0dfa0}
.rpt-plat-spend{font-family:'Cormorant Garamond',Georgia,serif;font-size:26px;font-weight:600}
.rpt-plat-empty{font-size:11px;color:var(--ink3);line-height:1.55}
.rpt-note{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
.rpt-note-hint{font-size:10px;color:var(--ink3);font-style:italic;padding:10px 18px;background:#faf9f6;border-bottom:1px solid var(--border)}
.rpt-note-body{padding:18px;font-size:13.5px;line-height:1.7;color:#2a2a2a;outline:none;min-height:48px}
.rpt-note-body[contenteditable]:hover{background:#fcfbf8}
.rpt-note-body[contenteditable]:focus{background:#fffef9}
.rpt-delta.up{color:var(--green)}
.rpt-delta.down{color:var(--red)}
.rpt-delta.flat,.rpt-delta.na{color:var(--ink4)}
.rpt-footer{display:flex;justify-content:space-between;align-items:flex-end;border-top:1px solid var(--border);padding-top:24px;margin-top:44px}
.rpt-footer-brand{font-family:'Cormorant Garamond',Georgia,serif;font-size:21px;font-weight:600}
.rpt-footer-powered{font-size:10px;color:var(--ink4);margin-top:2px}
.rpt-footer-note{font-size:10px;color:var(--ink4);text-align:right}
@media print{.rpt{background:#fff;padding:24px;max-width:none}.rpt-note-hint{display:none}.rpt-section{page-break-inside:avoid}}
`;
