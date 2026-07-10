import { useEffect, useState } from 'react';
import { formatMoney } from '@/lib/formatters';
import { Tag } from '@/components/ui';
import { EarlySignalTag, REPORT_CSS, VerdictWindowCaption } from './ReportDocument';
import type {
  AdAuditAction,
  AdAuditSection,
  AdsAuditCampaignDetail,
  AdsAuditCreativeRow,
  AdsAuditCreatives,
  AdsAuditMover,
  AdsAuditPerformerRow,
  AdsAuditPlatformBlock,
  AdsAuditReportData,
  AdsAuditSegmentAxis,
  AdsAuditSegments,
  AdVerdict,
} from '@/types/reports';

const PLATFORM_LABEL: Record<string, string> = {
  meta: 'Meta',
  google: 'Google Ads',
  tiktok: 'TikTok',
};

const AXIS_LABEL: Record<AdsAuditSegmentAxis, string> = {
  audience: 'Audience',
  age_gender: 'Age & gender',
  country: 'Country',
  device: 'Device',
  placement: 'Placement',
};

const DEFAULT_COMMENTARY =
  'Summarise the audit for the client here — where the budget is working, where it is burning, and the moves you recommend. Editable before you send.';

// Actions grouped Scale → Fix → Pause (kind 'stop' reads as Pause to a client),
// each keeping the kind's existing report coloring.
const ACTION_GROUPS: { kind: 'scale' | 'fix' | 'stop'; label: string; tone: string }[] = [
  { kind: 'scale', label: '★ Scale', tone: 'win' },
  { kind: 'fix', label: '◆ Fix', tone: 'wound' },
  { kind: 'stop', label: '▲ Pause', tone: 'dead' },
];

const VERDICT: Record<string, { label: string; tone: string }> = {
  dead: { label: 'Dead', tone: 'dead' },
  scaling_loss: { label: 'Scaling loss', tone: 'wound' },
  weak: { label: 'Weak', tone: 'wound' },
  winner: { label: 'Winner', tone: 'win' },
  steady: { label: 'Steady', tone: 'hold' },
  minor: { label: 'Minor', tone: 'hold' },
};

function verdictTint(v: AdVerdict): string {
  if (v === 'dead') return 'row-dead';
  if (v === 'scaling_loss' || v === 'weak') return 'row-wound';
  if (v === 'winner') return 'row-win';
  return '';
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

// Shared cell formatters — every null renders '—', never a fabricated 0.
const fmtRoas = (v: number | null) => (v === null ? '—' : `${v.toFixed(2)}×`);
const fmtCtr = (v: number | null) => (v === null ? '—' : `${v.toFixed(2)}%`);
const fmtInt = (v: number | null) => (v === null ? '—' : v.toLocaleString());
const titleCase = (s: string) => (s ? s.charAt(0).toUpperCase() + s.slice(1).toLowerCase().replace(/_/g, ' ') : s);

/**
 * Ads audit report — platform-by-platform campaign audit: what's winning,
 * what's burning spend, and what to do about it. Same white-label design
 * language as WeeklyReportDocument (shared REPORT_CSS, plain divs, no chart
 * lib). One section per platform: KPI strip, best/worst performer tables,
 * the "where the money went" audit block (waste callout + Scale/Fix/Pause
 * action list), customer-segment spend bars, creative winners/fatigue cards
 * (Meta/TikTok), the all-campaigns table with a per-campaign issues drawer,
 * and the movers table. Verdicts on thin data carry an "early signal" tag so
 * a small window never over-claims. The drawer is implemented inside this
 * component (plain CSS, no app Drawer import) so it works on the public
 * share page too. No AI narrative in v1 — commentary is the only editable slot.
 */
export function AdsAuditReportDocument({
  data,
  editable = false,
  onCommentaryChange,
}: {
  data: AdsAuditReportData;
  editable?: boolean;
  onCommentaryChange?: (value: string) => void;
}) {
  const { brand, currency, period, comparison, platformFilter, platforms, hasData } = data;
  const content = data.content ?? undefined;

  const accent = data.branding?.accent || '#1f6f5c';
  const agencyName = data.branding?.agency_name || 'Roasdriven';
  const footerText = data.branding?.footer_text || 'Powered by novasolution.ae';
  const initialCommentary = content?.commentary ?? DEFAULT_COMMENTARY;

  return (
    <div className="rpt" style={{ ['--rpt-accent' as never]: accent }}>
      <style>{REPORT_CSS}</style>
      <style>{AUDIT_CSS}</style>

      <header className="rpt-head">
        <div>
          <div className="rpt-eyebrow">
            Ads audit · {period.label}
            {platformFilter && (
              <span className="aud-filter-badge">{PLATFORM_LABEL[platformFilter] ?? platformFilter} only</span>
            )}
          </div>
          <h1 className="rpt-brand">{brand.name}</h1>
          <div className="rpt-brand-sub">
            Platform-by-platform campaign audit · {period.label.toLowerCase()}
            {comparison ? ` ${comparison.label ?? 'vs comparison'}` : ''} · prepared by {agencyName}
          </div>
        </div>
        <div className="rpt-meta">
          <div><strong>Period</strong> {period.start} – {period.end}</div>
          {comparison && <div><strong>{comparison.label ?? 'Comparison'}</strong> {comparison.start} – {comparison.end}</div>}
          {platformFilter && <div><strong>Scope</strong> {PLATFORM_LABEL[platformFilter] ?? platformFilter} only</div>}
          <div><strong>Currency</strong> {currency}</div>
        </div>
      </header>
      <div className="rpt-rule" />

      {!hasData && (
        <section className="rpt-sec">
          <div className="aud-empty">
            No campaign data in this window — run the ads backfill or widen the window.
          </div>
        </section>
      )}

      {hasData &&
        platforms.map((p, i) => (
          <PlatformSection
            key={p.platform}
            num={String(i + 1).padStart(2, '0')}
            block={p}
            currency={currency}
            hasComparison={!!comparison}
          />
        ))}

      {(editable || (content?.commentary ?? '').trim()) && (
        <section className="rpt-sec">
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
              <div className="rpt-note">{content?.commentary}</div>
            )}
          </div>
        </section>
      )}

      <footer className="rpt-foot">
        <div>
          <div className="rpt-foot-brand">{agencyName}</div>
          <div className="rpt-foot-powered">{footerText}</div>
        </div>
        <div className="rpt-foot-note">{brand.name} · Ads audit · {period.start} – {period.end}</div>
      </footer>
    </div>
  );
}

