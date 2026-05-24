import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useUiStore } from '@/stores/uiStore';
import { Avatar } from '@/components/ui';

type PaletteAction = 'addBrand' | 'inviteUser' | 'newTicket';

interface PaletteItem {
  label: string;
  to?: string;              // either navigate
  action?: PaletteAction;   // …or dispatch a drawer-opener
  section: string;
  meta?: string;
  initials?: string;
}

const ITEMS: PaletteItem[] = [
  { section: 'Pages', label: 'Dashboard', to: '/dashboard', meta: 'G then D' },
  { section: 'Pages', label: 'Sync health', to: '/sync-health', meta: 'G then S' },
  { section: 'Pages', label: 'Brands', to: '/brands', meta: 'G then B' },
  { section: 'Pages', label: 'Tickets', to: '/tickets', meta: 'G then T' },
  { section: 'Pages', label: 'Team', to: '/team' },
  { section: 'Pages', label: 'Audit log', to: '/audit-log' },
  { section: 'Pages', label: 'Settings', to: '/settings' },
  { section: 'Actions', label: 'Add new brand',     action: 'addBrand',   meta: 'Opens drawer' },
  { section: 'Actions', label: 'Invite a teammate', action: 'inviteUser', meta: 'Opens drawer' },
  { section: 'Actions', label: 'Raise a ticket',    action: 'newTicket',  meta: 'Opens drawer' },
];

export function SearchPalette() {
  const open = useUiStore((s) => s.paletteOpen);
  const setOpen = useUiStore((s) => s.setPaletteOpen);
  const setAddBrandOpen = useUiStore((s) => s.setAddBrandDrawerOpen);
  const setInviteOpen = useUiStore((s) => s.setInviteUserDrawerOpen);
  const setNewTicketOpen = useUiStore((s) => s.setNewTicketDrawerOpen);
  const [query, setQuery] = useState('');
  const navigate = useNavigate();
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setOpen(!open);
      }
      if (e.key === 'Escape') setOpen(false);
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [open, setOpen]);

  useEffect(() => {
    if (open) setTimeout(() => inputRef.current?.focus(), 30);
    else setQuery('');
  }, [open]);

  const grouped = useMemo(() => {
    const q = query.toLowerCase();
    const filtered = q
      ? ITEMS.filter((i) => i.label.toLowerCase().includes(q) || i.meta?.toLowerCase().includes(q))
      : ITEMS;
    const map = new Map<string, PaletteItem[]>();
    filtered.forEach((i) => {
      const arr = map.get(i.section) ?? [];
      arr.push(i);
      map.set(i.section, arr);
    });
    return Array.from(map.entries());
  }, [query]);

  if (!open) return null;

  return (
    <div className="modal-backdrop open" onClick={(e) => e.target === e.currentTarget && setOpen(false)}>
      <div className="modal" style={{ maxWidth: 560 }}>
        <input
          ref={inputRef}
          className="palette-input"
          type="text"
          placeholder="Search brands, pages, settings…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
        <div className="palette-list">
          {grouped.map(([section, items]) => (
            <div key={section}>
              <div className="palette-section">{section}</div>
              {items.map((item) => (
                <a
                  key={(item.to ?? item.action ?? '') + item.label}
                  href={item.to ?? '#'}
                  className="palette-item"
                  onClick={(e) => {
                    e.preventDefault();
                    setOpen(false);
                    if (item.action === 'addBrand')   setAddBrandOpen(true);
                    else if (item.action === 'inviteUser') setInviteOpen(true);
                    else if (item.action === 'newTicket')  setNewTicketOpen(true);
                    else if (item.to) navigate(item.to);
                  }}
                >
                  {item.initials ? (
                    <Avatar initials={item.initials} size={18} />
                  ) : (
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
                      <circle cx="12" cy="12" r="9" />
                    </svg>
                  )}
                  {item.label}
                  {item.meta && <span className="meta">{item.meta}</span>}
                </a>
              ))}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
