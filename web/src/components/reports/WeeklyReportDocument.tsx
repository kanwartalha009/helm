import { formatMoney, formatRoas } from '@/lib/formatters';
import { NarrativeBlocks } from './NarrativeBlocks';
import { EarlySignalTag, REPORT_CSS, VerdictWindowCaption } from './ReportDocument';
import type { NarrativeBlocksShape, WeeklyKpi, WeeklyReportData } from '@/types/reports';

const PLATFORM_LABEL: Record<string, string> = {
  meta: 'Meta Ads',
  google: 'Google Ads',
  tiktok: 'TikTok Ads',
};

const DEFAULT_COMMENTARY =
  'Summarise the week for the client here — what moved, why, and the focus for next week. Editable before you send.';

const ACTION_META: Record<string, { tone: string; label: string }> = {
  stop: { tone: 'dead', label: '▲ Stop' },
  fix: { tone: 'wound', label: '◆ Fix' },
  scale: { tone: 'win', label: '★ Scale' },
};

/**
 * The Monday client email as a document (spec §2 "Weekly ad report") — a
 * compact one-to-two page snapshot of the last complete Mon–Sun week. Same
 * white-label design language as ReportDocument (shared REPORT_CSS): header,
 * KPI row with WoW deltas (plus same-week-last-year when the brand has rows
 * that far back), a plain-div 7-day revenue/spend chart, the platform split,
 * campaign movers, and the rules-derived action plan. Narrative blocks and the
 * commentary are editable agency content, exactly like the other reports.
 */
