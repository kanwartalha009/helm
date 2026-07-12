import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { BrandSubnav } from '@/components/shell/BrandSubnav';
import { Breadcrumb, Button, Card } from '@/components/ui';
import { ForecastCard } from '@/components/brands/ForecastCard';
import { GapMapCard } from '@/components/planning/GapMapCard';
import { PlansPanel } from '@/components/planning/PlansPanel';
import { RecommendationBoard } from '@/components/planning/RecommendationBoard';
import { TrackRecordCard } from '@/components/planning/TrackRecordCard';
import { useBrandDetail } from '@/hooks/useApiData';
import { useCurrentUser } from '@/hooks/useSettings';
import { useBudgetPlan, useSaveBudgetPlan } from '@/hooks/useBudgetPlan';
import type { BudgetPlanRow } from '@/hooks/useBudgetPlan';
import { formatMoney, formatRoas } from '@/lib/formatters';
import { toast } from '@/stores/toastStore';

const PLATFORM_LABEL: Record<string, string> = { meta: 'Meta', google: 'Google', tiktok: 'TikTok' };

/** Next 3 months, 'Y-m'. */
function monthOptions(): string[] {
  const out: string[] = [];
  const now = new Date();
  for (let i = 1; i <= 3; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() + i, 1);
    out.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
  }
  return out;
}

/**
 * Budget planner (GO-2.2) — the "Planning" tab.
 *
 * This is a PLAN DOCUMENT. Helm does not push budgets to Meta, Google or TikTok, and
 * the page says so on every render rather than leaving the operator to assume. The
 * grid shows what actually happened (trailing 90 days), the monthly run-rate that
 * implies, what you intend to spend, and the gap between them.
 */
export function BrandPlanningPage() {
  const { slug } = useParams();
  const months = monthOptions();
  const [month, setMonth] = useState(months[0]);

  const { data: brandDetail } = useBrandDetail(slug);
  const { data: user } = useCurrentUser();
  const { data, isLoading } = useBudgetPlan(slug, month);

  const canEdit = user?.role === 'master_admin' || user?.role === 'manager';
  const brand = brandDetail?.brand;

  return (
    <AppLayout title="Planning">
      <Breadcrumb
        crumbs={[
          { label: 'Brands', to: '/brands' },
          { label: brand?.name ?? 'Brand', to: `/brands/${slug}` },
          { label: 'Planning' },
        ]}
      />

      <BrandSubnav slug={slug} />

      {/* GO-3.2 — the Stop/Scale/Fix board. Accepting records intent; it changes
          nothing in any ad platform. Sits above the budget grid because acting on
          what's already broken beats planning next month's spend. */}
      <RecommendationBoard slug={slug} canDecide={canEdit} />
      {/* GO-3.3 — Helm scored on its own advice. Computed live from the ledger; the
          losses are shown, not hidden. */}
      <TrackRecordCard slug={slug} />

      <div className="filter-bar mb-16" style={{ marginTop: 24 }}>
        <select className="input" style={{ maxWidth: 180 }} value={month} onChange={(e) => setMonth(e.target.value)}>
          {months.map((m) => <option key={m} value={m}>Plan for {m}</option>)}
        </select>
      </div>

      {isLoading && <div className="muted" style={{ padding: 24 }}>Loading plan…</div>}

      {data && data.rows.length === 0 && (
        <Card style={{ padding: 24 }}>
          <div className="muted text-sm">No ad platform is connected for this brand, so there is nothing to plan yet.</div>
        </Card>
      )}

      {data && data.rows.length > 0 && (
        <>
          <Card style={{ overflow: 'auto' }}>
            <table className="data-table">
              <thead>
                <tr>
                  <th>Platform</th>
                  <th className="num" title={`Actual spend over the last ${data.lookbackDays} days`}>Spend (90d)</th>
                  <th className="num" title="The platform's OWN reported ROAS — not store truth. MER on the brand page is the honest figure.">ROAS (reported)</th>
                  <th className="num" title="Spend per day with data, projected across the plan month. Days without data are not counted as zero.">Run rate / month</th>
                  <th className="num">Planned</th>
                  <th className="num">vs run rate</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.map((r) => (
                  <PlanRow key={r.platform} slug={slug} month={month} row={r} currency={data.currency} canEdit={canEdit} />
                ))}
                <tr>
                  <td style={{ fontWeight: 600 }}>Total</td>
                  <td className="num muted">—</td>
                  <td className="num muted">—</td>
                  <td className="num">{data.totals.runRateMonth === null ? '—' : formatMoney(data.totals.runRateMonth, data.currency)}</td>
                  <td className="num" style={{ fontWeight: 600 }}>
                    {data.totals.plannedSpend === null ? '—' : formatMoney(data.totals.plannedSpend, data.currency)}
                  </td>
                  <td className="num">
                    {data.totals.delta === null ? '—' : (
                      <span style={{ color: data.totals.delta >= 0 ? 'var(--warning, #9a6700)' : 'var(--success, #1f6f5c)' }}>
                        {data.totals.delta > 0 ? '+' : ''}{formatMoney(data.totals.delta, data.currency)}
                      </span>
                    )}
                  </td>
                </tr>
              </tbody>
            </table>
          </Card>

          {/* Said out loud, every render. */}
          <div className="text-xs muted mt-16" style={{ maxWidth: 760, lineHeight: 1.55 }}>
            {data.executionNote}
          </div>
        </>
      )}

      {/* GO-2.3 — the baseline the plan is being sized against. Refuses on thin
          history rather than extrapolating; every number carries the Modeled label. */}
      <ForecastCard slug={slug} />

      {/* GO-3.4 — where rivals are live and we are not. Proxy (their presence) and
          Verified (our spend) are labelled separately and never mixed. Feeds GO-4. */}
      <GapMapCard slug={slug} />

      {/* GO-4.3 — seasonal campaign plans. Every figure rule-assembled and traceable;
          the AI only rewrites them as prose, it never produces a number. */}
      <PlansPanel slug={slug} canEdit={canEdit} />
    </AppLayout>
  );
}

