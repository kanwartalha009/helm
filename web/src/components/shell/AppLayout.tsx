import type { ReactNode } from 'react';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { SearchPalette } from './SearchPalette';
import { AddBrandDrawer } from '@/components/brands/AddBrandDrawer';
import { InviteUserDrawer } from '@/components/team/InviteUserDrawer';
import { NewTicketDrawer } from '@/components/tickets/NewTicketDrawer';
import { useUiStore } from '@/stores/uiStore';

interface AppLayoutProps {
  title: ReactNode;
  tag?: ReactNode;
  topbarActions?: ReactNode;
  children: ReactNode;
}

export function AppLayout({ title, tag, topbarActions, children }: AppLayoutProps) {
  const addBrandOpen = useUiStore((s) => s.addBrandDrawerOpen);
  const setAddBrandOpen = useUiStore((s) => s.setAddBrandDrawerOpen);
  const inviteOpen = useUiStore((s) => s.inviteUserDrawerOpen);
  const setInviteOpen = useUiStore((s) => s.setInviteUserDrawerOpen);
  const newTicketOpen = useUiStore((s) => s.newTicketDrawerOpen);
  const setNewTicketOpen = useUiStore((s) => s.setNewTicketDrawerOpen);

  return (
    <>
      <div className="app-shell">
        <Sidebar />
        <div className="app-main">
          <Topbar title={title} tag={tag} actions={topbarActions} />
          <div className="app-content">{children}</div>
        </div>
      </div>
      <SearchPalette />
      <AddBrandDrawer open={addBrandOpen} onClose={() => setAddBrandOpen(false)} />
      <InviteUserDrawer open={inviteOpen} onClose={() => setInviteOpen(false)} />
      <NewTicketDrawer open={newTicketOpen} onClose={() => setNewTicketOpen(false)} />
    </>
  );
}
