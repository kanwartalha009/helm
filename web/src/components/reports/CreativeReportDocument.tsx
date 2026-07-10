import { formatMoney, formatRoas } from '@/lib/formatters';
import { NarrativeBlocks } from './NarrativeBlocks';
import { REPORT_CSS } from './ReportDocument';
import type { CreativePlatformBlock, CreativeReportData, NarrativeBlocksShape } from '@/types/reports';

const PLATFORM_LABEL: Record<string, string> = {
  meta: 'Meta',
  tiktok: 'TikTok',
};

const MEDIA_LABEL: Record<string, string> = {
  image: 'Image',
  video: 'Video',
  unknown: 'Other',
};

const DEFAULT_COMMENTARY =
  'Summarise the creative picture for the client here — which ads carried the period, what is wearing out, and what to brief next. Editable before you send.';

/**
 * Creative performance report (spec §2 weekly-ad / Meta-audit creative grain) —
 * which ads earned their budget. Same white-label design language as the other
 * report documents (shared REPORT_CSS). One block per ad platform with creative
 * rows in the window: summary KPIs, the top creatives table with relevance
 * ranking badges, rules-derived fatigue and scale-candidate cards, and the
 * media mix as plain-div bars. Narrative blocks + commentary are editable
 * agency content, exactly like the other reports.
 */
export function CreativeReportDocument({
  data,
  editable = false,
  onCommentaryChange,
  generatingNarrative = false,
  onGenerateNarrative,
  onNarrativeBlockChange,
}: {
  data: CreativeReportData;
  editable?: boolean;
  onCommentaryChange?: (value: string) => void;
  generatingNarrative?: boolean;
  onGenerateNarrative?: () => void;
  onNarrativeBlockChange?: (key: keyof NarrativeBlocksShape, value: string) => void;
}) {
  const { brand, currency, period, comparison, platforms } = data;
  const content = data.content ?? undefined;

  const accent = data.branding?.accent || '#1f6f5c';
  const agencyName = data.branding?.agency_name || 'Roasdriven';
  const footerText = data.branding?.footer_text || 'Powered by novasolution.ae';
  const initialCommentary = content?.commentary ?? DEFAULT_COMMENTARY;

  const totalSpend = platforms.reduce((s, p) => s + p.summary.spend, 0);
  const kpiMoney = (v: number | null) => (v === null ? '—' : formatMoney(v, currency, { whole: true }));

  return (
    <div className="rpt" style={{ ['--rpt-accent' as never]: accent }}>
      <style>{REPORT_CSS}</style>
      <style>{CREATIVE_CSS}</style>

      <header className="rpt-head">
        <div>
          <div className="rpt-eyebrow">Creative performance · {period.label}</div>
          <h1 className="rpt-brand">{brand.name}</h1>
          <div className="rpt-brand-sub">
            Which ads earned their budget · {period.label.toLowerCase()}
            {comparison ? ` ${comparison.label}` : ''} · prepared by {agencyName}
          </div>
        </div>
        <div className="rpt-meta">
          <div><strong>Period</strong> {period.start} – {period.end}</div>
          {comparison && <div><strong>{comparison.label}</strong> {comparison.start} – {comparison.end}</div>}
          <div><strong>Creative spend</strong> {platforms.length > 0 ? kpiMoney(totalSpend) : '—'}</div>
          <div><strong>Currency</strong> {currency}</div>
        </div>
      </header>
      <div className="rpt-rule" />

      <section className="rpt-sec">
        <div className="rpt-sec-head"><span className="rpt-sec-num">00</span><h2>Executive summary</h2></div>
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

      {platforms.length === 0 && (
        <section className="rpt-sec">
          <div className="crt-empty">
            No creative-level ad data is on file for this period yet. Once the creative sync has run for a connected
            ad platform, its creatives appear here — never as €0 rows.
          </div>
        </section>
      )}

      {platforms.map((p, i) => (
        <PlatformSection key={p.platform} num={String(i + 1).padStart(2, '0')} block={p} currency={currency} hasComparison={!!comparison} />
      ))}

      <footer className="rpt-foot">
        <div>
          <div className="rpt-foot-brand">{agencyName}</div>
          <div className="rpt-foot-powered">{footerText}</div>
        </div>
        <div className="rpt-foot-note">{brand.name} · Creative performance · {period.start} – {period.end}</div>
      </footer>
    </div>
  );
}