function PlanRow({
  slug, month, row, currency, canEdit,
}: { slug?: string; month: string; row: BudgetPlanRow; currency: string; canEdit: boolean }) {
  const save = useSaveBudgetPlan(slug);
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(row.plannedSpend !== null ? String(row.plannedSpend) : '');

  const commit = () => {
    const n = Number(value);
    if (!Number.isFinite(n) || n < 0) return;
    save.mutate(
      { month, platform: row.platform, planned_spend: n },
      { onSuccess: () => { setEditing(false); toast.success('Plan saved', `${PLATFORM_LABEL[row.platform]}: ${formatMoney(n, currency)} for ${month}.`); },
        onError: () => toast.error('Could not save the plan', 'Admins and managers only.') },
    );
  };

  return (
    <tr>
      <td style={{ fontWeight: 500 }}>{PLATFORM_LABEL[row.platform] ?? row.platform}</td>
      <td className="num">
        {row.spend90 === null ? <span className="muted">—</span> : formatMoney(row.spend90, currency)}
        {row.days90 > 0 && row.days90 < 90 && (
          <span className="muted text-xs" title="Run rate is based on these days, not on 90."> · {row.days90}d of data</span>
        )}
      </td>
      <td className="num">{row.reportedRoas === null ? <span className="muted">—</span> : formatRoas(row.reportedRoas)}</td>
      <td className="num">{row.runRateMonth === null ? <span className="muted">—</span> : formatMoney(row.runRateMonth, currency)}</td>
      <td className="num">
        {editing ? (
          <span className="flex items-center gap-8" style={{ justifyContent: 'flex-end' }}>
            <input
              className="input"
              type="number"
              min={0}
              step="1"
              autoFocus
              value={value}
              onChange={(e) => setValue(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') commit(); if (e.key === 'Escape') setEditing(false); }}
              style={{ maxWidth: 110, padding: '2px 6px' }}
            />
            <Button size="sm" variant="secondary" disabled={save.isPending} onClick={commit}>save</Button>
          </span>
        ) : canEdit ? (
          <button
            type="button"
            style={{ background: 'none', border: 0, cursor: 'pointer', font: 'inherit', color: 'inherit' }}
            title="Set the planned spend for this platform"
            onClick={() => setEditing(true)}
          >
            {row.plannedSpend !== null ? formatMoney(row.plannedSpend, currency) : <span className="muted">plan</span>}
          </button>
        ) : row.plannedSpend !== null ? formatMoney(row.plannedSpend, currency) : <span className="muted">—</span>}
      </td>
      <td className="num">
        {row.delta === null ? <span className="muted">—</span> : (
          <span style={{ color: row.delta >= 0 ? 'var(--warning, #9a6700)' : 'var(--success, #1f6f5c)' }}>
            {row.delta > 0 ? '+' : ''}{formatMoney(row.delta, currency)}
            {row.deltaPct !== null && <span className="muted text-xs"> · {row.deltaPct > 0 ? '+' : ''}{row.deltaPct}%</span>}
          </span>
        )}
      </td>
    </tr>
  );
}
