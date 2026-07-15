import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { DataCoverageCard } from '@/components/brands/DataCoverageCard';
import { MomFilterBar, toMomFilters, type CompareChoice } from '@/components/reports/mom/MomFilterBar';
import { MomReportDocument } from '@/components/reports/mom/MomReportDocument';
import { useMomReport } from '@/hooks/useMomReport';

/**
 * REV2 (monthly-report-v2-mom.md) — the "mom" report's OWN route, deliberately
 * separate from ReportViewPage's generic /brands/:slug/reports/:type
 * (v1 monthly/weekly/creatives/ads-audit). Two reasons this is a dedicated
 * page rather than one more branch in ReportViewPage:
 *
 *  1. Architecture (M0): mom is section-streamed — the shell fetch here
 *     returns only the manifest; each MomSectionCard fires its OWN request.
 *     ReportViewPage's useReport() fetches one full report payload in one
 *     call, which is exactly the monolith pattern M0 exists to prevent.
 *  2. REV2 R7 / v1 untouched: keeping this on its own route/component means
 *     zero risk of a mom-specific change ever touching ReportViewPage's
 *     already-live v1 code paths.
 *
 * React Router v6 ranks the literal '/reports/mom' segment above the
 * generic '/reports/:type' regardless of declaration order, so this route
 * and ReportViewPage's coexist without conflict.
 */
export function MomReportPage() {
  const { slug } = useParams();
  const [month, setMonth] = useState<string | undefined>(undefined);
  const [compareChoice, setCompareChoice] = useState<CompareChoice>('previous');
  const [customCompareMonth, setCustomCompareMonth] = useState('');

  const filters = toMomFilters(month, compareChoice, customCompareMonth);
  const { data: shell, isLoading, isError, error } = useMomReport(slug, filters);

  // The route is reused across /brands/:slug/reports/mom param changes —
  // filter state must not leak from one brand to the next (same pattern as
  // ReportViewPage's slug/type reset effect).
  useEffect(() => {
    setMonth(undefined);
    setCompareChoice('previous');
    setCustomCompareMonth('');
  }, [slug]);

  return (
    <AppLayout title="MoM Strategy Report">
      {/* M5 (monthly-report-v2-mom.md §M5) — "Report view embeds the existing
          DataCoverageCard at top when ANY section reports missing coverage."
          Same component v1's reports already use — renders nothing when the
          brand has no gaps, so a fully-synced brand sees no change here. */}
      {slug && <DataCoverageCard slug={slug} compact />}
      <MomFilterBar
        shell={shell}
        month={month}
        onMonthChange={setMonth}
        compareChoice={compareChoice}
        onCompareChoiceChange={setCompareChoice}
        customCompareMonth={customCompareMonth}
        onCustomCompareMonthChange={setCustomCompareMonth}
      />

      {isLoading && <div className="muted" style={{ padding: 24 }}>Building report…</div>}
      {isError && (
        <div className="muted" style={{ padding: 24 }}>
          Couldn’t load the report: {(error as any)?.response?.data?.message ?? (error as Error)?.message}
        </div>
      )}
      {shell && slug && <MomReportDocument slug={slug} shell={shell} filters={filters} />}
    </AppLayout>
  );
}
