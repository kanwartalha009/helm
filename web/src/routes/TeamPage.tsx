import { useState } from 'react';
import { APP_NAME } from '@/lib/branding';
import { Link } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import {
  Banner,
  Button,
  Dot,
  EmptyState,
  PageEmptyState,
  PageHeader,
  Tabs,
  Tag,
} from '@/components/ui';
import { useUsers } from '@/hooks/useApiData';
import {
  useDeleteUser,
  useInvitations,
  useRevokeInvitation,
  type Invitation,
} from '@/hooks/useInvitations';
import { useCurrentUser } from '@/hooks/useSettings';
import { useUiStore } from '@/stores/uiStore';
import { BrandAccessDrawer } from '@/components/team/BrandAccessDrawer';
import type { User } from '@/types/domain';

const ROLE_LABEL: Record<User['role'], string> = {
  master_admin: 'Master admin',
  manager: 'Manager',
  team_member: 'Team member',
  brand_user: 'Brand user',
};

function lastSeenLabel(iso: string | null): string {
  if (!iso) return 'Never signed in';
  const ms = Date.now() - new Date(iso).getTime();
  const min = Math.round(ms / 60_000);
  if (min < 1) return 'Online';
  if (min < 60) return `${min} min ago`;
  const hr = Math.round(min / 60);
  if (hr < 24) return `${hr} hr ago`;
  const days = Math.round(hr / 24);
  if (days === 1) return 'Yesterday';
  return `${days} days ago`;
}

