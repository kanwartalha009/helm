import { create } from 'zustand';

export interface InvitationAcceptUrlState {
  email: string;
  acceptUrl: string;
}

interface UiState {
  paletteOpen: boolean;
  userMenuOpen: boolean;
  addBrandDrawerOpen: boolean;
  inviteUserDrawerOpen: boolean;
  newTicketDrawerOpen: boolean;
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
}

export const useUiStore = create<UiState>((set) => ({
  paletteOpen: false,
  userMenuOpen: false,
  addBrandDrawerOpen: false,
  inviteUserDrawerOpen: false,
  newTicketDrawerOpen: false,
  invitationAcceptUrl: null,
  setPaletteOpen: (paletteOpen) => set({ paletteOpen }),
  setUserMenuOpen: (userMenuOpen) => set({ userMenuOpen }),
  setAddBrandDrawerOpen: (addBrandDrawerOpen) => set({ addBrandDrawerOpen }),
  setInviteUserDrawerOpen: (inviteUserDrawerOpen) => set({ inviteUserDrawerOpen }),
  setNewTicketDrawerOpen: (newTicketDrawerOpen) => set({ newTicketDrawerOpen }),
  setInvitationAcceptUrl: (invitationAcceptUrl) => set({ invitationAcceptUrl }),
}));
