import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { AppLayout } from '@/components/shell/AppLayout';
import { Button, Card, Segmented } from '@/components/ui';
import { ReportDocument } from '@/components/reports/ReportDocument';
import { MonthlyReportDocument } from '@/components/reports/MonthlyReportDocument';
import { useCreateShare, useReport } from '@/hooks/useReports';
import { useTriggerSync } from '@/hooks/useBrands';
import { toast } from '@/stores/toastStore';
import type { ReportFiltersInput } from '@/types/reports';

/**
 * In-app report view: filters, the editable white-label document, and the two
 * delivery actions. Before the report renders, a freshness gate checks the data
 * is current for the selected window — a client should never receive stale
 * numbers, so when the latest synced day is behind we block on a fresh sync
 * (with a "show anyway" escape).
 */
export function ReportViewPage() {
  const { slug, type } = useParams();
  const qc = useQueryClient();
  const [period, setPeriod] = useState<ReportFiltersInput['period']>('last30');
  const [compare, setCompare] = useState<ReportFiltersInput['compare']>('previous');
  const [commentary, setCommentary] = useState('');
  const [targets, setTargets] = useState<{ blendedRoas: number | null; newCustomerRoas: number | null }>({ blendedRoas: null, newCustomerRoas: null });
  const [showAnyway, setShowAnyway] = useState(false);

  const filters: ReportFiltersInput = { period, compare };
  const { data, isLoading, isError, error } = useReport(slug, type, filters);
  const createShare = useCreateShare(slug, type);
  const triggerSync = useTriggerSync();

  // A new window must re-check freshness, so the gate can't be bypassed for it.
  useEffect(() => setShowAnyway(false), [period, compare, slug, type]);

  const stale = !!data?.freshness && !data.freshness.upToDate;

  const onShare = () => {
    createShare.mutate(
      { filters, content: { commentary, targets } },
      {
        onSuccess: (res) => {
          const url = window.location.origin + res.url;
          navigator.clipboard?.writeText(url).catch(() => undefined);
          toast.success('Share link created', `${url} — copied to clipboard.`);
        },
      },
    );
  };

  const onSyncAndRefresh = () => {
    if (!slug) return;
    triggerSync.mutate(slug, {
      onSuccess: () => {
        toast.success('Sync started', 'Pulling fresh data — the report refreshes here in a moment.');
        // The sync runs on the queue; give it a head start, then refetch so the
        // gate clears itself once the latest day lands.
        setTimeout(() => qc.invalidateQueries({ queryKey: ['report', slug] }), 15000);
      },
      onError: (e: unknown) =>
        toast.error('Couldn’t start sync', (e as { message?: string })?.message ?? 'Unknown error'),
    });
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
        <Button variant="secondary" onClick={() => window.print()} disabled={!data || (stale && !showAnyway)}>
          Export PDF
        </Button>
        <Button variant="primary" onClick={onShare} disabled={!data || (stale && !showAnyway) || createShare.isPending}>
          {createShare.isPending ? 'Creating…' : 'Create share link'}
        </Button>
      </div>

      {isLoading && <div className="muted" style={{ padding: 24 }}>Building report…</div>}
      {isError && (
        <div className="muted" style={{ padding: 24 }}>
          Couldn’t load the report: {(error as any)?.response?.data?.message ?? (error as Error)?.message}
        </div>
      )}
      {data &&
        (stale && !showAnyway ? (
          <FreshnessGate
            staleDays={data.freshness!.staleDays}
            lastSynced={data.freshness!.lastSynced}
            windowEnd={data.freshness!.windowEnd}
            syncing={triggerSync.isPending}
            onSync={onSyncAndRefresh}
            onShowAnyway={() => setShowAnyway(true)}
          />
        ) : data.reportType === 'monthly' ? (
          <MonthlyReportDocument data={data} editable onCommentaryChange={setCommentary} onTargetsChange={setTargets} />
        ) : (
          <ReportDocument data={data} editable onCommentaryChange={setCommentary} />
        ))}
    </AppLayout>
  );
}

function FreshnessGate({
  staleDays,
  lastSynced,
  windowEnd,
  syncing,
  onSync,
  onShowAnyway,
}: {
  staleDays: number;
  lastSynced: string | null;
  windowEnd: string;
  syncing: boolean;
  onSync: () => void;
  onShowAnyway: () => void;
}) {
  return (
    <Card style={{ padding: 28, maxWidth: 560, margin: '24px auto', textAlign: 'center' }}>
      <div style={{ fontSize: 15, fontWeight: 600, marginBottom: 8 }}>
        Sync fresh data before generating this report
      </div>
      <p className="muted text-sm" style={{ marginBottom: 18, lineHeight: 1.6 }}>
        {lastSynced ? (
          <>
            The latest complete day on file is <b>{lastSynced}</b>, but this report covers through{' '}
            <b>{windowEnd}</b> — it’s <b>{staleDays} day{staleDays === 1 ? '' : 's'}</b> behind. Sync now so the
            numbers are correct before you send it.
          </>
        ) : (
          <>No synced data is on file for this brand yet. Run a sync to pull the data this report needs.</>
        )}
      </p>
      <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
        <Button variant="primary" onClick={onSync} disabled={syncing}>
          {syncing ? 'Starting sync…' : 'Sync now'}
        </Button>
        <Button variant="secondary" onClick={onShowAnyway}>
          Show anyway
        </Button>
      </div>
    </Card>
  );
}
