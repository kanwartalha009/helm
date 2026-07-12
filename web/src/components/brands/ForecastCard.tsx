import { Card } from '@/components/ui';
import { useBrandForecast } from '@/hooks/useForecast';
import { formatMoney } from '@/lib/formatters';

/**
 * Forecast baseline (GO-2.3) — seasonal-naive + drift.
 *
 * Two things this card must never do:
 *  1. Render a number without the "Modeled — baseline forecast" label (§0 law 1).
 *  2. Render anything at all when the engine refused. `insufficient_history` shows the
 *     reason and NO figures — a confident-looking number with nothing underneath is
 *     the single fastest way to lose a client's trust.
 */
export function ForecastCard({ slug, horizon }: { slug?: string; horizon?: number }) {
  const { data } = useBrandForecast(slug, horizon);

  if (!data) return null;

  // The refusal. Say why, offer the fix, show no numbers.
  if (data.status === 'insufficient_history') {
    return (
      <Card style={{ padding: 16, marginTop: 16 }}>
        <div className="flex items-center gap-8 mb-8">
          <div style={{ fontWeight: 600 }}>Revenue forecast</div>
          <span className="text-xs" style={{ color: 'var(--text-secondary)', fontWeight: 600 }}>{data.label}</span>
        </div>
        <div className="muted text-sm" style={{ maxWidth: 720, lineHeight: 1.55 }}>
          {data.reason} Helm won’t extrapolate from history it doesn’t have — a forecast built on missing data
          looks just as confident as a real one, which is exactly what makes it dangerous.
        </div>
      </Card>
    );
  }

  const c = data.currency ?? 'USD';
  const t = data.totals;

  return (
    <Card style={{ padding: 16, marginTop: 16 }}>
      <div className="flex items-center justify-between mb-8" style={{ flexWrap: 'wrap', gap: 8 }}>
        <div className="flex items-center gap-8">
          <div style={{ fontWeight: 600 }}>Revenue forecast</div>
          {/* The Modeled label ships with every number. Non-negotiable. */}
          <span
            className="text-xs"
            title={data.methodNote}
            style={{ color: 'var(--text-secondary)', fontWeight: 600, borderBottom: '1px dotted var(--text-muted)', cursor: 'help' }}
          >
            {data.label}
          </span>
        </div>
        <span className="text-xs muted">{data.periodStart} – {data.periodEnd}</span>
      </div>

      <div className="flex items-center gap-8 mb-8" style={{ flexWrap: 'wrap' }}>
        <span style={{ fontSize: 24, fontWeight: 700 }}>{t ? formatMoney(t.forecast, c) : '—'}</span>
        <span className="muted text-sm">projected over the next {data.horizonDays} days</span>
      </div>

      <div className="text-xs muted" style={{ display: 'grid', gap: 3, maxWidth: 760, lineHeight: 1.5 }}>
        {/* Both terms exposed, so the number can always be taken apart. */}
        <div>
          Last year over the same window: <b>{t ? formatMoney(t.seasonalOnly, c) : '—'}</b>
          {data.trendApplied && <> · trend applied <b>{data.trend}×</b></>}
        </div>
        <div style={data.trendClamped ? { color: 'var(--warning, #9a6700)' } : undefined}>{data.trendNote}</div>
        {data.coverage && data.coverage.missingDays > 0 && (
          <div>
            Last year is missing {data.coverage.missingDays} of {data.coverage.ofDays} days — those days contribute
            nothing to the total, rather than being counted as zero revenue.
          </div>
        )}
      </div>

      {data.monthEnd && (
        <div className="text-sm" style={{ marginTop: 12, borderTop: '1px solid var(--border)', paddingTop: 10 }}>
          <div className="flex items-center justify-between">
            <span className="muted">Projected month end</span>
            <span style={{ fontWeight: 600 }}>{formatMoney(data.monthEnd.projectedMonth, data.monthEnd.currency)}</span>
          </div>
          <div className="text-xs muted" style={{ marginTop: 2 }}>
            {formatMoney(data.monthEnd.actualToDate, data.monthEnd.currency)} actual so far (complete days) +{' '}
            {formatMoney(data.monthEnd.forecastRest, data.monthEnd.currency)} modelled for the rest of the month.
          </div>
        </div>
      )}
    </Card>
  );
}