export function TeamPage() {
  const { data: currentUser } = useCurrentUser();
  const { data: users = [], isLoading, isError, error } = useUsers();
  const { data: invitations = [] } = useInvitations();
  const revokeInvitation = useRevokeInvitation();
  const deleteUser = useDeleteUser();
  const openInvite = useUiStore((s) => s.setInviteUserDrawerOpen);
  const [assignUser, setAssignUser] = useState<User | null>(null);

  if (isLoading) {
    return (
      <AppLayout title="Team">
        <PageHeader title="Team" subtitle={`Internal users and brand-side users with access to ${APP_NAME}.`} />
        <div className="muted" style={{ padding: 24 }}>
          Loading team…
        </div>
      </AppLayout>
    );
  }

  if (isError) {
    return (
      <AppLayout title="Team">
        <PageHeader title="Team" subtitle={`Internal users and brand-side users with access to ${APP_NAME}.`} />
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
          Couldn&rsquo;t load the team: {(error as Error)?.message ?? 'unknown error'}.
        </Banner>
      </AppLayout>
    );
  }

  const active = users.filter((u) => u.status === 'active');
  const disabled = users.filter((u) => u.status === 'disabled');
  // Pending invitations live in their own table now — distinct from User rows
  // that happen to have status='invited' (legacy seed).
  const pendingInvites = invitations.filter((i) => i.status === 'pending');

  // "Flying solo" — only the current user exists.
  if (active.length <= 1) {
    return (
      <AppLayout title="Team">
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
              <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
              <circle cx="8.5" cy="7" r="4" />
              <line x1="20" y1="8" x2="20" y2="14" />
              <line x1="23" y1="11" x2="17" y2="11" />
            </svg>
          }
          title="You're flying solo"
          body="Invite a teammate to manage brands together. Managers and team members can see every brand; brand users see only their own."
          primary={
            <button onClick={() => openInvite(true)} className="btn btn-primary btn-lg">
              <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
              >
                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="8.5" cy="7" r="4" />
                <line x1="20" y1="8" x2="20" y2="14" />
                <line x1="23" y1="11" x2="17" y2="11" />
              </svg>
              Invite a teammate
            </button>
          }
          secondary={
            <Link to="/audit-log" className="btn btn-secondary btn-lg">
              View audit log
            </Link>
          }
          steps={[
            {
              n: 1,
              title: 'Pick a role',
              body: 'Manager sees everything. Team member sees assigned brands. Brand user sees one brand.',
            },
            {
              n: 2,
              title: 'Send invite',
              body: 'They receive an email link. Invitations expire in 7 days.',
            },
            {
              n: 3,
              title: 'They sign in',
              body: 'On first login they set a password and optionally configure MFA.',
            },
          ]}
        />
      </AppLayout>
    );
  }

  return (
    <AppLayout title="Team" tag={`${active.length} active`}>
      <PageHeader
        title="Team"
        subtitle={`Internal users and brand-side users with access to ${APP_NAME}.`}
        actions={
          <button onClick={() => openInvite(true)} className="btn btn-primary btn-sm">
            <svg
              width="14"
              height="14"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
              <circle cx="8.5" cy="7" r="4" />
              <line x1="20" y1="8" x2="20" y2="14" />
              <line x1="23" y1="11" x2="17" y2="11" />
            </svg>
            Invite user
          </button>
        }
      />

      <Tabs
        tabs={[
          {
            id: 'active',
            label: `Active (${active.length})`,
            content: (
              <div className="card" style={{ overflow: 'hidden' }}>
                <table className="data-table">
                  <thead>
                    <tr>
                      <th style={{ width: '30%' }}>Name</th>
                      <th>Role</th>
                      <th>Brands</th>
                      <th>MFA</th>
                      <th>Last seen</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {active.map((u) => {
                      const isYou = currentUser?.id === u.id;
                      const brandsLabel =
                        u.role === 'master_admin' || u.role === 'manager'
                          ? 'All brands'
                          : u.accessibleBrandIds.length === 0
                          ? '—'
                          : `${u.accessibleBrandIds.length} brand${u.accessibleBrandIds.length === 1 ? '' : 's'}`;
                      return (
                        <tr key={u.id}>
                          <td>
                            <div className="brand-cell">
                              <span
                                className="brand-avatar"
                                style={{
                                  borderRadius: '50%',
                                  ...(isYou
                                    ? {
                                        background: 'var(--accent)',
                                        color: 'var(--accent-fg)',
                                        borderColor: 'var(--accent)',
                                      }
                                    : {}),
                                }}
                              >
                                {u.displayInitials}
                              </span>
                              <div>
                                <div style={{ fontWeight: 500 }}>
                                  {u.name}
                                  {isYou && <Tag style={{ marginLeft: 4 }}>You</Tag>}
                                </div>
                                <div className="brand-meta">{u.email}</div>
                              </div>
                            </div>
                          </td>
                          <td>{ROLE_LABEL[u.role]}</td>
                          <td className="muted">{brandsLabel}</td>
                          <td>
                            {u.mfaEnabled ? (
                              <Tag variant="success">Enabled</Tag>
                            ) : u.role === 'master_admin' ? (
                              <Tag variant="warning">
                                <Dot variant="warning" />
                                Required
                              </Tag>
                            ) : (
                              <span className="muted">Not set</span>
                            )}
                          </td>
                          <td className="muted text-sm">{lastSeenLabel(u.lastLoginAt)}</td>
                          <td className="text-right">
                            <div className="flex items-center justify-end gap-8">
                              <button
                                className="btn btn-secondary btn-sm"
                                onClick={() => setAssignUser(u)}
                              >
                                Assign brands
                              </button>
                              <Link
                                to={isYou ? '/profile' : `/team/users/${u.id}`}
                                className="btn btn-ghost btn-sm"
                              >
                                {isYou ? 'Open →' : 'Edit →'}
                              </Link>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            ),
          },
          {
            id: 'invited',
            label: `Invited (${pendingInvites.length})`,
            content:
              pendingInvites.length === 0 ? (
                <EmptyState
                  title="No outstanding invitations"
                  description="When you invite someone, the pending invite shows up here until they accept it (7-day expiry)."
                  action={
                    <button onClick={() => openInvite(true)} className="btn btn-primary btn-sm">
                      Invite a teammate
                    </button>
                  }
                />
              ) : (
                <div className="card" style={{ overflow: 'hidden' }}>
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Invited by</th>
                        <th>Expires</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {pendingInvites.map((i: Invitation) => (
                        <tr key={i.id}>
                          <td>{i.email}</td>
                          <td>{ROLE_LABEL[i.role as User['role']] ?? i.role}</td>
                          <td className="muted text-sm">{i.invitedBy?.name ?? '—'}</td>
                          <td className="muted text-sm">
                            {i.expiresAt ? new Date(i.expiresAt).toLocaleDateString() : '—'}
                          </td>
                          <td className="text-right">
                            <Button
                              size="sm"
                              variant="ghost"
                              style={{ color: 'var(--danger)' }}
                              disabled={revokeInvitation.isPending}
                              onClick={() => {
                                if (window.confirm(`Revoke invitation for ${i.email}?`)) {
                                  revokeInvitation.mutate(i.id);
                                }
                              }}
                            >
                              Revoke
                            </Button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ),
          },
          {
            id: 'disabled',
            label: `Disabled (${disabled.length})`,
            content:
              disabled.length === 0 ? (
                <EmptyState
                  title="No disabled users right now"
                  description="Users you disable show up here. Disabling preserves their audit trail but blocks sign-in."
                />
              ) : (
                <div className="card" style={{ overflow: 'hidden' }}>
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Last seen</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {disabled.map((u) => (
                        <tr key={u.id}>
                          <td>
                            <strong>{u.name}</strong>
                            <div className="brand-meta">{u.email}</div>
                          </td>
                          <td>{ROLE_LABEL[u.role]}</td>
                          <td className="muted text-sm">{lastSeenLabel(u.lastLoginAt)}</td>
                          <td className="text-right">
                            <Button
                              size="sm"
                              variant="ghost"
                              style={{ color: 'var(--danger)' }}
                              disabled={deleteUser.isPending}
                              onClick={() => {
                                if (
                                  window.confirm(
                                    `Permanently remove ${u.name} (${u.email})? This deletes the account and their brand access for good — it can't be undone. Their audit-log history is kept.`
                                  )
                                ) {
                                  deleteUser.mutate(u.id);
                                }
                              }}
                            >
                              Remove permanently
                            </Button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ),
          },
        ]}
      />

      {assignUser && (
        <BrandAccessDrawer
          mode="user"
          open
          onClose={() => setAssignUser(null)}
          user={assignUser}
        />
      )}
    </AppLayout>
  );
}
