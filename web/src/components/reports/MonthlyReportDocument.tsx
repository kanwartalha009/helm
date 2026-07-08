import { formatMoney, formatRoas } from '@/lib/formatters';
import { REPORT_CSS } from './ReportDocument';
import type { MonthlyKpi, MonthlyReportData, MonthlyReportSection, MonthlySeriesData } from '@/types/reports';

const DEFAULT_COMMENTARY =
  'Summarise the month for the store owner here — what moved, how it landed against targets, and the plan for next month. Editable before you send.';

const RIBBON: Record<string, { label: string; tone: string; desc: string }> = {
  coming: { label: 'Building next', tone: 'blue', desc: 'The data is already synced — this section lands in the next increment.' },
  needs_source: { label: 'Needs a data source', tone: 'amber', desc: 'Blocked on a data probe before it can be built honestly.' },
  no_data: { label: 'Not synced yet', tone: 'grey', desc: 'Run the commerce backfill for this brand and this section lights up.' },
};

/**
 * The monthly client report (agency → store owner). Same white-label design
 * language as ReportDocument (shared REPORT_CSS), but a month-over-month layout:
 * an Overall picture up top, then heatmap MoM tables for the sections that run on
 * data Helm already has, and honest status ribbons for the ones still coming or
 * awaiting a data source — so the whole report structure renders and lights up
 * section by section as each lands.
 */
export function MonthlyReportDocument({
  data,
  editable = false,
  onCommentaryChange,
}: {
  data: MonthlyReportData;
  editable?: boolean;
  onCommentaryChange?: (value: string) => void;
}) {
  const { brand, currency, month, comparison, overall, sections } = data;
  const accent = data.branding?.accent || '#1f6f5c';
  const agencyName = data.branding?.agency_name || 'Roasdriven';
  const footerText = data.branding?.footer_text || 'Powered by novasolution.ae';
  const commentary = data.content?.commentary ?? DEFAULT_COMMENTARY;

  const money = (v: number | null) => (v == null ? '—' : formatMoney(v, currency, { whole: true }));

  return (
    <div className="rpt" style={{ ['--rpt-accent' as never]: accent }}>
      <style>{REPORT_CSS}</style>
      <style>{MONTHLY_CSS}</style>

      <header className="rpt-head">
        <div>
          <div className="rpt-eyebrow">Monthly report · {month.label}</div>
          <h1 className="rpt-brand">{brand.name}</h1>
          <div className="rpt-brand-sub">Online Store · {currency} · before returns unless noted · prepared by {agencyName}</div>
        </div>
        <div className="rpt-meta">
          <div><strong>Period</strong> {month.start} – {month.end}</div>
          <div><strong>vs</strong> {comparison.mom} (MoM) · {comparison.yoy} (YoY)</div>
          <div><strong>Revenue</strong> {money(overall.revenue.value)}</div>
          <div><strong>Currency</strong> {currency}</div>
        </div>
      </header>
      <div className="rpt-rule" />

      {/* Overall picture */}
      <section className="rpt-sec">
        <div className="rpt-sec-head"><span className="rpt-sec-num">00</span><h2>Overall picture</h2></div>
        <div className="rpt-sec-sub">{month.label} close · headline numbers vs {comparison.mom}.</div>
        <div className="rpt-kpis mrt-kpis-4">
          <Kpi label="Blended ROAS" value={formatRoas(overall.blendedRoas.value)} delta={<RatioDelta k={overall.blendedRoas} />} />
          <Kpi label="Revenue" value={money(overall.revenue.value)} delta={<PctDelta k={overall.revenue} />} />
          <Kpi label="Ad spend" value={money(overall.adSpend.value)} delta={<PctDelta k={overall.adSpend} goodUp={false} />} />
          <Kpi label="ROAS · new customer" value="—" note="pending customer-type probe" />
        </div>
        <div className="rpt-narrative">
          <div className="rpt-ai-tag">Commentary{editable ? ' · editable' : ''}</div>
          {editable ? (
            <div className="rpt-note" contentEditable suppressContentEditableWarning onBlur={(e) => onCommentaryChange?.(e.currentTarget.textContent ?? '')}>
              {commentary}
            </div>
          ) : (
            <div className="rpt-note">{commentary}</div>
          )}
        </div>
      </section>

      {/* Sections in the report's canonical order (mockup 1–11). Each renders a
          heat table when ready, or an honest status ribbon otherwise. */}
      <SectionBlock num="01" title="Market revenue — month over month" sub="Revenue grouped into markets/tiers." section={sections.market} currency={currency} />
      <SectionBlock num="02" title="Country revenue — month over month" sub="Revenue by country, rolled to calendar months." section={sections.countryRevenue} currency={currency} />
      <SectionBlock num="03" title="ROAS by country — month over month" sub="Meta country spend ÷ commerce country revenue." section={sections.roasByCountry} currency={currency} />
      <SectionBlock num="04" title="New vs existing customers" sub="New/returning revenue split and new-customer ROAS." section={sections.newVsExisting} currency={currency} />
      <SectionBlock num="05" title="Ad spend by placement" sub="Where the budget ran — Feed, Reels, Stories." section={sections.placement} currency={currency} />
      <SectionBlock num="06" title="Ad spend by gender" sub="Audience concentration by gender." section={sections.gender} currency={currency} />
      <SectionBlock num="07" title="Best categories — month over month" sub="Revenue by product category, month over month." section={sections.categories} currency={currency} />
      <SectionBlock num="08" title="Best sellers — month over month" sub="Top products by revenue, with stock context." section={sections.bestSellers} currency={currency} />
      <SectionBlock num="09" title="Ad spend by landing page × best sellers" sub="Is the ad budget behind the winners?" section={sections.landingSellers} currency={currency} />
      <SectionBlock num="10" title="Web funnel by country" sub="Sessions → cart → checkout → purchase." section={sections.funnelCountry} currency={currency} />
      <SectionBlock num="11" title="Web funnel by landing path" sub="Which entry pages convert." section={sections.funnelLanding} currency={currency} />

      <footer className="rpt-foot">
        <div>
          <div className="rpt-foot-brand">{agencyName}</div>
          <div className="rpt-foot-powered">{footerText}</div>
        </div>
        <div className="rpt-foot-note">{brand.name} · Monthly report · {month.label}</div>
      </footer>
    </div>
  );
}

