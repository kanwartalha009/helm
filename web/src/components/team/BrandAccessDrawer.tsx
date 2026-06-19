import { useEffect, useState } from 'react';
import { Button, Drawer } from '@/components/ui';
import {
  useAssignBrandUsers,
  useBrandAccessUsers,
  useBrandsLive,
  useUsers,
} from '@/hooks/useApiData';
import { useUpdateUser } from '@/hooks/useInvitations';
import type { Brand, User } from '@/types/domain';

/**
 * Right-hand sidebar for managing brand_user_access from either side:
 *  - mode 'user'  → pick which brands a user can access (saves brand_ids on the user)
 *  - mode 'brand' → pick which users can access a brand (saves user_ids on the brand)
 *
 * Both write the same pivot, so the two surfaces stay in sync.
 */
export function BrandAccessDrawer(
  props:
    | { mode: 'user'; open: boolean; onClose: () => void; user: User }
    | { mode: 'brand'; open: boolean; onClose: () => void; brand: Brand },
) {
  return props.mode === 'user' ? (
    <UserBrandsDrawer open={props.open} onClose={props.onClose} user={props.user} />
  ) : (
    <BrandUsersDrawer open={props.open} onClose={props.onClose} brand={props.brand} />
  );
}

/* ---- assign brands to a user --------------------------------------- */

function UserBrandsDrawer({
  open,
  onClose,
  user,
}: {
  open: boolean;
  onClose: () => void;
  user: User;
}) {
  const { data: allBrands = [] } = useBrandsLive();
  const updateUser = useUpdateUser();
  const [brandIds, setBrandIds] = useState<number[]>(user.accessibleBrandIds ?? []);

  // Reseed from the user each time the drawer opens.
  useEffect(() => {
    if (open) setBrandIds(user.accessibleBrandIds ?? []);
  }, [open, user.id, (user.accessibleBrandIds ?? []).join(',')]);

  const seesAllByRole = user.role === 'master_admin' || user.role === 'manager';
  const allSelected = allBrands.length > 0 && brandIds.length === allBrands.length;
  const toggle = (id: number) =>
    setBrandIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

  const save = () =>
    updateUser.mutate({ id: user.id, patch: { brand_ids: brandIds } }, { onSuccess: onClose });

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={`Brand access · ${user.name}`}
      footer={
        <div className="flex items-center justify-between" style={{ width: '100%' }}>
          <span className="muted text-sm">
            {brandIds.length} of {allBrands.length} selected
          </span>
          <div className="flex items-center gap-8">
            <Button size="sm" variant="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button size="sm" variant="primary" disabled={updateUser.isPending} onClick={save}>
              {updateUser.isPending ? 'Saving…' : 'Save'}
            </Button>
          </div>
        </div>
      }
    >
      <p className="muted text-sm" style={{ marginBottom: 12 }}>
        {seesAllByRole
          ? 'This role sees every brand. Assignments set this user’s default “My brands” dashboard view and the brand-manager filter.'
          : 'This user can only see the brands assigned here.'}
      </p>

      <div className="flex items-center gap-8" style={{ marginBottom: 12 }}>
        <Button
          size="sm"
          variant="secondary"
          disabled={allSelected || allBrands.length === 0}
          onClick={() => setBrandIds(allBrands.map((b) => b.id))}
        >
          Assign all
        </Button>
        <Button size="sm" variant="ghost" disabled={brandIds.length === 0} onClick={() => setBrandIds([])}>
          Clear
        </Button>
      </div>

      {allBrands.length === 0 ? (
        <div className="muted text-sm">No brands yet.</div>
      ) : (
        allBrands.map((b) => (
          <label
            key={b.id}
            className="list-row"
            style={{ cursor: 'pointer', gap: 12, alignItems: 'center' }}
          >
            <input
              type="checkbox"
              checked={brandIds.includes(b.id)}
              onChange={() => toggle(b.id)}
            />
            <div className="list-row-main">
              <div className="list-row-title">{b.name}</div>
              <div className="list-row-sub">
                {b.region ?? b.groupTag ?? '—'} · {b.baseCurrency}
              </div>
            </div>
          </label>
        ))
      )}
    </Drawer>
  );
}

/* ---- assign users to a brand --------------------------------------- */

function BrandUsersDrawer({
  open,
  onClose,
  brand,
}: {
  open: boolean;
  onClose: () => void;
  brand: Brand;
}) {
  // Both queries stay disabled until the drawer is opened.
  const { data: allUsers = [] } = useUsers(open);
  const { data: access } = useBrandAccessUsers(open ? brand.slug : undefined);
  const assign = useAssignBrandUsers();
  const [selected, setSelected] = useState<number[]>([]);

  useEffect(() => {
    if (open) setSelected(access?.userIds ?? []);
  }, [open, brand.slug, (access?.userIds ?? []).join(',')]);

  const activeUsers = allUsers.filter((u) => u.status === 'active');
  const toggle = (id: number) =>
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

  const save = () =>
    assign.mutate({ slug: brand.slug, userIds: selected }, { onSuccess: onClose });

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={`Team · ${brand.name}`}
      footer={
        <div className="flex items-center justify-between" style={{ width: '100%' }}>
          <span className="muted text-sm">{selected.length} assigned</span>
          <div className="flex items-center gap-8">
            <Button size="sm" variant="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button size="sm" variant="primary" disabled={assign.isPending} onClick={save}>
              {assign.isPending ? 'Saving…' : 'Save'}
            </Button>
          </div>
        </div>
      }
    >
      <p className="muted text-sm" style={{ marginBottom: 12 }}>
        Users assigned here can access <strong>{brand.name}</strong>. Team members and brand users
        see only their assigned brands; managers and admins see every brand, but assignments set
        their default dashboard view.
      </p>

      {activeUsers.length === 0 ? (
        <div className="muted text-sm">No active users yet. Invite teammates from the Team page.</div>
      ) : (
        activeUsers.map((u) => (
          <label
            key={u.id}
            className="list-row"
            style={{ cursor: 'pointer', gap: 12, alignItems: 'center' }}
          >
            <input
              type="checkbox"
              checked={selected.includes(u.id)}
              onChange={() => toggle(u.id)}
            />
            <div className="list-row-main">
              <div className="list-row-title">{u.name}</div>
              <div className="list-row-sub">
                {u.email} · {u.role.replace('_', ' ')}
              </div>
            </div>
          </label>
        ))
      )}
    </Drawer>
  );
}