function PlatformSection({ num, block, currency, hasComparison }: { num: string; block: CreativePlatformBlock; currency: string; hasComparison: boolean }) {
  const label = PLATFORM_LABEL[block.platform] ?? block.platform;
  const money = (v: number | null) => (v === null ? '—' : formatMoney(v, currency));
  const kpiMoney = (v: number | null) => (v === null ? '—' : formatMoney(v, currency, { whole: true }));
  const roas = (v: number | null) => (v === null ? '—' : `${v.toFixed(2)}×`);
  const pct = (v: number | null) => (v === null ? '—' : `${(v * 100).toFixed(1)}%`);
  const maxMix = block.mediaMix.reduce((m, r) => Math.max(m, r.share ?? 0), 0) || 1;

  return (
    <section className="rpt-sec">
      <div className="rpt-sec-head"><span className="rpt-sec-num">{num}</span><h2>{label} creatives</h2></div>
      <div className="rpt-sec-sub">
        Ranked by spend — where the creative budget actually went. Fatigue and scale flags are rules-based on
        period-over-period ROAS and CTR, never a judgement call.
      </div>

      <div className="rpt-kpis crt-kpis-4">
        <div className="rpt-kpi"><div className="rpt-kpi-l">Creatives live</div><div className="rpt-kpi-v">{block.summary.creatives.toLocaleString()}</div><div className="rpt-kpi-d rpt-kpi-note">with spend or delivery</div></div>
        <div className="rpt-kpi"><div className="rpt-kpi-l">Creative spend</div><div className="rpt-kpi-v">{kpiMoney(block.summary.spend)}</div><div className="rpt-kpi-d rpt-kpi-note">{label} attributed</div></div>
        <div className="rpt-kpi"><div className="rpt-kpi-l">Attributed revenue</div><div className="rpt-kpi-v">{kpiMoney(block.summary.revenue)}</div><div className="rpt-kpi-d rpt-kpi-note">platform attribution</div></div>
        <div className="rpt-kpi"><div className="rpt-kpi-l">Blended ROAS</div><div className="rpt-kpi-v">{formatRoas(block.summary.roas)}</div><div className="rpt-kpi-d rpt-kpi-note">across all creatives</div></div>
      </div>

      <div className="rpt-tbl-wrap">
        <table className="rpt-tbl rpt-tbl-dim crt-tbl">
          <thead>
            <tr>
              <th>Creative</th>
              <th className="r">Spend</th>
              <th className="r">Share</th>
              <th className="r">ROAS</th>
              {hasComparison && <th className="r">Δ ROAS</th>}
              <th className="r">Purch.</th>
              <th className="r">CPA</th>
              <th className="r">CTR</th>
              <th className="r">Thumbstop</th>
              <th className="r">Hold</th>
              <th className="r">ATC</th>
            </tr>
          </thead>
          <tbody>
            {block.topCreatives.map((c) => (
              <tr key={c.id} className={c.rankings.belowAverage ? 'row-wound' : ''}>
                <td className="name">
                  <div className="rpt-dim-label">
                    {c.name}
                    {c.mediaType && <span className="crt-media">{MEDIA_LABEL[c.mediaType] ?? c.mediaType}</span>}
                    {c.rankings.belowAverage && <span className="rpt-badge b-wound crt-rank-badge">Below average</span>}
                  </div>
                </td>
                <td className="r">{money(c.spend)}</td>
                <td className="r">{pct(c.spendShare)}</td>
                <td className="r">{roas(c.roas)}</td>
                {hasComparison && <td className="r"><AbsDelta abs={c.roasDelta} /></td>}
                <td className="r">{c.purchases.toLocaleString()}</td>
                <td className="r">{money(c.cpa)}</td>
                <td className="r">{c.ctr === null ? '—' : `${c.ctr.toFixed(2)}%`}</td>
                <td className="r">{c.thumbstop === null ? '—' : `${c.thumbstop.toFixed(1)}%`}</td>
                <td className="r">{c.hold === null ? '—' : `${c.hold.toFixed(1)}%`}</td>
                <td className="r">{c.addToCarts.toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {block.totalCreatives > block.topCreatives.length && (
        <div className="rpt-cap">Showing the top {block.topCreatives.length} of {block.totalCreatives} creatives by spend.</div>
      )}
      <div className="rpt-cap">
        Thumbstop = 3-second video views ÷ impressions; hold = ThruPlays ÷ 3-second views — both apply to video
        creatives only. "Below average" reflects {label}'s own relevance diagnostics on the most recent ranked day.
      </div>

      {block.fatigued.length > 0 && (
        <>
          <div className="crt-sub-head">Fatigue watch</div>
          <div className="rpt-actions rpt-actions-stack">
            {block.fatigued.map((f) => (
              <div className="rpt-act dead" key={f.id}>
                <div className="rpt-act-k">▲ Fatigued</div>
                <div className="rpt-act-t">
                  <b>{f.name}</b> — {f.reason}. {money(f.spend)} behind it this period — refresh or rotate the creative.
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      {block.scaleCandidates.length > 0 && (
        <>
          <div className="crt-sub-head">Scale candidates</div>
          <div className="rpt-actions rpt-actions-stack">
            {block.scaleCandidates.map((s) => (
              <div className="rpt-act win" key={s.id}>
                <div className="rpt-act-k">★ Scale</div>
                <div className="rpt-act-t">
                  <b>{s.name}</b> — {roas(s.roas)} vs a {roas(s.platformMedian)} platform median on {money(s.spend)} spend
                  ({pct(s.spendShare)} of budget). The clearest place to put more.
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      {block.mediaMix.length > 0 && (
        <>
          <div className="crt-sub-head">Media mix</div>
          <div className="rpt-bars">
            {block.mediaMix.map((m) => (
              <div className="rpt-bar-row" key={m.mediaType}>
                <div className="rpt-bar-l">{MEDIA_LABEL[m.mediaType] ?? m.mediaType} · {m.creatives} creative{m.creatives === 1 ? '' : 's'}</div>
                <div className="rpt-bar-track"><div className="rpt-bar-fill" style={{ width: `${Math.round(((m.share ?? 0) / maxMix) * 100)}%` }} /></div>
                <div className="rpt-bar-n">{money(m.spend)}</div>
                <div className="rpt-bar-p">{pct(m.share)}</div>
              </div>
            ))}
          </div>
        </>
      )}
    </section>
  );
}

function AbsDelta({ abs }: { abs: number | null }) {
  if (abs === null) return <span className="flat">—</span>;
  const dir = abs > 0.005 ? 'up' : abs < -0.005 ? 'down' : 'flat';
  return <span className={dir}>{abs > 0 ? '+' : ''}{abs.toFixed(2)}×</span>;
}

const CREATIVE_CSS = `
.rpt .crt-kpis-4{grid-template-columns:repeat(4,1fr)}
.rpt .crt-kpis-4 .rpt-kpi-v{font-size:28px}
.rpt .crt-tbl td.r{font-size:11px}
.rpt .crt-tbl td.name{min-width:220px}
.rpt .crt-media{display:inline-block;margin-left:8px;font-size:9px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:2px 7px;border-radius:20px;background:rgba(0,0,0,.06);color:var(--ink-3);vertical-align:middle}
.rpt .crt-rank-badge{margin-left:8px;vertical-align:middle}
.rpt .crt-sub-head{font-family:var(--mono);font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);font-weight:600;margin:26px 0 12px}
.rpt .crt-empty{background:var(--paper);border:1px solid var(--line);border-left:3px solid var(--ink-4);border-radius:12px;padding:18px 22px;font-size:12.5px;color:var(--ink-3);line-height:1.6}
@media print{.rpt .crt-tbl{page-break-inside:avoid}}
`;
