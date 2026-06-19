import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import {
  Banner,
  Button,
  Card,
  EmptyState,
  PageEmptyState,
  PageHeader,
  Popover,
  PopoverItem,
  PopoverLabel,
  Tabs,
} from '@/components/ui';
import { useRetrySyncLog, useSyncStatus } from '@/hooks/useApiData';
import { useUiStore } from '@/stores/uiStore';
import { toast } from '@/stores/toastStore';

function RetryButton({ logId }: { logId: number }) {
  const retry = useRetrySyncLog();
  return (
    <Button
      size="sm"
      variant="secondary"
      disabled={retry.isPending}
      onClick={() => {
        retry.mutate(logId, {
          onSuccess: () => toast.success('Retry queued', 'The sync will run in the background.'),
          onError: (err: any) => {
            toast.error("Couldn't retry", err?.response?.data?.message ?? err.message);
          },
        });
      }}
    >
      {retry.isPending ? 'Queuing…' : 'Retry'}
    </Button>
  );
}

function whenLabel(iso: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString();
}

export function SyncHealthPage() {
  const { data, isLoading, isError, error } = useSyncStatus();
  const openAddBrand = useUiStore((s) => s.setAddBrandDrawerOpen);
  const [brandFilter, setBrandFilter] = useState<number | 'all'>('all');
  const logs = data?.logs ?? [];
  const counts =
    data?.counts ?? { successful: 0, failed: 0, running: 0, queued: 0 };

  if (isLoading) {
    return (
      <AppLayout title="Sync health">
        <PageHeader title="Sync health" subtitle="Last 24 hours across all brands and platforms." />
        <div className="muted" style={{ padding: 24 }}>
          Loading sync history…
        </div>
      </AppLayout>
    );
  }

  if (isError) {
    return (
      <AppLayout title="Sync health">
        <PageHeader title="Sync health" subtitle="Last 24 hours across all brands and platforms." />
        <Banner
          variant="warning"
          icon={
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
          }
        >
          Couldn&rsquo;t load sync status: {(error as Error)?.message ?? 'unknown error'}.
        </Banner>
      </AppLayout>
    );
  }

  if (logs.length === 0) {
    return (
      <AppLayout title="Sync health">
        <PageEmptyState
          icon={
            <svg
              width="28"
              height="28"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.75"
            >
              <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" />
              <polyline points="21 3 21 8 16 8" />
              <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" />
              <polyline points="3 21 3 16 8 16" />
            </svg>
          }
          title="No sync data yet"
          body="Sync logs appear once a brand is connected and the daily 13:00 UTC sync runs. You can also trigger a manual sync from a brand's page."
          primary={
            <button onClick={() => openAddBrand(true)} className="btn btn-primary btn-lg">
              <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
              >
                <path d="M12 5v14M5 12h14" />
              </svg>
              Add brand
            </button>
          }
          secondary={
            <Link to="/brands" className="btn btn-secondary btn-lg">
              View brands
            </Link>
          }
          steps={[
            {
              n: 1,
              title: 'Add a brand',
              body: 'Name, timezone, base currency.',
              onClick: () => openAddBrand(true),
              cta: 'Add brand',
            },
            {
              n: 2,
              title: 'Connect platforms',
              body: 'Pick the Shopify store, Meta ad account, Google customer, TikTok advertiser.',
            },
            {
              n: 3,
              title: 'Wait for the next run',
              body: 'Daily syncs run at 13:00 UTC. You can also trigger one manually.',
            },
          ]}
        />
      </AppLayout>
    );
  }

  // Per-brand filter for the log tables. Options are the brands present in the
  // current log window, so the dropdown only lists brands that actually synced.
  const brandOptions = Array.from(
    new Map(logs.filter((l) => l.brand).map((l) => [l.brand!.id, l.brand!.name])).entries(),
  ).sort((a, b) => a[1].localeCompare(b[1]));
  const filteredLogs =
    brandFilter === 'all' ? logs : logs.filter((l) => l.brand?.id === brandFilter);
  const selectedBrandName =
    brandFilter === 'all'
      ? 'All brands'
      : brandOptions.find(([id]) => id === brandFilter)?.[1] ?? 'Brand';

  const failed = filteredLogs.filter((l) => l.status === 'failed');
  const recent = filteredLogs.slice(0, 50);
  const allRows = filteredLogs.slice(0, 200);

  return (
    <AppLayout title="Sync health">
      <PageHeader
        title="Sync health"
        subtitle="Last 24 hours across all brands and platforms."
        actions={
          <div className="flex items-center gap-8">
            {brandOptions.length > 0 && (
              <Popover
                trigger={
                  <button className="filter-btn">
                    Brand: <strong style={{ fontWeight: 500 }}>{selectedBrandName}</strong>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <polyline points="6 9 12 15 18 9" />
                    </svg>
                  </button>
                }
              >
                <PopoverLabel>Filter by brand</PopoverLabel>
                <PopoverItem active={brandFilter === 'all'} onClick={() => setBrandFilter('all')}>
                  All brands
                </PopoverItem>
                {brandOptions.map(([id, name]) => (
                  <PopoverItem
                    key={id}
                    active={brandFilter === id}
                    onClick={() => setBrandFilter(id)}
                  >
                    {name}
                  </PopoverItem>
                ))}
              </Popover>
            )}
            <a
              href="/api/sync/status/export.csv"
              onClick={(e) => {
                e.preventDefault();
                const token = localStorage.getItem('helm.auth.token');
                fetch('/api/sync/status/export.csv', {
                  headers: token ? { Authorization: `Bearer ${token}` } : {},
                })
                  .then((r) => r.blob())
                  .then((blob) => {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `helm-sync-log-${new Date().toISOString().slice(0, 19)}.csv`;
                    a.click();
                    URL.revokeObjectURL(url);
                  });
              }}
              className="btn btn-secondary btn-sm"
            >
              Export CSV
            </a>
          </div>
        }
      />

      <div className="stat-grid stat-grid-4 mb-24">
        <div className="stat">
          <div className="stat-label">Successful</div>
          <div className="stat-value num">{counts.successful}</div>
          <div className="stat-sub muted">Last 24 hours</div>
        </div>
        <div className="stat">
          <div className="stat-label">Failed</div>
          <div className="stat-value num">{counts.failed}</div>
          <div
            className="stat-sub"
            style={{ color: counts.failed > 0 ? 'var(--warning)' : 'var(--text-muted)' }}
          >
            {counts.failed === 0 ? 'All green' : 'Needs attention'}
          </div>
        </div>
        <div className="stat">
          <div className="stat-label">In progress</div>
          <div className="stat-value num">{counts.running}</div>
          <div className="stat-sub muted">{counts.running > 0 ? 'Running now' : 'Idle'}</div>
        </div>
        <div className="stat">
          <div className="stat-label">Queued</div>
          <div className="stat-value num">{counts.queued}</div>
          <div className="stat-sub muted">{counts.queued > 0 ? 'Waiting' : 'Healthy queue'}</div>
        </div>
      </div>

      <Tabs
        tabs={[
          {
            id: 'failed',
            label: `Failed (${failed.length})`,
            content:
              failed.length === 0 ? (
                <EmptyState
                  title="No failed syncs"
                  description="Every sync in the recent window completed cleanly. Failed syncs would appear here for retry."
                />
              ) : (
                <Card style={{ overflow: 'hidden' }}>
                  <table className="log-table">
                    <thead>
                      <tr>
                        <th>Brand</th>
                        <th>Platform</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Error</th>
                        <th>Last attempt</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {failed.map((log) => (
                        <tr key={log.id}>
                          <td>
                            <strong>{log.brand?.name ?? 'unknown brand'}</strong>
                          </td>
                          <td style={{ textTransform: 'capitalize' }}>{log.platform}</td>
                          <td className="mono">{log.targetDate}</td>
                          <td>
                            <span className="status-pill failed">Failed</span>
                          </td>
                          <td>
                            <span className="mono" style={{ color: 'var(--danger)' }}>
                              {log.errorMessage ?? '—'}
                            </span>
                          </td>
                          <td className="muted">{whenLabel(log.completedAt)}</td>
                          <td className="text-right">
                            <RetryButton logId={log.id} />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </Card>
              ),
          },
          {
            id: 'recent',
            label: `Recent (${recent.length})`,
            content: (
              <Card style={{ overflow: 'hidden' }}>
                <table className="log-table">
                  <thead>
                    <tr>
                      <th>Brand</th>
                      <th>Platform</th>
                      <th>Date</th>
                      <th>Status</th>
                      <th>Duration</th>
                      <th>Completed</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recent.map((log) => (
                      <tr key={log.id}>
                        <td>{log.brand?.name ?? 'unknown brand'}</td>
                        <td style={{ textTransform: 'capitalize' }}>{log.platform}</td>
                        <td className="mono">{log.targetDate}</td>
                        <td>
                          <span className={`status-pill ${log.status}`}>
                            {log.status[0].toUpperCase() + log.status.slice(1)}
                          </span>
                        </td>
                        <td className="mono">
                          {log.durationMs ? `${(log.durationMs / 1000).toFixed(1)}s` : '—'}
                        </td>
                        <td className="muted">{whenLabel(log.completedAt)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Card>
            ),
          },
          {
            id: 'all',
            label: `All (${allRows.length})`,
            content:
              allRows.length === 0 ? (
                <EmptyState
                  title="No sync logs"
                  description="No sync activity for this filter in the current window."
                />
              ) : (
                <Card style={{ overflow: 'hidden' }}>
                  <table className="log-table">
                    <thead>
                      <tr>
                        <th>Brand</th>
                        <th>Platform</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th>Completed</th>
                      </tr>
                    </thead>
                    <tbody>
                      {allRows.map((log) => (
                        <tr key={log.id}>
                          <td>{log.brand?.name ?? 'unknown brand'}</td>
                          <td style={{ textTransform: 'capitalize' }}>{log.platform}</td>
                          <td className="mono">{log.targetDate}</td>
                          <td>
                            <span className={`status-pill ${log.status}`}>
                              {log.status[0].toUpperCase() + log.status.slice(1)}
                            </span>
                          </td>
                          <td className="mono">
                            {log.durationMs ? `${(log.durationMs / 1000).toFixed(1)}s` : '—'}
                          </td>
                          <td className="muted">{whenLabel(log.completedAt)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </Card>
              ),
          },
        ]}
      />
    </AppLayout>
  );
}
