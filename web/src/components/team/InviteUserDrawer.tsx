import { useEffect, useState } from 'react';
import { Banner, Button, Card, Drawer, Input } from '@/components/ui';
import { useBrandsLive } from '@/hooks/useApiData';
import { useInviteUser } from '@/hooks/useInvitations';

interface InviteUserDrawerProps {
  open: boolean;
  onClose: () => void;
}

type Role = 'manager' | 'team_member' | 'brand_user';

/**
 * Side-drawer Invite-user form. Matches the AddBrandDrawer pattern.
 * Single step — email, role, optional brand scope, optional note → POST.
 */
export function InviteUserDrawer({ open, onClose }: InviteUserDrawerProps) {
  const inviteMutation = useInviteUser();
  const { data: brands = [] } = useBrandsLive();

  const [email, setEmail] = useState('');
  const [role, setRole] = useState<Role | ''>('');
  const [brandIds, setBrandIds] = useState<number[]>([]);
  const [note, setNote] = useState('');

  // Reset on close so re-opening starts fresh.
  useEffect(() => {
    if (!open) {
      setEmail('');
      setRole('');
      setBrandIds([]);
      setNote('');
    }
  }, [open]);

  const needsBrands = role === 'team_member' || role === 'brand_user';
  const valid = email.trim().length > 0 && role !== '' && (!needsBrands || brandIds.length > 0);

  const submit = async () => {
    if (!valid) return;
    try {
      await inviteMutation.mutateAsync({
        email: email.trim(),
        role: role as Role,
        brand_ids: needsBrands ? brandIds : undefined,
        note: note.trim() || undefined,
      });
      onClose();
    } catch {
      // toast shown by hook
    }
  };

  const toggleBrand = (id: number) => {
    if (role === 'brand_user') {
      // brand_user can only access exactly one brand
      setBrandIds([id]);
    } else {
      setBrandIds((prev) => (prev.includes(id) ? prev.filter((b) => b !== id) : [...prev, id]));
    }
  };

  return (
    <Drawer
      open={open}
      onClose={onClose}
      size="lg"
      title="Invite a teammate"
      footer={
        <>
          <span className="text-xs muted">Sent to the email below with a 7-day accept link.</span>
          <div className="flex items-center gap-8">
            <Button size="sm" variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button
              size="sm"
              variant="primary"
              onClick={submit}
              disabled={!valid || inviteMutation.isPending}
            >
              {inviteMutation.isPending ? 'Sending…' : 'Send invitation'}
            </Button>
          </div>
        </>
      }
    >
      <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
        <div className="form-grid">
          <Input
            label="Email"
            type="email"
            placeholder="name@company.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            autoFocus
            required
          />

          <div className="field">
            <label className="field-label">Role</label>
            <select
              className="input"
              value={role}
              onChange={(e) => {
                const next = e.target.value as Role | '';
                setRole(next);
                // Reset brand selection when leaving brand-scoped roles
                if (next === '' || next === 'manager') setBrandIds([]);
                if (next === 'brand_user' && brandIds.length > 1) setBrandIds(brandIds.slice(0, 1));
              }}
              required
            >
              <option value="">Pick a role…</option>
              <option value="manager">Manager — sees all brands, manages non-admin users</option>
              <option value="team_member">Team member — sees assigned brands only</option>
              <option value="brand_user">Brand user — sees one brand only, can raise tickets</option>
            </select>
            <span className="field-hint">You cannot invite another master admin.</span>
          </div>

          {needsBrands && (
            <div className="field">
              <label className="field-label">
                {role === 'brand_user' ? 'Brand (pick exactly one)' : 'Accessible brands'}
              </label>
              {brands.length === 0 ? (
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
                  You haven&rsquo;t added any brands yet. Add a brand first so there&rsquo;s something to grant access to.
                </Banner>
              ) : (
                <Card style={{ padding: 12, maxHeight: 240, overflowY: 'auto' }}>
                  {brands.map((b) => (
                    <label
                      key={b.id}
                      className="flex items-center gap-8"
                      style={{ cursor: 'pointer', padding: '6px 0' }}
                    >
                      <input
                        type={role === 'brand_user' ? 'radio' : 'checkbox'}
                        name="brands"
                        checked={brandIds.includes(b.id)}
                        onChange={() => toggleBrand(b.id)}
                      />
                      <span style={{ fontWeight: 500 }}>{b.name}</span>
                      <span className="muted text-sm">· {b.region} · {b.baseCurrency}</span>
                    </label>
                  ))}
                </Card>
              )}
              <span className="field-hint">
                {role === 'brand_user'
                  ? 'Brand users see exactly one brand.'
                  : 'Team members can be scoped to any number of brands.'}
              </span>
            </div>
          )}

          <div className="field">
            <label className="field-label">
              Personal note <span className="muted" style={{ fontWeight: 400 }}>(optional)</span>
            </label>
            <textarea
              className="input"
              rows={3}
              placeholder="Welcome to Helm…"
              style={{ resize: 'vertical', fontFamily: 'inherit' }}
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
            <span className="field-hint">Included in the invitation email.</span>
          </div>
        </div>
        <button type="submit" style={{ display: 'none' }} />
      </form>
    </Drawer>
  );
}
