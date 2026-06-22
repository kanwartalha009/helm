import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Button, Segmented } from '@/components/ui';
import { ReportDocument } from '@/components/reports/ReportDocument';
import { useCreateShare, useReport } from '@/hooks/useReports';
import { toast } from '@/stores/toastStore';
import type { ReportFiltersInput } from '@/types/reports';

/**
 * In-app report view: filters, the editable white-label document, and the two
 * delivery actions. Export PDF uses the browser print path (print CSS lives in
 * ReportDocument); Create share link snapshots the filters + edited commentary
 * to a public token and copies the URL.
 */
export function ReportViewPage() {
  const { slug, type } = useParams();
  const [period, setPeriod] = useState<ReportFiltersInput['period']>('last30');
  const [compare, setCompare] = useState<ReportFiltersInput['compare']>('previous');
  const [commentary, setCommentary] = useState('');

  const filters: ReportFiltersInput = { period, compare };
  const { data, isLoading, isError, error } = useReport(slug, type, filters);
  const createShare = useCreateShare(slug, type);

  const onShare = () => {
    createShare.mutate(
      { filters, content: { commentary } },
      {
        onSuccess: (res) => {
          const url = window.location.origin + res.url;
          navigator.clipboard?.writeText(url).catch(() => undefined);
          toast.success('Share link created', `${url} — copied to clipboard.`);
        },
      },
    );
  };

  return (
    <AppLayout title="Report">
      <div className="filter-bar mb-12" style={{ gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
        <Segmented
          options={[
            { value: 'last7', label: 'Last 7 days' },
            { value: 'last30', label: 'Last 30 days' },
            { value: 'mtd', label: 'Month to date' },
          ]}
          value={period}
          onChange={(v) => setPeriod(v as ReportFiltersInput['period'])}
        />
        <Segmented
          options={[
            { value: 'previous', label: 'vs previous' },
            { value: 'last_year', label: 'vs last year' },
          ]}
          value={compare}
          onChange={(v) => setCompare(v as ReportFiltersInput['compare'])}
        />
        <span style={{ flex: 1 }} />
        <Button variant="secondary" onClick={() => window.print()} disabled={!data}>
          Export PDF
        </Button>
        <Button variant="primary" onClick={onShare} disabled={!data || createShare.isPending}>
          {createShare.isPending ? 'Creating…' : 'Create share link'}
        </Button>
      </div>

      {isLoading && <div className="muted" style={{ padding: 24 }}>Building report…</div>}
      {isError && (
        <div className="muted" style={{ padding: 24 }}>
          Couldn’t load the report: {(error as any)?.response?.data?.message ?? (error as Error)?.message}
        </div>
      )}
      {data && <ReportDocument data={data} editable onCommentaryChange={setCommentary} />}
    </AppLayout>
  );
}
