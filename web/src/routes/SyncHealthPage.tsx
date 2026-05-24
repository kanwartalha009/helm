import { Link } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import {
  Banner,
  Button,
  Card,
  EmptyState,
  PageEmptyState,
  PageHeader,
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

  const failed = logs.filter((l) => l.status === 'failed');
  const recent = logs.slice(0, 50);

  return (
    <AppLayout title="Sync health">
      <PageHeader
        title="Sync health"
        subtitle="Last 24 hours across all brands and platforms."
        actions={
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
            label: 'All',
            content: (
              <EmptyState
                title="Full log"
                description="The full 90-day sync log lives here. Filterable by brand, platform, status, and date range."
                action={
                  <Button size="sm" variant="secondary">
                    Export CSV
                  </Button>
                }
              />
            ),
          },
        ]}
      />
    </AppLayout>
  );
}