export function WeeklyReportDocument({
  data,
  editable = false,
  onCommentaryChange,
  generatingNarrative = false,
  onGenerateNarrative,
  onNarrativeBlockChange,
}: {
  data: WeeklyReportData;
  editable?: boolean;
  onCommentaryChange?: (value: string) => void;
  generatingNarrative?: boolean;
  onGenerateNarrative?: () => void;
  onNarrativeBlockChange?: (key: keyof NarrativeBlocksShape, value: string) => void;
}) {
  const { brand, currency, week, comparison, kpis, dailySeries, spendByPlatform, campaignMovers, actions } = data;
  const marketAlerts = data.marketAlerts ?? [];
  const email = data.email ?? null;
  const content = data.content ?? undefined;

  const accent = data.branding?.accent || '#1f6f5c';
  const agencyName = data.branding?.agency_name || 'Roasdriven';
  const footerText = data.branding?.footer_text || 'Powered by novasolution.ae';
  const initialCommentary = content?.commentary ?? DEFAULT_COMMENTARY;

  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  const kpiMoney = (v: number | null) => (v === null ? '—' : formatMoney(v, currency, { whole: true }));
  const roas = (v: number | null) => (v === null ? '—' : `${v.toFixed(2)}×`);

  let secN = 1;
  const nextNum = () => String(secN++).padStart(2, '0');

  return (
    <div className="rpt" style={{ ['--rpt-accent' as never]: accent }}>
      <style>{REPORT_CSS}</style>
      <style>{WEEKLY_CSS}</style>

      <header className="rpt-head">
        <div>
          <div className="rpt-eyebrow">Weekly performance · {week.label}</div>
          <h1 className="rpt-brand">{brand.name}</h1>
          <div className="rpt-brand-sub">
            Last complete week (Mon–Sun) vs the week before
            {comparison.lastYear ? ' · same week last year alongside' : ''} · prepared by {agencyName}
          </div>
        </div>
        <div className="rpt-meta">
          <div><strong>Week</strong> {week.start} – {week.end}</div>
          <div><strong>vs previous</strong> {comparison.previous.start} – {comparison.previous.end}</div>
          {comparison.lastYear && <div><strong>vs last year</strong> {comparison.lastYear.start} – {comparison.lastYear.end}</div>}
          <div><strong>Revenue</strong> {kpiMoney(kpis.totalRevenue.value)}</div>
          <div><strong>Currency</strong> {currency}</div>
        </div>
      </header>
      <div className="rpt-rule" />

      <section className="rpt-sec">
        <div className="rpt-sec-head"><span className="rpt-sec-num">00</span><h2>The week in numbers</h2></div>
        <div className="rpt-kpis">
          <Kpi label="Total revenue" value={kpiMoney(kpis.totalRevenue.value)} k={kpis.totalRevenue} kind="money" />
          <Kpi label="Ad spend" value={kpiMoney(kpis.adSpend.value)} k={kpis.adSpend} kind="money" />
          <Kpi
            label="Blended ROAS"
            value={formatRoas(kpis.blendedRoas.value)}
            k={kpis.blendedRoas}
            kind="ratio"
            note={data.spendComplete ? undefined : 'connected platforms only'}
          />
          <Kpi label="Orders" value={kpis.orders.value === null ? '—' : kpis.orders.value.toLocaleString()} k={kpis.orders} kind="int" />
          <Kpi label="Avg order value" value={kpiMoney(kpis.aov.value)} k={kpis.aov} kind="money" />
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
        <NarrativeBlocks
          narrative={data.narrative}
          sharedBlocks={content?.narrativeBlocks ?? null}
          editable={editable}
          llmEnabled={data.llm?.enabled ?? false}
          generating={generatingNarrative}
          onGenerate={onGenerateNarrative}
          onBlockChange={onNarrativeBlockChange}
        />
      </section>

      {dailySeries.length > 0 && (
        <section className="rpt-sec">
          <div className="rpt-sec-head"><span className="rpt-sec-num">{nextNum()}</span><h2>Day by day</h2></div>
          <div className="rpt-sec-sub">Revenue per day with the ad spend behind it. A hatched day hasn't finished syncing — shown as pending, never as €0.</div>
          <DailyChart days={dailySeries} currency={currency} />
        </section>
      )}

      <section className="rpt-sec">
        <div className="rpt-sec-head"><span className="rpt-sec-num">{nextNum()}</span><h2>Spend by platform</h2></div>
        <div className="rpt-plat-grid">
          {spendByPlatform.map((p) => (
            <div className="rpt-plat" key={p.platform}>
              <div className="rpt-plat-name">
                {PLATFORM_LABEL[p.platform] ?? p.platform}
                <span className={`rpt-tag ${p.connected ? 'live' : 'pending'}`}>{p.connected ? 'Live' : 'Not connected'}</span>
              </div>
              {p.connected ? (
                <div className="rpt-plat-spend">{money(p.spend)}</div>
              ) : (
                <div className="rpt-plat-empty">No connection on this brand yet — connect it and spend appears here, never as €0.</div>
              )}
            </div>
          ))}
        </div>
      </section>

      {email && (
        <section className="rpt-sec">
          <div className="rpt-sec-head"><span className="rpt-sec-num">{nextNum()}</span><h2>Email revenue</h2></div>
          <div className="rpt-sec-sub">
            {email.label} — shown as its own channel. It is <b>not</b> added to store or ad revenue.
          </div>

          <div className="rpt-kpis">
            <div className="rpt-kpi">
              <div className="rpt-kpi-l">Email revenue</div>
              <div className="rpt-kpi-v">{money(email.revenue)}</div>
              <div className="rpt-kpi-d"><span className="rpt-kpi-note">Klaviyo-attributed</span></div>
            </div>
            <div className="rpt-kpi">
              <div className="rpt-kpi-l">Email orders</div>
              <div className="rpt-kpi-v">{email.orders.toLocaleString()}</div>
              <div className="rpt-kpi-d"><span className="rpt-kpi-note">placed orders</span></div>
            </div>
            <div className="rpt-kpi">
              <div className="rpt-kpi-l">vs store revenue</div>
              <div className="rpt-kpi-v">{email.shareOfStore === null ? '—' : `${email.shareOfStore}%`}</div>
              <div className="rpt-kpi-d"><span className="rpt-kpi-note">ratio, not a split</span></div>
            </div>
          </div>

          {email.topSources.length > 0 && (
            <div className="rpt-tbl-wrap">
              <table className="rpt-tbl rpt-tbl-dim">
                <thead>
                  <tr>
                    <th>Flow / campaign</th>
                    <th>Type</th>
                    <th className="r">Revenue</th>
                    <th className="r">Orders</th>
                  </tr>
                </thead>
                <tbody>
                  {email.topSources.map((s) => (
                    <tr key={`${s.source}-${s.id}`}>
                      <td className="name"><div className="rpt-dim-label">{s.name ?? s.id}</div></td>
                      <td>{s.source === 'flow' ? 'Flow' : 'Campaign'}</td>
                      <td className="r">{money(s.revenue)}</td>
                      <td className="r">{s.orders.toLocaleString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* The attribution honesty box — mandatory wherever a Klaviyo number renders. */}
          <div className="rpt-cap">{email.honestyBox}</div>
        </section>
      )}

      {campaignMovers.length > 0 && (
        <section className="rpt-sec">
          <div className="rpt-sec-head"><span className="rpt-sec-num">{nextNum()}</span><h2>Campaign movers</h2></div>
          <div className="rpt-sec-sub">The week's biggest campaigns by spend and how they moved week over week.</div>
          <div className="rpt-tbl-wrap">
            <table className="rpt-tbl rpt-tbl-dim">
              <thead>
                <tr>
                  <th>Campaign</th>
                  <th>Platform</th>
                  <th className="r">Spend</th>
                  <th className="r">Δ spend</th>
                  <th className="r">Revenue</th>
                  <th className="r">ROAS</th>
                  <th className="r">Δ ROAS</th>
                </tr>
              </thead>
              <tbody>
                {campaignMovers.map((c) => (
                  <tr key={`${c.platform}-${c.id}`}>
                    <td className="name"><div className="rpt-dim-label">{c.name}</div></td>
                    <td>{PLATFORM_LABEL[c.platform] ?? c.platform}</td>
                    <td className="r">{money(c.spend)}</td>
                    <td className="r"><PctDelta pct={c.spendDelta} /></td>
                    <td className="r">{money(c.revenue)}</td>
                    <td className="r">{roas(c.roas)}</td>
                    <td className="r"><AbsDelta abs={c.roasDelta} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="rpt-cap">Revenue and ROAS are platform-attributed (each platform's own model), so they don't sum to store revenue — the blended ROAS above is the true figure.</div>
        </section>
      )}

      {actions.length > 0 && (
        <section className="rpt-sec">
          <div className="rpt-sec-head"><span className="rpt-sec-num">{nextNum()}</span><h2>This week's action plan</h2></div>
          <div className="rpt-sec-sub">Rules-derived from the week's campaign data — stop the waste, cap the scalers, fund the winners.</div>
          <div className="rpt-actions rpt-actions-stack">
            {actions.map((a, i) => (
              <div className={`rpt-act ${ACTION_META[a.kind]?.tone ?? 'hold'}`} key={i}>
                <div className="rpt-act-k">
                  {ACTION_META[a.kind]?.label ?? a.kind}
                  <span className="wk-act-plat"> · {PLATFORM_LABEL[a.platform] ?? a.platform}</span>
                </div>
                <div className="rpt-act-t">
                  <b>{a.title}</b> — {a.body}
                  {a.confidence === 'early' && <EarlySignalTag />}
                </div>
              </div>
            ))}
          </div>
          {/* The weekly window is always the complete Mon–Sun week — 7 days. */}
          <VerdictWindowCaption windowDays={7} />
        </section>
      )}

      {marketAlerts.length > 0 && (
        <section className="rpt-sec">
          <div className="rpt-sec-head"><span className="rpt-sec-num">{nextNum()}</span><h2>Competitor watch</h2></div>
          <div className="rpt-sec-sub">What tracked competitors in this brand's niche did this week. Proxy — public Ad Library signals (reach, longevity, new creatives), never spend or ROAS.</div>
          <div className="rpt-actions rpt-actions-stack">
            {marketAlerts.map((a, i) => (
              <div className={`rpt-act ${a.severity === 'warn' ? 'wound' : 'hold'}`} key={i}>
                <div className="rpt-act-k">
                  {a.severity === 'warn' ? '◆ Watch' : '● Signal'}
                  <span className="wk-act-plat"> · {a.pageName ?? 'competitor'}</span>
                </div>
                <div className="rpt-act-t">{a.message}</div>
              </div>
            ))}
          </div>
          <div className="rpt-cap">Proxy signals from the public EU Ad Library — a directional read on competitor activity, not a performance claim.</div>
        </section>
      )}

      <footer className="rpt-foot">
        <div>
          <div className="rpt-foot-brand">{agencyName}</div>
          <div className="rpt-foot-powered">{footerText}</div>
        </div>
        <div className="rpt-foot-note">{brand.name} · Weekly performance · {week.start} – {week.end}</div>
      </footer>
    </div>
  );
}

function Kpi({ label, value, k, kind, note }: { label: string; value: string; k: WeeklyKpi; kind: 'money' | 'ratio' | 'int'; note?: string }) {
  return (
    <div className="rpt-kpi">
      <div className="rpt-kpi-l">{label}</div>
      <div className="rpt-kpi-v">{value}</div>
      <div className="rpt-kpi-d">
        {kind === 'ratio' ? <AbsDelta abs={k.deltaAbs} /> : <PctDelta pct={k.deltaPct} />}
        <span className="rpt-kpi-note"> WoW</span>
        {k.lastYear !== null && (
          <span className="rpt-kpi-note">
            {' '}· {kind === 'ratio'
              ? (k.yoyAbs === null ? '—' : `${k.yoyAbs > 0 ? '+' : ''}${k.yoyAbs.toFixed(2)}×`)
              : (k.yoyPct === null ? '—' : `${k.yoyPct > 0 ? '+' : ''}${k.yoyPct.toFixed(1)}%`)} YoY
          </span>
        )}
        {note ? <span className="rpt-kpi-note"> · {note}</span> : null}
      </div>
    </div>
  );
}

function PctDelta({ pct }: { pct: number | null }) {
  if (pct === null) return <span className="flat">—</span>;
  const dir = pct > 0.05 ? 'up' : pct < -0.05 ? 'down' : 'flat';
  return <span className={dir}>{pct > 0 ? '+' : ''}{pct.toFixed(1)}%</span>;
}

function AbsDelta({ abs }: { abs: number | null }) {
  if (abs === null) return <span className="flat">—</span>;
  const dir = abs > 0.005 ? 'up' : abs < -0.005 ? 'down' : 'flat';
  return <span className={dir}>{abs > 0 ? '+' : ''}{abs.toFixed(2)}×</span>;
}

// Plain-div 7-day bar chart (no chart library, like every other report
// document): revenue bars normalised to the week's best day, spend printed
// underneath. Incomplete days render a hatched pending bar and '—', never €0.
function DailyChart({ days, currency }: { days: { date: string; revenue: number | null; spend: number | null; complete: boolean }[]; currency: string }) {
  const max = days.reduce((m, d) => Math.max(m, d.revenue ?? 0), 0);
  const compact = (v: number | null) => (v === null ? '—' : formatMoney(v, currency, { compact: true }));
  const dayName = (iso: string) =>
    new Date(`${iso}T00:00:00Z`).toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' });

  return (
    <div className="wk-chart">
      {days.map((d) => {
        const h = d.revenue !== null && max > 0 ? Math.max(4, Math.round((d.revenue / max) * 120)) : 0;
        return (
          <div className="wk-day" key={d.date}>
            <div className="wk-day-v">{compact(d.revenue)}</div>
            <div className="wk-day-track">
              {d.revenue !== null ? (
                <div className="wk-day-bar" style={{ height: h }} />
              ) : (
                <div className="wk-day-bar pending" style={{ height: 120 }} title="Not fully synced yet" />
              )}
            </div>
            <div className="wk-day-l">{dayName(d.date)}</div>
            <div className="wk-day-s">{d.spend === null ? 'spend —' : `spend ${compact(d.spend)}`}</div>
          </div>
        );
      })}
    </div>
  );
}

const WEEKLY_CSS = `
.rpt .wk-chart{display:grid;grid-template-columns:repeat(7,1fr);gap:10px;background:var(--paper);border:1px solid var(--line);border-radius:13px;padding:22px 20px 16px}
.rpt .wk-day{text-align:center}
.rpt .wk-day-v{font-family:var(--mono);font-size:10.5px;font-weight:600;color:var(--ink-2);margin-bottom:6px}
.rpt .wk-day-track{height:120px;display:flex;align-items:flex-end;justify-content:center}
.rpt .wk-day-bar{width:70%;max-width:44px;background:var(--accent);border-radius:5px 5px 2px 2px;min-height:4px}
.rpt .wk-day-bar.pending{background:repeating-linear-gradient(45deg,#efece6,#efece6 5px,#e2ded6 5px,#e2ded6 10px);border:1px dashed var(--line-2)}
.rpt .wk-day-l{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-3);margin-top:8px}
.rpt .wk-day-s{font-family:var(--mono);font-size:9.5px;color:var(--ink-4);margin-top:3px}
.rpt .wk-act-plat{font-weight:400;text-transform:none;letter-spacing:0;color:var(--ink-4)}
@media print{.rpt .wk-chart{page-break-inside:avoid}}
`;