function PlatformSection({
  num,
  block,
  currency,
  hasComparison,
}: {
  num: string;
  block: AdsAuditPlatformBlock;
  currency: string;
  hasComparison: boolean;
}) {
  const label = PLATFORM_LABEL[block.platform] ?? block.platform;
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  const kpiMoney = (v: number | null) => (v === null ? '—' : formatMoney(v, currency, { whole: true }));
  const k = block.kpis;

  const best = block.best ?? [];
  const worst = block.worst ?? [];
  const creatives = block.creatives ?? null;
  const campaignDetails = block.campaignDetails ?? [];

  return (
    <section className="rpt-sec">
      <div className="rpt-sec-head"><span className="rpt-sec-num">{num}</span><h2>{label} — the honest read</h2></div>
      <div className="rpt-sec-sub">
        Spend, return and the campaign verdicts for {label} this period. Verdicts are rules-based — never a judgement
        call.
      </div>

      <div className="rpt-kpis">
        <Kpi label="Spend" value={kpiMoney(k.spend.value)} delta={hasComparison ? <PctDelta pct={k.spend.deltaPct} /> : undefined} />
        <Kpi label="Revenue (attributed)" value={kpiMoney(k.conversionValue.value)} delta={hasComparison ? <PctDelta pct={k.conversionValue.deltaPct} /> : undefined} />
        <Kpi label="ROAS" value={fmtRoas(k.roas.value)} delta={hasComparison ? <AbsDelta abs={k.roas.deltaAbs} /> : undefined} />
        <Kpi label="Purchases" value={fmtInt(k.purchases.value)} delta={hasComparison ? <PctDelta pct={k.purchases.deltaPct} /> : undefined} />
        <Kpi label="CPA" value={money(k.cpa.value)} delta={hasComparison ? <PctDelta pct={k.cpa.deltaPct} /> : undefined} />
      </div>

      {best.length > 0 && (
        <div className="aud-perf aud-perf-best">
          <div className="aud-sub-head aud-sub-head-tight">Best performers</div>
          <PerformerTable rows={best} currency={currency} />
        </div>
      )}

      {worst.length > 0 && (
        <div className="aud-perf aud-perf-worst">
          <div className="aud-sub-head aud-sub-head-tight aud-sub-head-danger">Worst performers</div>
          <div className="aud-lead-in">These campaigns returned the least for their spend in this window.</div>
          <PerformerTable rows={worst} currency={currency} worst />
        </div>
      )}

      <AuditBlock audit={block.audit} currency={currency} />

      {block.segments && <SegmentsBlock segments={block.segments} currency={currency} />}

      {creatives && creatives.status === 'ok' && (creatives.winners.length > 0 || creatives.fatigued.length > 0) && (
        <CreativesBlock creatives={creatives} currency={currency} />
      )}

      {campaignDetails.length > 0 && (
        <CampaignsBlock details={campaignDetails} currency={currency} platformLabel={label} />
      )}

      {block.movers.length > 0 && (
        <>
          <div className="aud-sub-head">Movers</div>
          <MoversTable movers={block.movers.slice(0, 8)} currency={currency} hasComparison={hasComparison} />
        </>
      )}
    </section>
  );
}

