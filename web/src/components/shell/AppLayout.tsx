import { useEffect, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
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
  const collapsed = useUiStore((s) => s.sidebarCollapsed);
  const toggleSidebar = useUiStore((s) => s.toggleSidebar);

  /**
   * `[` toggles the sidebar — the same key Linear and VS Code use, because this is a thing you do
   * repeatedly while reading a wide table and reaching for the mouse each time defeats the point.
   *
   * Ignored while typing: a brand search box is one keystroke away from every table on this app, and
   * a filter that silently collapsed the navigation every time someone typed "[" would be a bug.
   */
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== '[' || e.metaKey || e.ctrlKey || e.altKey) return;

      const el = document.activeElement as HTMLElement | null;
      const tag = el?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el?.isContentEditable) return;

      e.preventDefault();
      toggleSidebar();
    };

    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [toggleSidebar]);

  return (
    <>
      <div className={cn('app-shell', collapsed && 'sidebar-collapsed')}>
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
