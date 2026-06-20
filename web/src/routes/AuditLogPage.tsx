import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import {
  Banner,
  Button,
  Chip,
  PageEmptyState,
  PageHeader,
} from '@/components/ui';
import { useAuditLogs } from '@/hooks/useApiData';

function initials(name: string): string {
  return name
    .split(' ')
    .map((p) => p[0])
    .join('')
    .slice(0, 2)
    .toUpperCase();
}

function whenLabel(iso: string): string {
  if (!iso) return '—';
  // Heuristic — if the backend sent a relative string like "12 min ago",
  // pass it through verbatim. Otherwise render the ISO timestamp.
  if (!/^\d{4}-/.test(iso)) return iso;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString();
}

export function AuditLogPage() {
  // Cursor pagination: stack of cursors for back-navigation, plus current cursor.
  const [cursorStack, setCursorStack] = useState<string[]>([]);
  const currentCursor = cursorStack[cursorStack.length - 1];
  const { data: page, isLoading, isError, error } = useAuditLogs(currentCursor);
  const logs = page?.data ?? [];

  if (isLoading) {
    return (
      <AppLayout title="Audit log">
        <PageHeader title="Audit log" subtitle="Append-only ledger of sensitive actions. Never deleted." />
        <div className="muted" style={{ padding: 24 }}>
          Loading…
        </div>
      </AppLayout>
    );
  }

  if (isError) {
    return (
      <AppLayout title="Audit log">
        <PageHeader title="Audit log" subtitle="Append-only ledger of sensitive actions. Never deleted." />
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
          Couldn&rsquo;t load audit log: {(error as Error)?.message ?? 'unknown error'}.
        </Banner>
      </AppLayout>
    );
  }

  if (logs.length === 0) {
    return (
      <AppLayout title="Audit log">
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
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
              <polyline points="14 2 14 8 20 8" />
              <line x1="16" y1="13" x2="8" y2="13" />
              <line x1="16" y1="17" x2="8" y2="17" />
              <polyline points="10 9 9 9 8 9" />
            </svg>
          }
          title="No activity yet"
          body="As you and your team work in Roasdriven, every sensitive action — sign-ins, invitations, credential rotations, brand access changes — lands here. Append-only, never deleted."
          footnote={
            <Link to="/sitemap" style={{ color: 'inherit' }}>
              Audit log explained →
            </Link>
          }
        />
      </AppLayout>
    );
  }

  return (
    <AppLayout title="Audit log">
      <PageHeader
        title="Audit log"
        subtitle="Append-only ledger of sensitive actions. Never deleted."
        actions={
          <a
            href="/api/audit-logs/export.csv"
            // The export endpoint is auth'd via the Sanctum bearer; using a
            // plain <a download> works because the axios interceptor sets
            // the header on every fetch, but anchor clicks bypass axios. We
            // hand-build a click handler that pulls the CSV via fetch with
            // the bearer attached, then triggers a blob download.
            onClick={(e) => {
              e.preventDefault();
              const token = localStorage.getItem('helm.auth.token');
              fetch('/api/audit-logs/export.csv', {
                headers: token ? { Authorization: `Bearer ${token}` } : {},
              })
                .then((r) => r.blob())
                .then((blob) => {
                  const url = URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = url;
                  a.download = `helm-audit-log-${new Date().toISOString().slice(0, 19)}.csv`;
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

      <div className="filter-bar mb-16">
        <input
          className="input"
          type="text"
          placeholder="Filter by user, action, or target…"
          style={{ maxWidth: 320 }}
        />
        <Chip active>All actions</Chip>
        <Chip>Auth</Chip>
        <Chip>Users</Chip>
        <Chip>Brands</Chip>
        <Chip>Connections</Chip>
        <Chip>Impersonation</Chip>
        <span style={{ flex: 1 }} />
        <Chip>Last 7 days</Chip>
      </div>

      <div className="card" style={{ overflow: 'hidden' }}>
        <table className="log-table">
          <thead>
            <tr>
              <th style={{ width: '16%' }}>Actor</th>
              <th style={{ width: '22%' }}>Action</th>
              <th>Target</th>
              <th>IP</th>
              <th>When</th>
            </tr>
          </thead>
          <tbody>
            {logs.map((log) => {
              // System events (no actor) and dangling refs both come through
              // with actor=null. Render them as "system" so the table never crashes.
              const actorName = log.actor?.name ?? 'system';
              return (
              <tr key={log.id}>
                <td>
                  <div className="brand-cell">
                    <span className="avatar-sm">{initials(actorName)}</span>
                    {actorName}
                  </div>
                </td>
                <td>
                  <span className="mono">{log.action}</span>
                </td>
                <td>{log.target}</td>
                <td className="mono">{log.ip || '—'}</td>
                <td className="muted">{whenLabel(log.createdAt)}</td>
              </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <div className="flex items-center justify-between mt-24">
        <div className="text-xs muted">
          Showing {logs.length} event{logs.length === 1 ? '' : 's'}
          {cursorStack.length > 0 ? ` · page ${cursorStack.length + 1}` : ''}.
        </div>
        <div className="flex items-center gap-8">
          <Button
            size="sm"
            variant="secondary"
            disabled={cursorStack.length === 0}
            onClick={() => setCursorStack((s) => s.slice(0, -1))}
          >
            Previous
          </Button>
          <Button
            size="sm"
            variant="secondary"
            disabled={!page?.hasMore || !page?.nextCursor}
            onClick={() => {
              if (page?.nextCursor) setCursorStack((s) => [...s, page.nextCursor!]);
            }}
          >
            Next
          </Button>
        </div>
      </div>
    </AppLayout>
  );
}