// Best / worst performers — same columns both ways; worst leads with null-ROAS
// spenders (money out, zero attributed revenue back), flagged explicitly.
function PerformerTable({ rows, currency, worst = false }: { rows: AdsAuditPerformerRow[]; currency: string; worst?: boolean }) {
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  return (
    <div className="rpt-tbl-wrap">
      <table className="rpt-tbl rpt-tbl-dim aud-perf-tbl">
        <thead>
          <tr>
            <th>Campaign</th>
            <th className="r">Spend</th>
            <th className="r">Revenue</th>
            <th className="r">ROAS</th>
            <th className="r">CPA</th>
            <th className="r">CTR</th>
            <th className="r">CPM</th>
            <th className="r">Purchases</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.campaignId}>
              <td className="name">
                <div className="rpt-dim-label">
                  {r.name}
                  {r.confidence === 'early' && <EarlySignalTag />}
                </div>
              </td>
              <td className="r">{money(r.spend)}</td>
              <td className="r">{money(r.conversionValue)}</td>
              <td className="r">
                {fmtRoas(r.roas)}
                {worst && r.roas === null && r.spend > 0 && (
                  <span className="rpt-badge b-dead aud-inline-badge">no attributed revenue</span>
                )}
              </td>
              <td className="r">{money(r.cpa)}</td>
              <td className="r">{fmtCtr(r.ctr)}</td>
              <td className="r">{money(r.cpm)}</td>
              <td className="r">{fmtInt(r.purchases)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// "Where the money went" — the waste callout plus the Scale / Fix / Pause
// action list, mirroring how the overall report renders its adsAudit entries.
function AuditBlock({ audit, currency }: { audit: AdAuditSection; currency: string }) {
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  const grouped = ACTION_GROUPS.map((g) => ({ ...g, actions: audit.actions.filter((a) => a.kind === g.kind) })).filter(
    (g) => g.actions.length > 0,
  );

  return (
    <div className="aud-money">
      <div className="aud-sub-head">Where the money went</div>
      <div className="rpt-kpis aud-waste-row">
        <div className="rpt-kpi rpt-kpi-warn">
          <div className="rpt-kpi-l">Wasted spend</div>
          <div className="rpt-kpi-v">{money(audit.waste.amount)}</div>
          <div className="rpt-kpi-d">
            {audit.waste.count} campaign{audit.waste.count === 1 ? '' : 's'}
            {audit.waste.sharePct === null ? '' : ` · ${audit.waste.sharePct.toFixed(0)}% of platform spend`}
          </div>
        </div>
      </div>
      {grouped.length > 0 && (
        <div className="rpt-actions rpt-actions-stack">
          {grouped.map((g) =>
            g.actions.map((a: AdAuditAction, i: number) => (
              <div className={`rpt-act ${g.tone}`} key={`${g.kind}-${i}`}>
                <div className="rpt-act-k">{g.label}</div>
                <div className="rpt-act-t">
                  <b>{a.title}</b> — {a.body}
                  {a.confidence === 'early' && <EarlySignalTag />}
                </div>
              </div>
            )),
          )}
        </div>
      )}
      <VerdictWindowCaption windowDays={audit.windowDays} />
    </div>
  );
}

// Customer segments — one axis visible at a time (chip toggles), rows as
// horizontal spend-share bars. Axis selection is local per platform section.
function SegmentsBlock({ segments, currency }: { segments: AdsAuditSegments; currency: string }) {
  const axes = segments.axes;
  const [axis, setAxis] = useState<AdsAuditSegmentAxis | null>(axes.length > 0 ? axes[0].axis : null);
  const active = axes.find((a) => a.axis === axis) ?? axes[0];
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));

  return (
    <div className="aud-segments">
      <div className="aud-sub-head">Customer segments</div>
      {axes.length === 0 ? (
        <div className="aud-muted-note">No audience breakdown synced for this window.</div>
      ) : (
        <>
          {axes.length > 1 && (
            <div className="aud-chips" role="tablist" aria-label="Segment axis">
              {axes.map((a) => (
                <button
                  key={a.axis}
                  type="button"
                  role="tab"
                  aria-selected={active?.axis === a.axis}
                  className={`aud-chip${active?.axis === a.axis ? ' on' : ''}`}
                  onClick={() => setAxis(a.axis)}
                >
                  {AXIS_LABEL[a.axis] ?? a.axis}
                </button>
              ))}
            </div>
          )}
          {axes.length === 1 && <div className="aud-seg-axis-label">{AXIS_LABEL[axes[0].axis] ?? axes[0].axis}</div>}
          {active && (
            <div className="rpt-bars">
              {active.rows.map((r) => (
                <div className="aud-seg-row" key={r.key}>
                  <div className="aud-seg-l" title={r.label}>{r.label}</div>
                  <div className="aud-seg-track">
                    <div
                      className="aud-seg-fill"
                      style={{ width: `${Math.min(100, Math.max(r.sharePct === null ? 0 : 1, Math.round(r.sharePct ?? 0)))}%` }}
                    />
                  </div>
                  <div className="aud-seg-n">
                    {money(r.spend)} · CTR {fmtCtr(r.ctr)} · CPM {money(r.cpm)} · ROAS {fmtRoas(r.roas)}
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}

// Creative thumbnail — img with a grey "no preview" fallback (also used when
// the URL 404s / is permission-gated, mirroring the ads-hub creatives view).
function CreativeThumb({ url }: { url: string | null }) {
  const [errored, setErrored] = useState(false);
  if (!url || errored) return <div className="aud-crt-thumb aud-crt-noimg">no preview</div>;
  return (
    <img
      className="aud-crt-thumb"
      src={url}
      alt=""
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setErrored(true)}
    />
  );
}

function CreativeCard({ row, currency }: { row: AdsAuditCreativeRow; currency: string }) {
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  const isVideo = row.mediaType === 'video';
  return (
    <div className="aud-crt-card">
      <CreativeThumb url={row.thumbnailUrl} />
      <div className="aud-crt-body">
        <div className="aud-crt-name" title={row.name}>{row.name}</div>
        <div className="aud-crt-stats">
          {money(row.spend)} · ROAS {fmtRoas(row.roas)} · CTR {fmtCtr(row.ctr)}
        </div>
        {isVideo && (
          <div className="aud-crt-stats">
            Thumbstop {row.thumbstopPct === null ? '—' : `${row.thumbstopPct.toFixed(1)}%`} · Hold{' '}
            {row.holdPct === null ? '—' : `${row.holdPct.toFixed(1)}%`}
          </div>
        )}
        {row.belowAverage && (
          <Tag variant="warning" style={{ marginTop: 6, fontSize: 10 }}>ranked below average</Tag>
        )}
      </div>
    </div>
  );
}

// Creatives — winners to scale, fatigued to refresh. Meta/TikTok only; the
// whole block is omitted when the platform has no creative sync ('no_data').
function CreativesBlock({ creatives, currency }: { creatives: AdsAuditCreatives; currency: string }) {
  return (
    <div className="aud-creatives">
      <div className="aud-sub-head">Creatives</div>
      {creatives.winners.length > 0 && (
        <>
          <div className="aud-crt-group win">Scale these</div>
          <div className="aud-crt-grid">
            {creatives.winners.map((c) => <CreativeCard key={c.adId} row={c} currency={currency} />)}
          </div>
        </>
      )}
      {creatives.fatigued.length > 0 && (
        <>
          <div className="aud-crt-group tired">Refresh these</div>
          <div className="aud-crt-grid">
            {creatives.fatigued.map((c) => <CreativeCard key={c.adId} row={c} currency={currency} />)}
          </div>
        </>
      )}
      <div className="rpt-cap">
        Thumbstop = 3-second video views ÷ impressions; hold = ThruPlays ÷ 3-second views — video creatives only.
        "Ranked below average" reflects the platform's own relevance diagnostics.
      </div>
    </div>
  );
}

function StatusChip({ status }: { status: string }) {
  if (!status) return <span className="flat">—</span>;
  const tone = status.toLowerCase() === 'active' || status.toLowerCase() === 'enabled' ? 'win' : 'hold';
  return <span className={`rpt-badge b-${tone}`}>{titleCase(status)}</span>;
}

// All campaigns + the per-campaign issues drawer. The drawer lives inside this
// component (fixed panel + overlay, plain CSS in AUDIT_CSS — no app Drawer
// import) so it works on the public share page. Esc / overlay / × close it.
function CampaignsBlock({
  details,
  currency,
  platformLabel,
}: {
  details: AdsAuditCampaignDetail[];
  currency: string;
  platformLabel: string;
}) {
  const [openId, setOpenId] = useState<string | null>(null);
  const open = openId === null ? null : details.find((d) => d.campaignId === openId) ?? null;
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpenId(null);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  return (
    <div className="aud-campaigns">
      <div className="aud-sub-head">All campaigns</div>
      <div className="rpt-sec-sub" style={{ marginBottom: 14 }}>
        Every audited {platformLabel} campaign this window — open one to see its issues and daily trend.
      </div>
      <div className="rpt-tbl-wrap">
        <table className="rpt-tbl rpt-tbl-dim aud-camp-tbl">
          <thead>
            <tr>
              <th>Campaign</th>
              <th>Status</th>
              <th className="r">Spend</th>
              <th className="r">ROAS</th>
              <th>Verdict</th>
              <th className="r">Issues</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {details.map((d) => {
              const critical = d.issues.some((i) => i.severity === 'critical');
              return (
                <tr
                  key={d.campaignId}
                  className={`aud-camp-row ${verdictTint(d.verdict)}`}
                  role="button"
                  tabIndex={0}
                  aria-label={`View ${d.name} issues`}
                  onClick={() => setOpenId(d.campaignId)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      setOpenId(d.campaignId);
                    }
                  }}
                >
                  <td className="name"><div className="rpt-dim-label">{d.name}</div></td>
                  <td><StatusChip status={d.status} /></td>
                  <td className="r">{money(d.kpis.spend)}</td>
                  <td className="r">{fmtRoas(d.kpis.roas)}</td>
                  <td>
                    {VERDICT[d.verdict] ? <span className={`rpt-badge b-${VERDICT[d.verdict].tone}`}>{VERDICT[d.verdict].label}</span> : '—'}
                    {d.confidence === 'early' && <EarlySignalTag />}
                  </td>
                  <td className="r">
                    <span className={`rpt-badge ${critical ? 'b-dead' : 'b-hold'}`}>{d.issues.length}</span>
                  </td>
                  <td className="r aud-camp-view">View →</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {open && (
        <>
          <div className="aud-drawer-overlay" onClick={() => setOpenId(null)} />
          <CampaignDrawer detail={open} currency={currency} platformLabel={platformLabel} onClose={() => setOpenId(null)} />
        </>
      )}
    </div>
  );
}

function CampaignDrawer({
  detail,
  currency,
  platformLabel,
  onClose,
}: {
  detail: AdsAuditCampaignDetail;
  currency: string;
  platformLabel: string;
  onClose: () => void;
}) {
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  const k = detail.kpis;
  const spendDeltaPct =
    k.spend !== null && k.prevSpend !== null && k.prevSpend !== 0 ? ((k.spend - k.prevSpend) / k.prevSpend) * 100 : null;
  const roasDeltaAbs = k.roas !== null && k.prevRoas !== null ? k.roas - k.prevRoas : null;

  return (
    <aside className="aud-drawer" role="dialog" aria-modal="true" aria-label={`${detail.name} — campaign detail`}>
      <button type="button" className="aud-drawer-x" onClick={onClose} aria-label="Close campaign detail">×</button>
      <div className="aud-drawer-eyebrow">{platformLabel} campaign</div>
      <h3 className="aud-drawer-title">{detail.name}</h3>
      <div className="aud-drawer-chips">
        <StatusChip status={detail.status} />
        {detail.channelType && <span className="rpt-badge b-hold">{titleCase(detail.channelType)}</span>}
        {VERDICT[detail.verdict] && (
          <span className={`rpt-badge b-${VERDICT[detail.verdict].tone}`}>{VERDICT[detail.verdict].label}</span>
        )}
        {detail.confidence === 'early' && <EarlySignalTag />}
      </div>

      <div className="aud-drawer-kpis">
        <DrawerKpi label="Spend" value={money(k.spend)} sub={<><PctDelta pct={spendDeltaPct} /><span className="aud-drawer-kpi-note"> vs {money(k.prevSpend)} prev</span></>} />
        <DrawerKpi label="ROAS" value={fmtRoas(k.roas)} sub={<><AbsDelta abs={roasDeltaAbs} /><span className="aud-drawer-kpi-note"> vs {fmtRoas(k.prevRoas)} prev</span></>} />
        <DrawerKpi label="CPA" value={money(k.cpa)} />
        <DrawerKpi label="CTR" value={fmtCtr(k.ctr)} />
        <DrawerKpi label="CPM" value={money(k.cpm)} />
        <DrawerKpi label="Purchases" value={fmtInt(k.purchases)} />
      </div>

      {detail.series.length > 0 && <DrawerSeriesChart series={detail.series} currency={currency} />}

      <div className="aud-drawer-issues-head">
        Issues{detail.issues.length > 0 ? ` (${detail.issues.length})` : ''}
      </div>
      {detail.issues.length === 0 ? (
        <div className="aud-muted-note">No issues flagged on this campaign in this window.</div>
      ) : (
        <div className="aud-issues">
          {detail.issues.map((iss, i) => (
            <div className="aud-issue" key={i}>
              <span className={`aud-issue-dot ${iss.severity}`} aria-hidden />
              <div>
                <div className="aud-issue-title">{iss.title}</div>
                <div className="aud-issue-detail">{iss.detail}</div>
              </div>
            </div>
          ))}
        </div>
      )}
    </aside>
  );
}

function DrawerKpi({ label, value, sub }: { label: string; value: string; sub?: React.ReactNode }) {
  return (
    <div className="aud-drawer-kpi">
      <div className="aud-drawer-kpi-l">{label}</div>
      <div className="aud-drawer-kpi-v">{value}</div>
      {sub && <div className="aud-drawer-kpi-d">{sub}</div>}
    </div>
  );
}

// Daily spend bars (normalised to the max spend day) with a thin ROAS dot per
// bar — plain divs, mirroring the WeeklyReportDocument bar technique. Null
// days render no bar / no dot, never a zero bar.
function DrawerSeriesChart({ series, currency }: { series: { date: string; spend: number | null; roas: number | null }[]; currency: string }) {
  const maxSpend = series.reduce((m, d) => Math.max(m, d.spend ?? 0), 0);
  const maxRoas = series.reduce((m, d) => Math.max(m, d.roas ?? 0), 0);
  const compact = (v: number | null) => (v === null ? '—' : formatMoney(v, currency, { compact: true }));
  const H = 84;

  return (
    <div className="aud-drawer-chart-wrap">
      <div className="aud-drawer-chart" style={{ gridTemplateColumns: `repeat(${series.length},1fr)` }}>
        {series.map((d) => {
          const barH = d.spend !== null && maxSpend > 0 ? Math.max(3, Math.round((d.spend / maxSpend) * H)) : 0;
          const dotB = d.roas !== null && maxRoas > 0 ? Math.round((d.roas / maxRoas) * (H - 6)) : null;
          return (
            <div
              className="aud-drawer-day"
              key={d.date}
              title={`${d.date} · spend ${compact(d.spend)} · ROAS ${fmtRoas(d.roas)}`}
            >
              <div className="aud-drawer-track" style={{ height: H }}>
                {d.spend !== null && <div className="aud-drawer-bar" style={{ height: barH }} />}
                {dotB !== null && <span className="aud-drawer-dot" style={{ bottom: dotB }} />}
              </div>
            </div>
          );
        })}
      </div>
      <div className="aud-drawer-chart-cap">
        {series[0].date} – {series[series.length - 1].date} · bars = daily spend · dot = daily ROAS
      </div>
    </div>
  );
}

function MoversTable({
  movers,
  currency,
  hasComparison,
}: {
  movers: AdsAuditMover[];
  currency: string;
  hasComparison: boolean;
}) {
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  return (
    <div className="rpt-tbl-wrap">
      <table className="rpt-tbl rpt-tbl-dim">
        <thead>
          <tr>
            <th>Campaign</th>
            <th className="r">Spend</th>
            {hasComparison && <th className="r">vs prev</th>}
            {hasComparison && <th className="r">Δ spend</th>}
            <th className="r">ROAS</th>
            {hasComparison && <th className="r">vs prev</th>}
            <th>Verdict</th>
          </tr>
        </thead>
        <tbody>
          {movers.map((m) => (
            <tr key={m.campaignId} className={verdictTint(m.verdict)}>
              <td className="name"><div className="rpt-dim-label">{m.name}</div></td>
              <td className="r">{money(m.spend)}</td>
              {hasComparison && <td className="r prior">{money(m.prevSpend)}</td>}
              {hasComparison && <td className="r"><PctDelta pct={m.spendDeltaPct} /></td>}
              <td className="r">{fmtRoas(m.roas)}</td>
              {hasComparison && <td className="r prior">{fmtRoas(m.prevRoas)}</td>}
              <td>
                {VERDICT[m.verdict] ? <span className={`rpt-badge b-${VERDICT[m.verdict].tone}`}>{VERDICT[m.verdict].label}</span> : '—'}
                {m.confidence === 'early' && <EarlySignalTag />}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Kpi({ label, value, delta }: { label: string; value: string; delta?: React.ReactNode }) {
  return (
    <div className="rpt-kpi">
      <div className="rpt-kpi-l">{label}</div>
      <div className="rpt-kpi-v">{value}</div>
      <div className="rpt-kpi-d">{delta ?? <span className="rpt-kpi-note">—</span>}</div>
    </div>
  );
}

const AUDIT_CSS = `
.rpt .aud-filter-badge{font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--accent);background:var(--accent-soft);border:1px solid var(--accent);border-radius:20px;padding:2px 9px}
.rpt .aud-sub-head{font-family:var(--mono);font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);font-weight:600;margin:26px 0 12px}
.rpt .aud-sub-head-tight{margin:0 0 10px}
.rpt .aud-sub-head-danger{color:var(--red)}
.rpt .aud-waste-row{grid-template-columns:minmax(220px,340px);margin-bottom:14px}
.rpt .aud-waste-row .rpt-kpi-v{font-size:28px}
.rpt .aud-empty{background:var(--paper);border:1px solid var(--line);border-left:3px solid var(--ink-4);border-radius:12px;padding:18px 22px;font-size:12.5px;color:var(--ink-3);line-height:1.6}
.rpt .aud-muted-note{font-size:12px;color:var(--ink-3);font-style:italic;padding:6px 2px}
.rpt .aud-money{margin-top:4px}
.rpt .aud-lead-in{font-size:12px;color:var(--ink-3);margin:0 0 10px}

.rpt .aud-perf{background:var(--paper);border:1px solid var(--line);border-radius:13px;padding:18px 22px 8px;margin:26px 0 0}
.rpt .aud-perf-best{border-left:3px solid var(--green)}
.rpt .aud-perf-worst{border-left:3px solid var(--red)}
.rpt .aud-perf-tbl td.r{font-size:11px}
.rpt .aud-inline-badge{margin-left:8px;vertical-align:middle;text-transform:none;letter-spacing:.02em}

.rpt .aud-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
.rpt .aud-chip{font-family:var(--mono);font-size:10px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:var(--ink-3);background:var(--paper);border:1px solid var(--line-2);border-radius:20px;padding:5px 13px;cursor:pointer}
.rpt .aud-chip:hover{border-color:var(--accent);color:var(--accent)}
.rpt .aud-chip.on{background:var(--accent);border-color:var(--accent);color:#fff}
.rpt .aud-seg-axis-label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);margin-bottom:10px}
.rpt .aud-seg-row{display:grid;grid-template-columns:minmax(120px,190px) 1fr minmax(230px,auto);gap:12px;align-items:center;padding:7px 0;font-size:12px}
.rpt .aud-seg-row+.aud-seg-row{border-top:1px solid var(--line)}
.rpt .aud-seg-l{color:var(--ink-2);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rpt .aud-seg-track{height:6px;border-radius:4px;background:#efece6;overflow:hidden}
.rpt .aud-seg-fill{height:100%;border-radius:4px;background:var(--accent);min-width:2px}
.rpt .aud-seg-n{font-family:var(--mono);font-size:10.5px;color:var(--ink-2);text-align:right;white-space:nowrap}

.rpt .aud-crt-group{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin:14px 0 10px}
.rpt .aud-crt-group.win{color:var(--green)}
.rpt .aud-crt-group.tired{color:var(--amber)}
.rpt .aud-crt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.rpt .aud-crt-card{display:flex;gap:12px;background:var(--paper);border:1px solid var(--line);border-radius:12px;padding:12px}
.rpt .aud-crt-thumb{width:64px;height:64px;flex-shrink:0;border-radius:9px;object-fit:cover;background:#efece6}
.rpt .aud-crt-noimg{display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-4);text-align:center}
.rpt .aud-crt-body{min-width:0}
.rpt .aud-crt-name{font-size:12.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:5px}
.rpt .aud-crt-stats{font-family:var(--mono);font-size:10px;color:var(--ink-3);line-height:1.7}

.rpt .aud-camp-tbl tbody tr{cursor:pointer}
.rpt .aud-camp-tbl tbody tr:hover td{background:rgba(0,0,0,.025)}
.rpt .aud-camp-row:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.rpt .aud-camp-view{font-size:11px;font-weight:600;color:var(--accent);white-space:nowrap}

.rpt .aud-drawer-overlay{position:fixed;inset:0;background:rgba(22,21,20,.45);z-index:90}
.rpt .aud-drawer{position:fixed;top:0;right:0;bottom:0;width:min(460px,94vw);background:var(--paper);z-index:91;box-shadow:-20px 0 48px rgba(0,0,0,.22);border-left:1px solid var(--line);overflow-y:auto;padding:26px 26px 44px;font-family:var(--sans);color:var(--ink)}
.rpt .aud-drawer-x{position:absolute;top:14px;right:16px;width:30px;height:30px;border:1px solid var(--line-2);border-radius:8px;background:var(--paper);color:var(--ink-3);font-size:17px;line-height:1;cursor:pointer}
.rpt .aud-drawer-x:hover{color:var(--ink);border-color:var(--ink-3)}
.rpt .aud-drawer-eyebrow{font-family:var(--mono);font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);font-weight:600;margin-bottom:8px}
.rpt .aud-drawer-title{font-family:var(--display);font-size:22px;font-weight:600;letter-spacing:-.01em;line-height:1.2;margin:0 24px 12px 0}
.rpt .aud-drawer-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:20px}
.rpt .aud-drawer-kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:22px}
.rpt .aud-drawer-kpi{background:var(--bg);border:1px solid var(--line);border-radius:11px;padding:12px 14px}
.rpt .aud-drawer-kpi-l{font-size:9px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-3);margin-bottom:7px}
.rpt .aud-drawer-kpi-v{font-family:var(--display);font-size:21px;font-weight:600;line-height:1}
.rpt .aud-drawer-kpi-d{font-size:10.5px;margin-top:7px;font-weight:600}
.rpt .aud-drawer-kpi-note{color:var(--ink-4);font-weight:400}
.rpt .aud-drawer-chart-wrap{margin-bottom:22px}
.rpt .aud-drawer-chart{display:grid;gap:3px;align-items:end;background:var(--bg);border:1px solid var(--line);border-radius:11px;padding:14px 14px 10px}
.rpt .aud-drawer-track{position:relative;display:flex;align-items:flex-end;justify-content:center}
.rpt .aud-drawer-bar{width:70%;max-width:22px;background:var(--accent);opacity:.85;border-radius:3px 3px 1px 1px}
.rpt .aud-drawer-dot{position:absolute;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:var(--ink);box-shadow:0 0 0 1.5px var(--paper)}
.rpt .aud-drawer-chart-cap{font-family:var(--mono);font-size:9.5px;color:var(--ink-4);margin-top:7px}
.rpt .aud-drawer-issues-head{font-family:var(--mono);font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);font-weight:600;margin:0 0 12px}
.rpt .aud-issues{display:flex;flex-direction:column;gap:14px}
.rpt .aud-issue{display:flex;gap:10px;align-items:flex-start}
.rpt .aud-issue-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px}
.rpt .aud-issue-dot.critical{background:var(--red)}
.rpt .aud-issue-dot.warn{background:var(--amber)}
.rpt .aud-issue-dot.info{background:var(--ink-4)}
.rpt .aud-issue-title{font-size:12.5px;font-weight:600;color:var(--ink)}
.rpt .aud-issue-detail{font-size:12.5px;line-height:1.5;color:var(--ink-2);margin-top:2px}

@media print{.rpt .rpt-tbl{page-break-inside:avoid}.rpt .aud-drawer,.rpt .aud-drawer-overlay{display:none}.rpt .aud-perf{page-break-inside:avoid}.rpt .aud-crt-grid{page-break-inside:avoid}}
`;
