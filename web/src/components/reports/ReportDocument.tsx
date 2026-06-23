import { formatMoney, formatRoas } from '@/lib/formatters';
import type {
  CommerceRow,
  CommerceSection,
  CommerceTrend,
  OverallPerformanceReportData,
} from '@/types/reports';

const PLATFORM_LABEL: Record<string, string> = {
  meta: 'Meta Ads',
  google: 'Google Ads',
  tiktok: 'TikTok Ads',
};

const DEFAULT_COMMENTARY =
  'Summarise the period for the client here — what moved, why, and the plan for next period. This is editable before you send, and is where the analyst narrative will be generated once the AI layer is on.';

// trend → region status badge / product signal. Rules-driven (no LLM): the
// classification comes straight from the comparison delta.
const STATUS: Record<string, { label: string; tone: Tone }> = {
  dead: { label: 'Dead zone', tone: 'dead' },
  wounded: { label: 'Wounded', tone: 'wound' },
  stable: { label: 'Holding', tone: 'hold' },
  growing: { label: 'Recovered', tone: 'win' },
  new: { label: 'New', tone: 'new' },
};
const SIGNAL: Record<string, { label: string; tone: Tone }> = {
  dead: { label: 'Cut', tone: 'dead' },
  wounded: { label: 'Review', tone: 'wound' },
  stable: { label: 'Steady', tone: 'hold' },
  growing: { label: 'Scale', tone: 'win' },
  new: { label: 'New', tone: 'new' },
};
const MATRIX_CELL: Record<string, { title: string; tone: Tone; desc: string }> = {
  dead: { title: 'Dead zones', tone: 'dead', desc: 'Fell sharply vs last period' },
  wounded: { title: 'Wounded', tone: 'wound', desc: 'Declining, but slower' },
  new: { title: 'New', tone: 'new', desc: 'First orders this period' },
  growing: { title: 'Recovered / growing', tone: 'win', desc: 'Up vs last period' },
};
const ACTION_META: Record<string, { tone: Tone; label: string }> = {
  stop: { tone: 'dead', label: '▲ Stop' },
  fix: { tone: 'wound', label: '◆ Fix' },
  scale: { tone: 'win', label: '★ Scale' },
};

type Tone = 'dead' | 'wound' | 'win' | 'new' | 'hold';

