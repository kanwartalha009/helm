import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import {
  Banner,
  Breadcrumb,
  Button,
  Card,
  EmptyState,
  Input,
  PageHeader,
  Tag,
} from '@/components/ui';
import { useUser } from '@/hooks/useApiData';
import { useDisableUser, useUpdateUser } from '@/hooks/useInvitations';
import { useCurrentUser } from '@/hooks/useSettings';
import type { User } from '@/types/domain';

const ROLE_LABEL: Record<User['role'], string> = {
  master_admin: 'Master admin',
  manager: 'Manager',
  team_member: 'Team member',
  brand_user: 'Brand user',
};

const ASSIGNABLE_ROLES: Array<{ value: User['role']; label: string; description: string }> = [
  { value: 'manager',     label: 'Manager',     description: 'Sees every brand, can manage non-admin users.' },
  { value: 'team_member', label: 'Team member', description: 'Sees only brands explicitly assigned to them.' },
  { value: 'brand_user',  label: 'Brand user',  description: 'Sees exactly one brand. Can raise tickets, not edit settings.' },
];

export function UserDetailPage() {
  // The router param is `:slug` historically but for users we always pass the
  // numeric id, since users don't have a slug column.
  const { slug } = useParams();
  const navigate = useNavigate();
  const { data: currentUser } = useCurrentUser();
  const { data: user, isLoading, isError, error } = useUser(slug);
  const updateUser = useUpdateUser();
  const disableUser = useDisableUser();

  const [name, setName] = useState('');
  const [role, setRole] = useState<User['role']>('manager');

  // Seed local form state from the loaded user; reseed on refetch.
  useEffect(() => {
    if (!user) return;
    setName(user.name);
    setRole(user.role);
  }, [user?.id, user?.name, user?.role]);

  if (isLoading) {
    return (
      <AppLayout title="User">
        <div className="muted" style={{ padding: 24 }}>Loading user…</div>
      </AppLayout>
    );
  }

  if (isError || !user) {
    const msg = (error as any)?.response?.data?.message ?? (error as Error)?.message ?? 'User not found';
    return (
      <AppLayout title="User not found">
        <Breadcrumb crumbs={[{ label: 'Team', to: '/team' }, { label: 'Not found' }]} />
        <EmptyState
          title="We couldn’t load this user"
          description={msg}
          action={<Button variant="primary" onClick={() => navigate('/team')}>Back to team</Button>}
        />
      </AppLayout>
    );
  }

  const isSelf = currentUser?.id === user.id;
  const isMasterAdmin = user.role === 'master_admin';
  const dirty = name !== user.name || role !== user.role;
  const canEditRole = !isMasterAdmin && !isSelf;

  const onSave = () => {
    if (!dirty) return;
    const patch: Record<string, unknown> = {};
    if (name !== user.name) patch.name = name;
    if (role !== user.role && canEditRole) patch.role = role;
    if (Object.keys(patch).length === 0) return;
    updateUser.mutate({ id: user.id, patch });
  };

  const onDisable = () => {
    if (isMasterAdmin) return;
    if (isSelf) return;
    const msg = `Disable ${user.name}? They lose sign-in access immediately. Their audit trail is preserved.`;
    if (!window.confirm(msg)) return;
    disableUser.mutate(user.id, {
      onSuccess: () => navigate('/team'),
    });
  };

  return (
    <AppLayout title={user.name}>
      <Breadcrumb crumbs={[{ label: 'Team', to: '/team' }, { label: user.name }]} />

      <PageHeader
        leading={
          <span
            className="brand-avatar"
            style={{
              width: 44,
              height: 44,
              fontSize: 15,
              borderRadius: '50%',
              ...(isSelf
                ? { background: 'var(--accent)', color: 'var(--accent-fg)', borderColor: 'var(--accent)' }
                : {}),
            }}
          >
            {user.displayInitials || (user.name || '?').slice(0, 1)}
          </span>
        }
        title={
          <>
            {user.name}
            {isSelf && <Tag style={{ marginLeft: 8 }}>You</Tag>}
            {user.status === 'disabled' && <Tag variant="warning" style={{ marginLeft: 8 }}>Disabled</Tag>}
          </>
        }
        subtitle={`${user.email} · ${ROLE_LABEL[user.role]} · ${user.timezone ?? 'UTC'}`}
        actions={
          <>
            {!isMasterAdmin && !isSelf && user.status === 'active' && (
              <Button
                size="sm"
                variant="secondary"
                style={{ color: 'var(--danger)' }}
                onClick={onDisable}
                disabled={disableUser.isPending}
              >
                {disableUser.isPending ? 'Disabling…' : 'Disable user'}
              </Button>
            )}
            {isSelf && (
              <Button size="sm" variant="secondary" onClick={() => navigate('/profile')}>
                Edit your profile →
              </Button>
            )}
          </>
        }
      />

      <h3 className="section-title">Profile</h3>
      <Card style={{ padding: 24, maxWidth: 640 }}>
        <form
          className="form-grid"
          onSubmit={(e) => {
            e.preventDefault();
            onSave();
          }}
        >
          <Input
            label="Name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
          />
          <Input
            label="Email"
            value={user.email}
            disabled
            onChange={() => {}}
            hint="Email changes are handled via a fresh invite for security."
          />
          <div className="field">
            <label className="field-label">Role</label>
            {canEditRole ? (
              <select
                className="input"
                value={role}
                onChange={(e) => setRole(e.target.value as User['role'])}
              >
                {ASSIGNABLE_ROLES.map((r) => (
                  <option key={r.value} value={r.value}>
                    {r.label} — {r.description}
                  </option>
                ))}
              </select>
            ) : (
              <div className="muted text-sm" style={{ padding: '10px 0' }}>
                {ROLE_LABEL[user.role]}{' '}
                <span className="muted">
                  ({isMasterAdmin ? 'master admin role is locked' : 'you can’t change your own role'})
                </span>
              </div>
            )}
          </div>

          <div className="flex items-center gap-8 mt-16">
            <Button
              size="sm"
              variant="primary"
              type="submit"
              disabled={!dirty || updateUser.isPending}
            >
              {updateUser.isPending ? 'Saving…' : 'Save changes'}
            </Button>
            <Button
              size="sm"
              variant="ghost"
              type="button"
              disabled={!dirty || updateUser.isPending}
              onClick={() => {
                setName(user.name);
                setRole(user.role);
              }}
            >
              Cancel
            </Button>
          </div>
        </form>
      </Card>

      <h3 className="section-title mt-32">Security</h3>
      <Card style={{ overflow: 'hidden' }}>
        <div className="list-row">
          <div className="list-row-main">
            <div className="list-row-title">Two-factor auth</div>
            <div className="list-row-sub">
              {user.mfaEnabled ? 'Enabled via authenticator app.' : 'Not configured.'}
            </div>
          </div>
          {user.mfaEnabled ? <Tag variant="success">Enabled</Tag> : <Tag variant="warning">Off</Tag>}
        </div>
        <div className="list-row">
          <div className="list-row-main">
            <div className="list-row-title">Last sign-in</div>
            <div className="list-row-sub">
              {user.lastLoginAt
                ? new Date(user.lastLoginAt).toLocaleString()
                : 'Never signed in yet.'}
            </div>
          </div>
        </div>
        <div className="list-row">
          <div className="list-row-main">
            <div className="list-row-title">Onboarding</div>
            <div className="list-row-sub">
              {user.onboardingComplete
                ? `Completed ${user.onboardingCompletedAt ? new Date(user.onboardingCompletedAt).toLocaleDateString() : ''}.`
                : 'Pending — user hasn’t finished the welcome wizard yet.'}
            </div>
          </div>
          {user.onboardingComplete ? <Tag variant="success">Done</Tag> : <Tag variant="warning">Pending</Tag>}
        </div>
      </Card>

      <Banner variant="info" className="mt-24">
        Per-user audit trail and impersonation land alongside the rest of Phase 1.5 RBAC. The role + name edits above persist now.
      </Banner>
    </AppLayout>
  );
}
