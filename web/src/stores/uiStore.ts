import { create } from 'zustand';

export interface InvitationAcceptUrlState {
  email: string;
  acceptUrl: string;
}

/**
 * The collapsed sidebar is REMEMBERED across reloads.
 *
 * Someone widening the viewport to read a 3,892-row inventory table is not making a one-off
 * decision about this page — they are telling us how they want to work. Resetting it on every
 * navigation would mean re-collapsing it all day, which is worse than not offering it at all.
 *
 * localStorage, not a cookie or the API: it is a per-device display preference, it must apply on the
 * FIRST paint (before any request resolves), and it is not worth a round-trip or a DB column.
 */
const SIDEBAR_KEY = 'helm.sidebarCollapsed';

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(SIDEBAR_KEY) === '1';
  } catch {
    // Private mode / storage disabled. Expanded is the safe default — a user who cannot persist a
    // preference should still get the readable navigation, not a mystery strip of icons.
    return false;
  }
}

function writeCollapsed(v: boolean): void {
  try {
    localStorage.setItem(SIDEBAR_KEY, v ? '1' : '0');
  } catch {
    /* preference simply won't persist; the session still works */
  }
}

interface UiState {
  paletteOpen: boolean;
  userMenuOpen: boolean;
  addBrandDrawerOpen: boolean;
  inviteUserDrawerOpen: boolean;
  newTicketDrawerOpen: boolean;
  sidebarCollapsed: boolean;
  // Modal that shows the freshly minted invite URL after a successful invite,
  // since SMTP isn't wired and the admin needs to paste the link manually.
  // Stays up until the admin dismisses it — won't disappear like a toast.
  invitationAcceptUrl: InvitationAcceptUrlState | null;
  setPaletteOpen: (v: boolean) => void;
  setUserMenuOpen: (v: boolean) => void;
  setAddBrandDrawerOpen: (v: boolean) => void;
  setInviteUserDrawerOpen: (v: boolean) => void;
  setNewTicketDrawerOpen: (v: boolean) => void;
  setInvitationAcceptUrl: (v: InvitationAcceptUrlState | null) => void;
  toggleSidebar: () => void;
  setSidebarCollapsed: (v: boolean) => void;
}

export const useUiStore = create<UiState>((set) => ({
  paletteOpen: false,
  userMenuOpen: false,
  addBrandDrawerOpen: false,
  inviteUserDrawerOpen: false,
  newTicketDrawerOpen: false,
  invitationAcceptUrl: null,
  // Read on store creation, so the very first paint is already in the right state — no flash of
  // an expanded sidebar collapsing a frame later.
  sidebarCollapsed: readCollapsed(),
  setPaletteOpen: (paletteOpen) => set({ paletteOpen }),
  setUserMenuOpen: (userMenuOpen) => set({ userMenuOpen }),
  setAddBrandDrawerOpen: (addBrandDrawerOpen) => set({ addBrandDrawerOpen }),
  setInviteUserDrawerOpen: (inviteUserDrawerOpen) => set({ inviteUserDrawerOpen }),
  setNewTicketDrawerOpen: (newTicketDrawerOpen) => set({ newTicketDrawerOpen }),
  setInvitationAcceptUrl: (invitationAcceptUrl) => set({ invitationAcceptUrl }),
  toggleSidebar: () => set((s) => {
    writeCollapsed(!s.sidebarCollapsed);
    return { sidebarCollapsed: !s.sidebarCollapsed };
  }),
  setSidebarCollapsed: (sidebarCollapsed) => {
    writeCollapsed(sidebarCollapsed);
    set({ sidebarCollapsed });
  },
}));
