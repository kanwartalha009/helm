import { formatMoney } from '@/lib/formatters';
import { EarlySignalTag, REPORT_CSS, VerdictWindowCaption } from './ReportDocument';
import type {
  AdAuditAction,
  AdAuditSection,
  AdsAuditMover,
  AdsAuditPlatformBlock,
  AdsAuditReportData,
  AdVerdict,
} from '@/types/reports';

const PLATFORM_LABEL: Record<string, string> = {
  meta: 'Meta',
  google: 'Google Ads',
  tiktok: 'TikTok',
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

/**
 * Ads audit report — platform-by-platform campaign audit: what's winning,
 * what's burning spend, and what to do about it. Same white-label design
 * language as WeeklyReportDocument (shared REPORT_CSS, plain divs, no chart
 * lib). One section per platform: KPI strip, the "where the money went" audit
 * block (waste callout + Scale/Fix/Pause action list), and the movers table.
 * Verdicts on thin data carry an "early signal" tag so a small window never
 * over-claims. No AI narrative in v1 — commentary is the only editable slot.
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
  const roas = (v: number | null) => (v === null ? '—' : `${v.toFixed(2)}×`);
  const k = block.kpis;

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
        <Kpi label="ROAS" value={roas(k.roas.value)} delta={hasComparison ? <AbsDelta abs={k.roas.deltaAbs} /> : undefined} />
        <Kpi label="Purchases" value={k.purchases.value === null ? '—' : k.purchases.value.toLocaleString()} delta={hasComparison ? <PctDelta pct={k.purchases.deltaPct} /> : undefined} />
        <Kpi label="CPA" value={money(k.cpa.value)} delta={hasComparison ? <PctDelta pct={k.cpa.deltaPct} /> : undefined} />
      </div>

      <AuditBlock audit={block.audit} currency={currency} />

      {block.movers.length > 0 && (
        <>
          <div className="aud-sub-head">Movers</div>
          <MoversTable movers={block.movers.slice(0, 8)} currency={currency} hasComparison={hasComparison} />
        </>
      )}
    </section>
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
  const roas = (v: number | null) => (v === null ? '—' : `${v.toFixed(2)}×`);
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
              <td className="r">{roas(m.roas)}</td>
              {hasComparison && <td className="r prior">{roas(m.prevRoas)}</td>}
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
.rpt .aud-waste-row{grid-template-columns:minmax(220px,340px);margin-bottom:14px}
.rpt .aud-waste-row .rpt-kpi-v{font-size:28px}
.rpt .aud-empty{background:var(--paper);border:1px solid var(--line);border-left:3px solid var(--ink-4);border-radius:12px;padding:18px 22px;font-size:12.5px;color:var(--ink-3);line-height:1.6}
.rpt .aud-money{margin-top:4px}
@media print{.rpt .rpt-tbl{page-break-inside:avoid}}
`;