/**
 * The white-label client report — Roasdriven's native agency design (Fraunces
 * display, Inter body, JetBrains Mono figures, the agency accent applied as a
 * CSS variable). Brand on top, agency footer below. Print-friendly.
 *
 * The structure mirrors the agency report format: executive summary, revenue vs
 * ad spend, by-platform, then the slice 2.1 commerce sections (region matrix +
 * status table, product signals, collection bars). Every status badge / matrix
 * count is rules-driven from real comparison data. The narrative slots
 * (analyst summary, action cards, per-section reads) render the LLM's prose
 * when present (slice 2.3) and stay out of the way until then — so a report is
 * clean to send today and richer once the AI layer is on.
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
  const content = data.content ?? undefined;
  const hasComparison = !!comparison;

  const accent = data.branding?.accent || '#1f6f5c';
  const agencyName = data.branding?.agency_name || 'Roasdriven';
  const footerText = data.branding?.footer_text || 'Powered by novasolution.ae';
  const initialCommentary = content?.commentary ?? DEFAULT_COMMENTARY;

  // Commerce sections present this period, numbered contiguously from 03.
  const commerce: Array<{ id: 'region' | 'product' | 'collection'; section: CommerceSection }> = [];
  if (data.byRegion) commerce.push({ id: 'region', section: data.byRegion });
  if (data.byProduct) commerce.push({ id: 'product', section: data.byProduct });
  if (data.byCategory) commerce.push({ id: 'collection', section: data.byCategory });

  const fmt = (v: number | null, kind: 'money' | 'ratio' | 'int' = 'money'): string =>
    v === null ? '—' : kind === 'ratio' ? formatRoas(v) : kind === 'int' ? v.toLocaleString() : formatMoney(v, currency);
  const kpiMoney = (v: number | null): string => (v === null ? '—' : formatMoney(v, currency, { whole: true }));

  return (
    <div className="rpt" style={{ ['--rpt-accent' as never]: accent }}>
      <style>{REPORT_CSS}</style>

      <header className="rpt-head">
        <div>
          <div className="rpt-eyebrow">Performance &amp; strategy report · {period.label}</div>
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
            <div className="rpt-note">{content?.commentary ?? DEFAULT_COMMENTARY}</div>
          )}
        </div>
        {content?.actions && content.actions.length > 0 && (
          <div className="rpt-actions">
            {content.actions.map((a, i) => (
              <div className={`rpt-act ${ACTION_META[a.kind]?.tone ?? 'hold'}`} key={i}>
                <div className="rpt-act-k">{ACTION_META[a.kind]?.label ?? a.kind}</div>
                <div className="rpt-act-t"><b>{a.title}</b> {a.body}</div>
              </div>
            ))}
          </div>
        )}
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

      {commerce.map((c, i) => {
        const num = String(3 + i).padStart(2, '0');
        if (c.id === 'region') {
          return <RegionSection key={c.id} num={num} section={c.section} currency={currency} hasComparison={hasComparison} read={content?.regionRead} />;
        }
        if (c.id === 'product') {
          return <ProductSection key={c.id} num={num} section={c.section} currency={currency} hasComparison={hasComparison} read={content?.productRead} />;
        }
        return <CollectionSection key={c.id} num={num} section={c.section} currency={currency} read={content?.collectionRead} />;
      })}

      <footer className="rpt-foot">
        <div>
          <div className="rpt-foot-brand">{agencyName}</div>
          <div className="rpt-foot-powered">{footerText}</div>
        </div>
        <div className="rpt-foot-note">{brand.name} · Performance &amp; strategy · {period.start} – {period.end}</div>
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

function Badge({ tone, children }: { tone: Tone; children: React.ReactNode }) {
  return <span className={`rpt-badge b-${tone}`}>{children}</span>;
}

const TREND_PCT = (pct: number | null): string => (pct === null ? 'new' : `${pct > 0 ? '+' : ''}${pct.toFixed(0)}%`);

function SectionRead({ tag, text }: { tag: string; text?: string }) {
  if (!text) return null;
  return (
    <div className="rpt-narrative" style={{ marginTop: 18 }}>
      <div className="rpt-ai-tag">{tag}</div>
      <div className="rpt-note">{text}</div>
    </div>
  );
}

function RegionSection({
  num,
  section,
  currency,
  hasComparison,
  read,
}: {
  num: string;
  section: CommerceSection;
  currency: string;
  hasComparison: boolean;
  read?: string;
}) {
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  return (
    <section className="rpt-sec">
      <div className="rpt-sec-head"><span className="rpt-sec-num">{num}</span><h2>Performance by region</h2></div>
      <div className="rpt-sec-sub">Where orders came from this period vs last, classified by 30-day trajectory.</div>

      {section.matrix && (
        <div className="rpt-matrix">
          {section.matrix.map((cell) => {
            const meta = MATRIX_CELL[cell.bucket];
            return (
              <div className={`rpt-mx ${meta.tone}`} key={cell.bucket}>
                <div className="rpt-mx-l">{meta.title}</div>
                <div className="rpt-mx-n">{cell.count}</div>
                <div className="rpt-mx-d">{meta.desc}</div>
                {cell.samples.length > 0 && (
                  <div className="rpt-pills">
                    {cell.samples.map((s) => (
                      <span className="rpt-pill" key={s.label}>{s.label} {TREND_PCT(s.deltaPct)}</span>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      <div className="rpt-tbl-wrap">
        <table className="rpt-tbl rpt-tbl-dim">
          <thead>
            <tr>
              <th>Market</th>
              {hasComparison && <th>Status</th>}
              <th className="r">Revenue</th>
              <th className="r">Share</th>
              <th className="r">Orders</th>
              <th className="r">AOV</th>
              <th className="r">{hasComparison ? 'Δ rev' : ''}</th>
            </tr>
          </thead>
          <tbody>
            {section.rows.map((r) => (
              <tr key={r.key} className={rowTint(r.trend)}>
                <td className="name">
                  <div className="rpt-dim-label">{r.label}</div>
                  <div className="rpt-bar"><span style={{ width: `${Math.round((r.share ?? 0) * 100)}%` }} /></div>
                </td>
                {hasComparison && <td>{r.trend && STATUS[r.trend] ? <Badge tone={STATUS[r.trend].tone}>{STATUS[r.trend].label}</Badge> : '—'}</td>}
                <td className="r">{money(r.revenue)}</td>
                <td className="r">{r.share === null ? '—' : `${(r.share * 100).toFixed(1)}%`}</td>
                <td className="r">{r.orders.toLocaleString()}</td>
                <td className="r">{money(r.aov)}</td>
                <td className="r">{hasComparison ? <Delta pct={r.deltaPct} abs={null} kind="money" /> : '—'}</td>
              </tr>
            ))}
            {section.other && (
              <tr className="rpt-other">
                <td className="name"><div className="rpt-dim-label">Other</div><div className="rpt-dim-sub">{section.other.count} more markets</div></td>
                {hasComparison && <td>—</td>}
                <td className="r">{money(section.other.revenue)}</td>
                <td className="r">{section.other.share === null ? '—' : `${(section.other.share * 100).toFixed(1)}%`}</td>
                <td className="r">{section.other.orders.toLocaleString()}</td>
                <td className="r">—</td>
                <td className="r">—</td>
              </tr>
            )}
          </tbody>
          <tfoot>
            <tr>
              <td className="name"><div className="rpt-dim-label">Total</div></td>
              {hasComparison && <td />}
              <td className="r">{money(section.total.revenue)}</td>
              <td className="r">100%</td>
              <td className="r">{section.total.orders.toLocaleString()}</td>
              <td className="r">—</td>
              <td className="r">—</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <SectionRead tag="Regional read" text={read} />
    </section>
  );
}

function ProductSection({
  num,
  section,
  currency,
  hasComparison,
  read,
}: {
  num: string;
  section: CommerceSection;
  currency: string;
  hasComparison: boolean;
  read?: string;
}) {
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  return (
    <section className="rpt-sec">
      <div className="rpt-sec-head"><span className="rpt-sec-num">{num}</span><h2>Performance by product</h2></div>
      <div className="rpt-sec-sub">Top sellers this period with momentum and a merchandising signal.</div>
      <div className="rpt-tbl-wrap">
        <table className="rpt-tbl rpt-tbl-dim">
          <thead>
            <tr>
              <th>Product</th>
              <th className="r">Revenue</th>
              <th className="r">Share</th>
              <th className="r">Orders</th>
              <th className="r">AOV</th>
              <th className="r">{hasComparison ? 'Δ rev' : ''}</th>
              {hasComparison && <th>Signal</th>}
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
                <td className="r">{r.share === null ? '—' : `${(r.share * 100).toFixed(1)}%`}</td>
                <td className="r">{r.orders.toLocaleString()}</td>
                <td className="r">{money(r.aov)}</td>
                <td className="r">{hasComparison ? <Delta pct={r.deltaPct} abs={null} kind="money" /> : '—'}</td>
                {hasComparison && <td>{r.trend && SIGNAL[r.trend] ? <Badge tone={SIGNAL[r.trend].tone}>{SIGNAL[r.trend].label}</Badge> : '—'}</td>}
              </tr>
            ))}
            {section.other && (
              <tr className="rpt-other">
                <td className="name"><div className="rpt-dim-label">Other</div><div className="rpt-dim-sub">{section.other.count} more products</div></td>
                <td className="r">{money(section.other.revenue)}</td>
                <td className="r">{section.other.share === null ? '—' : `${(section.other.share * 100).toFixed(1)}%`}</td>
                <td className="r">{section.other.orders.toLocaleString()}</td>
                <td className="r">—</td>
                <td className="r">—</td>
                {hasComparison && <td>—</td>}
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
              {hasComparison && <td />}
            </tr>
          </tfoot>
        </table>
      </div>
      <div className="rpt-cap">Revenue is allocated per line item — an order spanning several products appears under each, so product orders can exceed total orders.</div>
      <SectionRead tag="Merchandising read" text={read} />
    </section>
  );
}

function CollectionSection({
  num,
  section,
  currency,
  read,
}: {
  num: string;
  section: CommerceSection;
  currency: string;
  read?: string;
}) {
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  const maxShare = section.rows.reduce((m, r) => Math.max(m, r.share ?? 0), 0) || 1;
  return (
    <section className="rpt-sec">
      <div className="rpt-sec-head"><span className="rpt-sec-num">{num}</span><h2>Performance by collection</h2></div>
      <div className="rpt-sec-sub">Revenue share and momentum by category (Shopify product type — the closest native equivalent to collection).</div>
      <div className="rpt-bars">
        {section.rows.map((r) => (
          <div className="rpt-bar-row" key={r.key}>
            <div className="rpt-bar-l">{r.label}</div>
            <div className="rpt-bar-track"><div className="rpt-bar-fill" style={{ width: `${Math.round(((r.share ?? 0) / maxShare) * 100)}%` }} /></div>
            <div className="rpt-bar-n">{money(r.revenue)}</div>
            <div className="rpt-bar-p"><Delta pct={r.deltaPct} abs={null} kind="money" /></div>
          </div>
        ))}
        {section.other && (
          <div className="rpt-bar-row">
            <div className="rpt-bar-l" style={{ color: 'var(--ink-3)' }}>Other ({section.other.count})</div>
            <div className="rpt-bar-track"><div className="rpt-bar-fill" style={{ width: `${Math.round(((section.other.share ?? 0) / maxShare) * 100)}%`, background: 'var(--ink-4)' }} /></div>
            <div className="rpt-bar-n">{money(section.other.revenue)}</div>
            <div className="rpt-bar-p">—</div>
          </div>
        )}
      </div>
      <SectionRead tag="Merchandising read" text={read} />
    </section>
  );
}

function rowTint(trend: CommerceTrend): string {
  if (trend === 'dead') return 'row-dead';
  if (trend === 'wounded') return 'row-wound';
  if (trend === 'growing') return 'row-win';
  return '';
}

const REPORT_CSS = `
.rpt{--ink:#161514;--ink-2:#45433f;--ink-3:#7a766f;--ink-4:#a8a39a;--line:#e7e4dd;--line-2:#d6d2c8;
  --paper:#fff;--bg:#f7f6f3;--red:#bb2d2d;--red-bg:#fbf1f0;--red-line:#eecac6;--green:#1f7a48;--green-bg:#eef8f1;--green-line:#bfe0c8;
  --amber:#a8730a;--amber-bg:#fbf5e8;--amber-line:#ecdcb4;--blue:#1f5fa6;--blue-bg:#eef4fb;--blue-line:#c4d8ef;
  --accent:var(--rpt-accent,#1f6f5c);--accent-soft:#eaf3f0;
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
.rpt-sec-head{display:flex;align-items:baseline;gap:13px;margin-bottom:8px}
.rpt-sec-num{font-family:var(--mono);font-size:12px;color:var(--accent);letter-spacing:.1em;font-weight:600}
.rpt-sec-head h2{font-family:var(--display);font-size:30px;font-weight:600;letter-spacing:-.015em;margin:0}
.rpt-sec-sub{font-size:12.5px;color:var(--ink-3);margin:0 0 22px;max-width:760px}
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
.rpt-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px}
.rpt-act{border-radius:12px;padding:15px 17px;border:1px solid}
.rpt-act.dead{background:var(--red-bg);border-color:var(--red-line)} .rpt-act.wound{background:var(--amber-bg);border-color:var(--amber-line)} .rpt-act.win{background:var(--green-bg);border-color:var(--green-line)} .rpt-act.hold{background:var(--paper);border-color:var(--line)}
.rpt-act-k{font-family:var(--mono);font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;margin-bottom:7px}
.rpt-act.dead .rpt-act-k{color:var(--red)} .rpt-act.wound .rpt-act-k{color:var(--amber)} .rpt-act.win .rpt-act-k{color:var(--green)} .rpt-act.hold .rpt-act-k{color:var(--ink-3)}
.rpt-act-t{font-size:12.5px;line-height:1.5;color:var(--ink-2)} .rpt-act-t b{color:var(--ink);font-weight:600}
.rpt-matrix{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:22px}
.rpt-mx{border-radius:12px;border:1px solid;padding:16px 18px}
.rpt-mx.dead{background:var(--red-bg);border-color:var(--red-line)} .rpt-mx.wound{background:var(--amber-bg);border-color:var(--amber-line)} .rpt-mx.new{background:var(--blue-bg);border-color:var(--blue-line)} .rpt-mx.win{background:var(--green-bg);border-color:var(--green-line)}
.rpt-mx-l{font-size:9.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:6px}
.rpt-mx.dead .rpt-mx-l{color:var(--red)} .rpt-mx.wound .rpt-mx-l{color:var(--amber)} .rpt-mx.new .rpt-mx-l{color:var(--blue)} .rpt-mx.win .rpt-mx-l{color:var(--green)}
.rpt-mx-n{font-family:var(--display);font-size:38px;font-weight:600;line-height:1}
.rpt-mx.dead .rpt-mx-n{color:var(--red)} .rpt-mx.wound .rpt-mx-n{color:var(--amber)} .rpt-mx.new .rpt-mx-n{color:var(--blue)} .rpt-mx.win .rpt-mx-n{color:var(--green)}
.rpt-mx-d{font-size:10.5px;color:var(--ink-3);line-height:1.5;margin-top:6px}
.rpt-pills{display:flex;flex-wrap:wrap;gap:4px;margin-top:10px}
.rpt-pill{font-size:9.5px;padding:3px 7px;border-radius:20px;font-weight:600;background:rgba(0,0,0,.05);color:var(--ink-2)}
.rpt-tbl-wrap{background:var(--paper);border:1px solid var(--line);border-radius:13px;overflow:hidden}
.rpt-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.rpt-tbl thead tr{background:var(--ink);color:#fff}
.rpt-tbl th{padding:12px 18px;text-align:left;font-size:9.5px;font-weight:600;letter-spacing:.1em;text-transform:uppercase}
.rpt-tbl th.r{text-align:right}
.rpt-tbl td{padding:12px 18px;border-bottom:1px solid var(--line)}
.rpt-tbl td.r{text-align:right;font-family:var(--mono);font-size:11.5px}
.rpt-tbl td.name{font-weight:600}
.rpt-tbl td.prior{color:var(--ink-3)}
.rpt-tbl tbody tr:last-child td{border-bottom:1px solid var(--line)}
.rpt-tbl .row-dead{background:#fdf6f5} .rpt-tbl .row-win{background:#f4fbf6} .rpt-tbl .row-wound{background:#fdfaf2}
.rpt-badge{display:inline-block;font-size:9px;font-weight:700;padding:3px 8px;border-radius:5px;letter-spacing:.04em;text-transform:uppercase}
.rpt-badge.b-dead{background:rgba(187,45,45,.12);color:var(--red)} .rpt-badge.b-wound{background:rgba(168,115,10,.14);color:var(--amber)}
.rpt-badge.b-win{background:rgba(31,122,72,.12);color:var(--green)} .rpt-badge.b-new{background:rgba(31,95,166,.12);color:var(--blue)} .rpt-badge.b-hold{background:rgba(0,0,0,.06);color:var(--ink-3)}
.rpt-tbl-dim td.name{min-width:200px}
.rpt-dim-label{font-weight:600;font-size:13px}
.rpt-dim-sub{font-size:10px;color:var(--ink-4);margin-top:2px}
.rpt-bar{height:3px;background:var(--line);border-radius:2px;margin-top:6px;max-width:200px}
.rpt-bar span{display:block;height:100%;background:var(--accent);border-radius:2px;min-width:2px}
.rpt-other td{color:var(--ink-3)}
.rpt-tbl-dim tfoot td{padding:12px 18px;border-top:1.5px solid var(--accent);font-weight:700;background:var(--accent-soft)}
.rpt-tbl-dim tfoot td.r{text-align:right;font-family:var(--mono);font-size:11.5px}
.rpt-cap{font-size:10.5px;color:var(--ink-4);margin-top:10px;line-height:1.5;font-style:italic}
.rpt-bars{background:var(--paper);border:1px solid var(--line);border-radius:13px;padding:20px 24px}
.rpt-bar-row{display:grid;grid-template-columns:160px 1fr 100px 64px;gap:12px;align-items:center;padding:8px 0;font-size:12px}
.rpt-bar-row+.rpt-bar-row{border-top:1px solid var(--line)}
.rpt-bar-l{color:var(--ink-2);font-weight:500}
.rpt-bar-track{height:8px;border-radius:5px;background:#efece6;overflow:hidden}
.rpt-bar-fill{height:100%;border-radius:5px;background:var(--accent)}
.rpt-bar-n{font-family:var(--mono);text-align:right;font-size:11.5px;font-weight:500}
.rpt-bar-p{font-size:10.5px;text-align:right;font-weight:600}
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
@media print{.rpt{background:#fff;padding:24px;max-width:none}.rpt-sec{page-break-inside:avoid}.rpt-matrix{page-break-inside:avoid}}
`;