function SectionBlock({ num, title, sub, section, currency }: { num: string; title: string; sub: string; section: MonthlyReportSection; currency: string }) {
  return (
    <section className="rpt-sec">
      <div className="rpt-sec-head"><span className="rpt-sec-num">{num}</span><h2>{title}</h2></div>
      <div className="rpt-sec-sub">{sub}</div>
      {section.status === 'ready' && section.data
        ? <MoMTable data={section.data} currency={currency} />
        : <Ribbon status={section.status} note={section.note} />}
    </section>
  );
}

// Month-over-month heat table: segments × calendar months, each cell tinted by
// its change vs the prior month, then the trailing total, share and YoY.
function MoMTable({ data, currency }: { data: MonthlySeriesData; currency: string }) {
  const cell = (v: number | null) => (v == null ? '—' : formatMoney(v, currency, { compact: true }));
  const months = data.months;

  return (
    <div className="rpt-tbl-wrap">
      <table className="rpt-tbl mrt-tbl">
        <thead>
          <tr>
            <th>Segment</th>
            {months.map((m) => <th className="r" key={m}>{monthLabel(m)}</th>)}
            <th className="r">Total</th>
            <th className="r">Share</th>
            <th className="r">Δ YoY</th>
          </tr>
        </thead>
        <tbody>
          {data.rows.map((r) => (
            <tr key={r.key}>
              <td className="name"><div className="rpt-dim-label">{r.label}</div></td>
              {months.map((m, i) => {
                const v = r.byMonth[m] ?? 0;
                const prev = i > 0 ? (r.byMonth[months[i - 1]] ?? 0) : null;
                return <td className={`r ${heatClass(v, prev)}`} key={m}>{cell(v)}</td>;
              })}
              <td className="r"><b>{cell(r.total)}</b></td>
              <td className="r">{r.share == null ? '—' : `${(r.share * 100).toFixed(1)}%`}</td>
              <td className="r">{r.deltaYoY == null ? '—' : <span className={r.deltaYoY >= 0 ? 'up' : 'down'}>{r.deltaYoY > 0 ? '+' : ''}{r.deltaYoY.toFixed(0)}%</span>}</td>
            </tr>
          ))}
          {data.other && (
            <tr className="rpt-other">
              <td className="name"><div className="rpt-dim-label">Other</div><div className="rpt-dim-sub">{data.other.count} more</div></td>
              {months.map((m) => <td className="r" key={m}>—</td>)}
              <td className="r">{cell(data.other.total)}</td>
              <td className="r">{data.other.share == null ? '—' : `${(data.other.share * 100).toFixed(1)}%`}</td>
              <td className="r">—</td>
            </tr>
          )}
        </tbody>
        <tfoot>
          <tr>
            <td className="name"><div className="rpt-dim-label">Total</div></td>
            {months.map((m) => <td className="r" key={m} />)}
            <td className="r">{cell(data.total)}</td>
            <td className="r">100%</td>
            <td className="r">—</td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}

function Ribbon({ status, note }: { status: string; note?: string }) {
  const meta = RIBBON[status] ?? RIBBON.coming;
  return (
    <div className={`mrt-ribbon ${meta.tone}`}>
      <span className="mrt-ribbon-tag">{meta.label}</span>
      <span className="mrt-ribbon-desc">{note ?? meta.desc}</span>
    </div>
  );
}

function Kpi({ label, value, delta, note }: { label: string; value: string; delta?: React.ReactNode; note?: string }) {
  return (
    <div className="rpt-kpi">
      <div className="rpt-kpi-l">{label}</div>
      <div className="rpt-kpi-v">{value}</div>
      <div className="rpt-kpi-d">{delta}{note ? <span className="rpt-kpi-note">{delta ? ' · ' : ''}{note}</span> : null}</div>
    </div>
  );
}

function PctDelta({ k, goodUp = true }: { k: MonthlyKpi; goodUp?: boolean }) {
  if (k.deltaPct == null) return <span className="flat">—</span>;
  const up = k.deltaPct > 0.05;
  const down = k.deltaPct < -0.05;
  const cls = !up && !down ? 'flat' : (goodUp ? up : down) ? 'up' : 'down';
  return <span className={cls}>{k.deltaPct > 0 ? '+' : ''}{k.deltaPct.toFixed(1)}% MoM</span>;
}

function RatioDelta({ k }: { k: MonthlyKpi }) {
  if (k.deltaAbs == null) return <span className="flat">—</span>;
  const dir = k.deltaAbs > 0.005 ? 'up' : k.deltaAbs < -0.005 ? 'down' : 'flat';
  return <span className={dir}>{k.deltaAbs > 0 ? '+' : ''}{k.deltaAbs.toFixed(2)}× MoM</span>;
}

function monthLabel(ym: string): string {
  const [y, m] = ym.split('-');
  const name = new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('en-GB', { month: 'short' });
  return `${name} ${y.slice(2)}`;
}

function heatClass(cur: number, prev: number | null): string {
  if (prev == null || prev <= 0) return '';
  const d = (cur - prev) / prev;
  if (d >= 0.5) return 'mrt-g3';
  if (d >= 0.15) return 'mrt-g2';
  if (d > 0.02) return 'mrt-g1';
  if (d <= -0.5) return 'mrt-r3';
  if (d <= -0.15) return 'mrt-r2';
  if (d < -0.02) return 'mrt-r1';
  return '';
}

const MONTHLY_CSS = `
.rpt .mrt-kpis-4{grid-template-columns:repeat(4,1fr)}
.rpt .mrt-kpis-4 .rpt-kpi-v{font-size:30px}
.rpt .mrt-tbl td.name{min-width:180px}
.rpt .mrt-tbl td.r{font-size:11px}
.rpt .mrt-g1{background:#EAF6EE} .rpt .mrt-g2{background:#CFEBD8;color:#14532D} .rpt .mrt-g3{background:#A7DCB8;color:#14532D;font-weight:600}
.rpt .mrt-r1{background:#FCECEC} .rpt .mrt-r2{background:#F7D3D3;color:#7F1D1D} .rpt .mrt-r3{background:#EFB4B4;color:#7F1D1D;font-weight:600}
.rpt .mrt-ribbon{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--paper);border:1px solid var(--line);border-radius:12px;padding:14px 18px}
.rpt .mrt-ribbon.blue{border-left:3px solid var(--blue)} .rpt .mrt-ribbon.amber{border-left:3px solid var(--amber)} .rpt .mrt-ribbon.grey{border-left:3px solid var(--ink-4)}
.rpt .mrt-ribbon-tag{font-family:var(--mono);font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap}
.rpt .mrt-ribbon.blue .mrt-ribbon-tag{color:var(--blue);background:var(--blue-bg)} .rpt .mrt-ribbon.amber .mrt-ribbon-tag{color:var(--amber);background:var(--amber-bg)} .rpt .mrt-ribbon.grey .mrt-ribbon-tag{color:var(--ink-3);background:rgba(0,0,0,.05)}
.rpt .mrt-ribbon-desc{font-size:12px;color:var(--ink-3)}
`;
