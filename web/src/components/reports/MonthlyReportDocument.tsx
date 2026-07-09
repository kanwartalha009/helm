import { useState } from 'react';
import { formatMoney, formatRoas } from '@/lib/formatters';
import { REPORT_CSS } from './ReportDocument';
import type { MonthlyCustomerRow, MonthlyFunnelRow, MonthlyGenderRow, MonthlyKpi, MonthlyLandingRow, MonthlyPlacementRow, MonthlyReportData, MonthlyReportSection, MonthlyRoasData, MonthlySeriesData } from '@/types/reports';

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
  onTargetsChange,
}: {
  data: MonthlyReportData;
  editable?: boolean;
  onCommentaryChange?: (value: string) => void;
  onTargetsChange?: (t: { blendedRoas: number | null; newCustomerRoas: number | null }) => void;
}) {
  const { brand, currency, month, comparison, overall, sections } = data;
  const accent = data.branding?.accent || '#1f6f5c';
  const agencyName = data.branding?.agency_name || 'Roasdriven';
  const footerText = data.branding?.footer_text || 'Powered by novasolution.ae';
  const commentary = data.content?.commentary ?? DEFAULT_COMMENTARY;

  // Agency targets — editable in-app, saved with the share, read-only on the
  // public view. Drive the "Target X · met / near" note on the KPIs.
  const t0 = data.content?.targets;
  const [tBlended, setTBlended] = useState<number | null>(t0?.blendedRoas ?? null);
  const [tNewCust, setTNewCust] = useState<number | null>(t0?.newCustomerRoas ?? null);
  const push = (b: number | null, n: number | null) => onTargetsChange?.({ blendedRoas: b, newCustomerRoas: n });

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
          <Kpi label="Blended ROAS" value={formatRoas(overall.blendedRoas.value)} delta={<RatioDelta k={overall.blendedRoas} />} note={targetNote(overall.blendedRoas.value, tBlended)} />
          <Kpi label="Revenue" value={money(overall.revenue.value)} delta={<PctDelta k={overall.revenue} />} />
          <Kpi label="Ad spend" value={money(overall.adSpend.value)} delta={<PctDelta k={overall.adSpend} goodUp={false} />} />
          <Kpi
            label="ROAS · new customer"
            value={overall.newCustomerRoas ? `~${formatRoas(overall.newCustomerRoas.value)}` : '—'}
            delta={overall.newCustomerRoas ? <RatioDelta k={overall.newCustomerRoas} /> : undefined}
            note={overall.newCustomerRoas
              ? (tNewCust != null ? `Target ${tNewCust} · est` : 'est · new customers × AOV ÷ spend')
              : 'Shopify has no new/returning revenue split'}
          />
        </div>
        {editable && (
          <div className="mrt-targets">
            <span className="mrt-targets-l">Targets</span>
            <label>Blended ROAS <input type="number" step="0.1" defaultValue={tBlended ?? ''} onChange={(e) => { const v = e.target.value === '' ? null : Number(e.target.value); setTBlended(v); push(v, tNewCust); }} /></label>
            <label>New-customer ROAS <input type="number" step="0.1" defaultValue={tNewCust ?? ''} onChange={(e) => { const v = e.target.value === '' ? null : Number(e.target.value); setTNewCust(v); push(tBlended, v); }} /></label>
          </div>
        )}
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
      <div className="mrt-legend">
        <span className="sw mrt-g2"></span><b>Ahead</b>
        <span className="sw mrt-r2"></span><b>Behind</b>
        <span>Outcome columns are shaded by performance; cost metrics (CAC, CPM) are shaded so lower is greener. Counts, spend and share stay unshaded.</span>
      </div>

      <div className="mrt-group"><span>Commerce</span></div>
      <SectionBlock num="01" title="Market revenue" sub="Revenue grouped into markets and tiers, month over month." section={sections.market} currency={currency} />
      <SectionBlock num="02" title="Country revenue" sub="Revenue by country, rolled to calendar months." section={sections.countryRevenue} currency={currency} />
      <SectionBlock num="03" title="Best categories" sub="Revenue by product category, month over month." section={sections.categories} currency={currency} />
      <SectionBlock num="04" title="Best sellers" sub="Top products by revenue, with stock context." section={sections.bestSellers} currency={currency} />

      <div className="mrt-group"><span>Advertising</span></div>
      <SectionBlock num="05" title="ROAS by country" sub="Meta country spend ÷ commerce country revenue, shaded against blended ROAS." section={sections.roasByCountry} currency={currency} />
      <SectionBlock num="06" title="Ad spend by placement" sub="Where the budget ran — Feed, Reels, Stories." section={sections.placement} currency={currency} />
      <SectionBlock num="07" title="Ad spend by gender" sub="Audience concentration by gender." section={sections.gender} currency={currency} />
      <SectionBlock num="08" title="Ad spend by landing page × best sellers" sub="Is the ad budget behind the winners?" section={sections.landingSellers} currency={currency} />

      <div className="mrt-group"><span>Customers</span></div>
      <SectionBlock num="09" title="New vs existing customers" sub="New vs returning counts, retention, CAC and blended ROAS by month." section={sections.newVsExisting} currency={currency} />

      <div className="mrt-group"><span>Web</span></div>
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
      {section.status === 'ready' && section.data ? (
        <MoMTable data={section.data} currency={currency} />
      ) : section.status === 'ready' && section.roas ? (
        <RoasTable data={section.roas} currency={currency} />
      ) : section.status === 'ready' && section.metrics ? (
        <GenderTable rows={section.metrics} currency={currency} />
      ) : section.status === 'ready' && section.products ? (
        <LandingTable rows={section.products} currency={currency} />
      ) : section.status === 'ready' && section.placement ? (
        <PlacementTable rows={section.placement} currency={currency} />
      ) : section.status === 'ready' && section.funnel ? (
        <FunnelTable rows={section.funnel} />
      ) : section.status === 'ready' && section.customers ? (
        <NewVsExistingTable rows={section.customers} currency={currency} />
      ) : (
        <Ribbon status={section.status} note={section.note} />
      )}
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
                const v = r.byMonth[m] ?? null;
                const prev = i > 0 ? (r.byMonth[months[i - 1]] ?? null) : null;
                const heat = v == null ? '' : heatClass(v, prev);
                return <td className={`r ${heat}`} key={m}>{cell(v)}</td>;
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

// ROAS by country, month over month — each cell heat-tinted vs the section's
// blended ROAS. The Meta-spend column reveals the low-spend, high-ROAS scalers.
function RoasTable({ data, currency }: { data: MonthlyRoasData; currency: string }) {
  const money = (v: number | null) => (v == null ? '—' : formatMoney(v, currency, { whole: true }));
  const { months, blended } = data;
  return (
    <>
      <div className="rpt-tbl-wrap">
        <table className="rpt-tbl mrt-tbl">
          <thead>
            <tr>
              <th>Country</th>
              {months.map((m) => <th className="r" key={m}>{monthLabel(m)}</th>)}
              <th className="r">ROAS</th>
              <th className="r">Meta spend</th>
            </tr>
          </thead>
          <tbody>
            {data.rows.map((r) => (
              <tr key={r.key}>
                <td className="name"><div className="rpt-dim-label">{r.label}</div></td>
                {months.map((m) => {
                  const v = r.byMonth[m] ?? null;
                  return <td className={`r ${roasHeat(v, blended)}`} key={m}>{v == null ? '—' : `${v.toFixed(1)}×`}</td>;
                })}
                <td className="r"><b>{r.roas == null ? '—' : `${r.roas.toFixed(1)}×`}</b></td>
                <td className="r">{money(r.spend)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="rpt-cap">Green = above your blended ROAS{blended == null ? '' : ` (${blended.toFixed(1)}×)`}, red = below. Low-spend, high-ROAS countries are the scaling opportunity.</div>
    </>
  );
}

function roasHeat(v: number | null, blended: number | null): string {
  if (v == null || blended == null || blended <= 0) return '';
  const r = v / blended;
  if (r >= 1.5) return 'mrt-g3';
  if (r >= 1.15) return 'mrt-g2';
  if (r > 1.02) return 'mrt-g1';
  if (r <= 0.5) return 'mrt-r3';
  if (r <= 0.85) return 'mrt-r2';
  if (r < 0.98) return 'mrt-r1';
  return '';
}

// Grade an outcome value against the rest of its column, min–max normalised, and
// return a heat class. `dir` flips it for cost metrics (CAC / CPM / CPC) where
// LOWER is better — so a cheap CAC greens and an expensive one reds, never the
// reverse. Only graded when the column has ≥3 comparable values and real spread.
function gradeCol(v: number | null, values: (number | null)[], dir: 'high' | 'low' = 'high'): string {
  if (v == null) return '';
  const xs = values.filter((x): x is number => x != null && Number.isFinite(x));
  if (xs.length < 3) return '';
  const min = Math.min(...xs);
  const max = Math.max(...xs);
  if (max === min) return '';
  let t = (v - min) / (max - min); // 0 = lowest value, 1 = highest value
  if (dir === 'low') t = 1 - t; // invert: lowest value is best
  if (t >= 0.82) return 'mrt-g3';
  if (t >= 0.60) return 'mrt-g2';
  if (t > 0.52) return 'mrt-g1';
  if (t <= 0.18) return 'mrt-r3';
  if (t <= 0.40) return 'mrt-r2';
  if (t < 0.48) return 'mrt-r1';
  return '';
}

// Single-month ad-spend-by-gender table (cost / efficiency / share).
function GenderTable({ rows, currency }: { rows: MonthlyGenderRow[]; currency: string }) {
  const money = (v: number | null) => (v == null ? '—' : formatMoney(v, currency, { whole: true }));
  return (
    <>
      <div className="rpt-tbl-wrap">
        <table className="rpt-tbl mrt-tbl">
          <thead>
            <tr>
              <th>Gender</th>
              <th className="r">Cost</th>
              <th className="r">Clicks</th>
              <th className="r">CPC</th>
              <th className="r">CTR</th>
              <th className="r">CPM</th>
              <th className="r">Share</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.label}>
                <td className="name"><div className="rpt-dim-label">{r.label}</div></td>
                <td className="r">{money(r.cost)}</td>
                <td className="r">{r.clicks.toLocaleString()}</td>
                <td className={`r ${gradeCol(r.cpc, rows.map((x) => x.cpc), 'low')}`}>{money(r.cpc)}</td>
                <td className={`r ${gradeCol(r.ctr, rows.map((x) => x.ctr), 'high')}`}>{r.ctr == null ? '—' : `${r.ctr.toFixed(2)}%`}</td>
                <td className={`r ${gradeCol(r.cpm, rows.map((x) => x.cpm), 'low')}`}>{money(r.cpm)}</td>
                <td className="r">{r.share == null ? '—' : `${(r.share * 100).toFixed(0)}%`}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="rpt-cap">Reach &amp; frequency aren’t stored on breakdowns yet — they arrive when added to the Meta pull.</div>
    </>
  );
}

// Landing page × best sellers — Meta spend vs product revenue, stock, and a read.
function LandingTable({ rows, currency }: { rows: MonthlyLandingRow[]; currency: string }) {
  const money = (v: number | null) => (v == null ? '—' : formatMoney(v, currency, { whole: true }));
  return (
    <div className="rpt-tbl-wrap">
      <table className="rpt-tbl mrt-tbl">
        <thead>
          <tr>
            <th>Product (landing)</th>
            <th className="r">Meta spend</th>
            <th className="r">Revenue</th>
            <th className="r">ROAS</th>
            <th className="r">Units</th>
            <th className="r">Stock</th>
            <th>Read</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.label}>
              <td className="name"><div className="rpt-dim-label">{r.label}</div></td>
              <td className="r">{money(r.spend)}</td>
              <td className="r">{money(r.revenue)}</td>
              <td className={`r ${gradeCol(r.roas, rows.map((x) => x.roas), 'high')}`}>{r.roas == null ? '—' : `${r.roas.toFixed(1)}×`}</td>
              <td className="r">{r.units.toLocaleString()}</td>
              <td className={`r ${r.stock > 0 && r.stock <= 20 ? 'mrt-r1' : ''}`}>{r.stock.toLocaleString()}</td>
              <td>{r.read}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// Ad spend by placement (cost / reach / frequency / efficiency / share).
function PlacementTable({ rows, currency }: { rows: MonthlyPlacementRow[]; currency: string }) {
  const money = (v: number | null) => (v == null ? '—' : formatMoney(v, currency, { whole: true }));
  const hasReach = rows.some((r) => r.reach != null);
  return (
    <>
      <div className="rpt-tbl-wrap">
        <table className="rpt-tbl mrt-tbl">
          <thead>
            <tr>
              <th>Placement</th>
              <th className="r">Cost</th>
              <th className="r">Reach</th>
              <th className="r">Freq</th>
              <th className="r">Clicks</th>
              <th className="r">CPC</th>
              <th className="r">CTR</th>
              <th className="r">CPM</th>
              <th className="r">Share</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.label}>
                <td className="name"><div className="rpt-dim-label">{r.label}</div></td>
                <td className="r">{money(r.cost)}</td>
                <td className="r">{r.reach == null ? '—' : r.reach.toLocaleString()}</td>
                <td className="r">{r.freq == null ? '—' : r.freq.toFixed(2)}</td>
                <td className="r">{r.clicks.toLocaleString()}</td>
                <td className={`r ${gradeCol(r.cpc, rows.map((x) => x.cpc), 'low')}`}>{money(r.cpc)}</td>
                <td className={`r ${gradeCol(r.ctr, rows.map((x) => x.ctr), 'high')}`}>{r.ctr == null ? '—' : `${r.ctr.toFixed(2)}%`}</td>
                <td className={`r ${gradeCol(r.cpm, rows.map((x) => x.cpm), 'low')}`}>{money(r.cpm)}</td>
                <td className="r">{r.share == null ? '—' : `${(r.share * 100).toFixed(0)}%`}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!hasReach && <div className="rpt-cap">Reach &amp; frequency populate after the next sync — the pull now captures reach; existing history shows “—” until re-synced.</div>}
    </>
  );
}

// Web funnel — sessions → cart → checkout → purchase, by country or landing.
function FunnelTable({ rows }: { rows: MonthlyFunnelRow[] }) {
  const n = (v: number) => v.toLocaleString();
  return (
    <div className="rpt-tbl-wrap">
      <table className="rpt-tbl mrt-tbl">
        <thead>
          <tr>
            <th>Segment</th>
            <th className="r">Sessions</th>
            <th className="r">Add to cart</th>
            <th className="r">Checkout</th>
            <th className="r">Purchase</th>
            <th className="r">CVR</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.label}>
              <td className="name"><div className="rpt-dim-label">{r.label}</div></td>
              <td className="r">{n(r.sessions)}</td>
              <td className="r">{n(r.cart)}</td>
              <td className="r">{n(r.checkout)}</td>
              <td className="r">{n(r.purchase)}</td>
              <td className={`r ${gradeCol(r.cvr, rows.map((x) => x.cvr), 'high')}`}>{r.cvr == null ? '—' : `${r.cvr.toFixed(2)}%`}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// New vs existing customers, one row per trailing month. Counts + blended money;
// revenue is NOT split by customer type (no customer_type dimension on ShopifyQL
// sales) — so no per-segment revenue and no new-customer ROAS. CAC = spend ÷ new.
function NewVsExistingTable({ rows, currency }: { rows: MonthlyCustomerRow[]; currency: string }) {
  const n = (v: number) => v.toLocaleString();
  const money = (v: number | null) => (v == null ? '—' : formatMoney(v, currency, { whole: true }));
  return (
    <div className="rpt-tbl-wrap">
      <table className="rpt-tbl mrt-tbl">
        <thead>
          <tr>
            <th>Month</th>
            <th className="r">New</th>
            <th className="r">Returning</th>
            <th className="r">Total</th>
            <th className="r">% ret.</th>
            <th className="r">Revenue</th>
            <th className="r">AOV</th>
            <th className="r">Spend</th>
            <th className="r">ROAS</th>
            <th className="r">ROAS·new<span style={{ opacity: 0.5, fontWeight: 400 }}> est</span></th>
            <th className="r">CAC</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.month}>
              <td className="name"><div className="rpt-dim-label">{r.month}</div></td>
              <td className="r">{n(r.new)}</td>
              <td className="r">{n(r.returning)}</td>
              <td className="r">{n(r.total)}</td>
              <td className={`r ${gradeCol(r.retPct, rows.map((x) => x.retPct), 'high')}`}>{r.retPct == null ? '—' : `${r.retPct.toFixed(1)}%`}</td>
              <td className="r">{money(r.revenue)}</td>
              <td className="r">{money(r.aov)}</td>
              <td className="r">{money(r.spend)}</td>
              <td className={`r ${gradeCol(r.roas, rows.map((x) => x.roas), 'high')}`}>{r.roas == null ? '—' : `${r.roas.toFixed(2)}×`}</td>
              <td className={`r ${gradeCol(r.roasNew, rows.map((x) => x.roasNew), 'high')}`}>{r.roasNew == null ? '—' : `~${r.roasNew.toFixed(2)}×`}</td>
              <td className={`r ${gradeCol(r.cac, rows.map((x) => x.cac), 'low')}`}>{money(r.cac)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="rpt-cap">ROAS·new is an estimate — new customers × AOV ÷ ad spend (uses blended AOV, so it runs slightly high).</div>
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

function targetNote(value: number | null, target: number | null): string | undefined {
  if (target == null) return undefined;
  if (value == null) return `Target ${target}`;
  if (value >= target) return `Target ${target} · met ✓`;
  if (value >= 0.9 * target) return `Target ${target} · ${Math.round((value / target) * 100)}% there`;
  return `Target ${target} · below`;
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
.rpt .mrt-kpis-4{grid-template-columns:repeat(4,1fr);gap:0;border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:22px}
.rpt .mrt-kpis-4 .rpt-kpi{border:none;border-radius:0}
.rpt .mrt-kpis-4 .rpt-kpi + .rpt-kpi{border-left:1px solid var(--line)}
.rpt .mrt-kpis-4 .rpt-kpi-v{font-size:30px}
.rpt .mrt-group{display:flex;align-items:center;gap:14px;margin:52px 0 26px}
.rpt .mrt-group span{font-size:12px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);white-space:nowrap}
.rpt .mrt-group::after{content:'';flex:1;height:1px;background:var(--line)}
.rpt .mrt-legend{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--ink-3);margin:16px 0 30px}
.rpt .mrt-legend .sw{width:20px;height:11px;border-radius:3px;display:inline-block}
.rpt .mrt-legend b{color:var(--ink-2);font-weight:600}
.rpt .mrt-targets{display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin:-8px 0 20px;padding:10px 16px;background:var(--paper);border:1px dashed var(--line-2);border-radius:10px;font-size:12px}
.rpt .mrt-targets-l{font-family:var(--mono);font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-3);font-weight:600}
.rpt .mrt-targets label{display:inline-flex;align-items:center;gap:7px;color:var(--ink-2)}
.rpt .mrt-targets input{width:66px;padding:4px 8px;border:1px solid var(--line-2);border-radius:6px;font-family:var(--mono);font-size:12px;background:var(--paper);color:var(--ink)}
.rpt .mrt-tbl td.name{min-width:180px}
.rpt .mrt-tbl td.r{font-size:11px}
.rpt .mrt-g1{background:#f2f7f3} .rpt .mrt-g2{background:#e3efe7;color:#1c6b45} .rpt .mrt-g3{background:#d2e7da;color:#1c6b45;font-weight:600}
.rpt .mrt-r1{background:#fbf3f2} .rpt .mrt-r2{background:#f4e4e1;color:#a83a31} .rpt .mrt-r3{background:#eccfc9;color:#a83a31;font-weight:600}
.rpt .mrt-ribbon{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--paper);border:1px solid var(--line);border-radius:12px;padding:14px 18px}
.rpt .mrt-ribbon.blue{border-left:3px solid var(--blue)} .rpt .mrt-ribbon.amber{border-left:3px solid var(--amber)} .rpt .mrt-ribbon.grey{border-left:3px solid var(--ink-4)}
.rpt .mrt-ribbon-tag{font-family:var(--mono);font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap}
.rpt .mrt-ribbon.blue .mrt-ribbon-tag{color:var(--blue);background:var(--blue-bg)} .rpt .mrt-ribbon.amber .mrt-ribbon-tag{color:var(--amber);background:var(--amber-bg)} .rpt .mrt-ribbon.grey .mrt-ribbon-tag{color:var(--ink-3);background:rgba(0,0,0,.05)}
.rpt .mrt-ribbon-desc{font-size:12px;color:var(--ink-3)}
`;
