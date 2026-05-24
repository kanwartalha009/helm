/* Helm — shared app shell & interactions.
   Injects sidebar + topbar + modals into any page with [data-shell].
   Static design preview — no real auth, no real data. */

(function () {
  const SIDEBAR_HTML = `
    <a href="dashboard.html" class="wordmark" style="padding: 0 8px; margin-bottom: 8px;">
      <span class="wordmark-dot"></span>
      Helm
    </a>

    <div>
      <div class="sidebar-section-label">Operate</div>
      <a href="dashboard.html" class="nav-item" data-nav="dashboard">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Dashboard
      </a>
      <a href="sync-health.html" class="nav-item" data-nav="sync-health">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 12h4l3-9 4 18 3-9h4"/></svg>
        Sync health
      </a>
      <a href="brands.html" class="nav-item" data-nav="brands">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
        Brands
      </a>
      <a href="tickets.html" class="nav-item" data-nav="tickets">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        Tickets
      </a>
    </div>

    <div>
      <div class="sidebar-section-label">Manage</div>
      <a href="team.html" class="nav-item" data-nav="team">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="9" cy="8" r="4"/><path d="M3 21a6 6 0 0 1 12 0"/><circle cx="17" cy="8" r="3"/><path d="M15 21a4 4 0 0 1 6 0"/></svg>
        Team
      </a>
      <a href="audit-log.html" class="nav-item" data-nav="audit-log">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/><line x1="9" y1="11" x2="15" y2="11"/></svg>
        Audit log
      </a>
      <a href="settings.html" class="nav-item" data-nav="settings">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.16.34.25.7.25 1.08V12c0 .38-.09.74-.25 1.08z"/></svg>
        Settings
      </a>
    </div>

    <div style="margin-top: auto; padding: 12px 8px; border-top: 1px solid var(--border); position: relative;">
      <button id="user-menu-trigger" style="background: none; border: 0; padding: 0; width: 100%; cursor: pointer; font-family: inherit;">
        <div class="flex items-center gap-8" style="width: 100%;">
          <span class="brand-avatar" style="background: var(--accent); color: var(--accent-fg); border-color: var(--accent);">K</span>
          <div style="line-height: 1.3; text-align: left; flex: 1;">
            <div style="font-size: 13px; font-weight: 500; color: var(--text);">Kanwar</div>
            <div style="font-size: 11px; color: var(--text-muted);">Master admin</div>
          </div>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" style="color: var(--text-muted);"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </button>
      <div id="user-menu" class="dropdown-menu up" style="left: 8px; right: 8px; bottom: calc(100% - 0px); min-width: auto;">
        <a href="profile.html" class="dropdown-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
        <a href="settings.html" class="dropdown-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6"/></svg>
          Settings
        </a>
        <a href="settings.html#mfa" class="dropdown-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Two-factor auth
        </a>
        <div class="dropdown-divider"></div>
        <a href="login.html" class="dropdown-item danger">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign out
        </a>
      </div>
    </div>
  `;

  const TOPBAR_HTML = `
    <div class="flex items-center gap-12">
      <h3 id="topbar-title" style="font-size: 15px; font-weight: 500;">Page title</h3>
      <span id="topbar-tag"></span>
    </div>
    <div class="flex items-center gap-8">
      <button class="btn btn-secondary btn-sm" id="search-trigger" style="gap: 8px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 21l-4.35-4.35"/><circle cx="11" cy="11" r="7"/></svg>
        Search
        <span class="kbd">⌘K</span>
      </button>
      <a href="add-brand.html" class="btn btn-primary btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
        Add brand
      </a>
    </div>
  `;

  const PALETTE_HTML = `
    <div class="modal-backdrop" id="palette-backdrop" data-modal="palette">
      <div class="modal" style="max-width: 560px;">
        <input class="palette-input" id="palette-input" type="text" placeholder="Search brands, pages, settings…" autocomplete="off" />
        <div class="palette-list" id="palette-list">
          <div class="palette-section">Pages</div>
          <a href="dashboard.html" class="palette-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            Dashboard
            <span class="meta">G then D</span>
          </a>
          <a href="sync-health.html" class="palette-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 12h4l3-9 4 18 3-9h4"/></svg>
            Sync health
            <span class="meta">G then S</span>
          </a>
          <a href="brands.html" class="palette-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="9"/></svg>
            Brands
            <span class="meta">G then B</span>
          </a>
          <a href="tickets.html" class="palette-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7"/></svg>
            Tickets
            <span class="meta">G then T</span>
          </a>
          <a href="team.html" class="palette-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="9" cy="8" r="4"/></svg>
            Team
          </a>
          <a href="audit-log.html" class="palette-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
            Audit log
          </a>
          <a href="settings.html" class="palette-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="3"/></svg>
            Settings
          </a>

          <div class="palette-section">Brands</div>
          <a href="brand-detail.html" class="palette-item">
            <span class="brand-avatar" style="width: 18px; height: 18px; font-size: 9px;">ML</span>
            Meller
            <span class="meta">Spain &middot; EUR</span>
          </a>
          <a href="brand-detail.html" class="palette-item">
            <span class="brand-avatar" style="width: 18px; height: 18px; font-size: 9px;">AY</span>
            Ayla &amp; Co
            <span class="meta">UAE &middot; AED</span>
          </a>
          <a href="brand-detail.html" class="palette-item">
            <span class="brand-avatar" style="width: 18px; height: 18px; font-size: 9px;">NT</span>
            Nova Threads
            <span class="meta">US &middot; USD</span>
          </a>
          <a href="brand-detail.html" class="palette-item">
            <span class="brand-avatar" style="width: 18px; height: 18px; font-size: 9px;">KE</span>
            Kenza Beauty
            <span class="meta">Saudi Arabia &middot; SAR</span>
          </a>

          <div class="palette-section">Actions</div>
          <a href="add-brand.html" class="palette-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 5v14M5 12h14"/></svg>
            Add new brand
          </a>
          <a href="invite-user.html" class="palette-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            Invite a teammate
          </a>
        </div>
      </div>
    </div>
  `;

  // Build app shell into [data-shell]
  function buildShell() {
    const root = document.querySelector('[data-shell]');
    if (!root) return;

    const activeNav = root.getAttribute('data-active') || '';
    const title = root.getAttribute('data-title') || 'Helm';
    const tag = root.getAttribute('data-tag') || '';
    const content = root.innerHTML;

    root.innerHTML = `
      <div class="app-shell">
        <aside class="sidebar">${SIDEBAR_HTML}</aside>
        <div class="app-main">
          <header class="app-topbar">${TOPBAR_HTML}</header>
          <div class="app-content">${content}</div>
        </div>
      </div>
      ${PALETTE_HTML}
    `;

    // Set active nav
    if (activeNav) {
      const item = root.querySelector(`[data-nav="${activeNav}"]`);
      if (item) item.classList.add('active');
    }

    // Set title and tag
    const titleEl = root.querySelector('#topbar-title');
    if (titleEl) titleEl.textContent = title;
    if (tag) {
      const tagEl = root.querySelector('#topbar-tag');
      if (tagEl) tagEl.innerHTML = `<span class="tag">${tag}</span>`;
    }

    bindInteractions();
  }

  function bindInteractions() {
    // User menu dropdown
    const trigger = document.getElementById('user-menu-trigger');
    const menu = document.getElementById('user-menu');
    if (trigger && menu) {
      trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = menu.style.display === 'block';
        menu.style.display = open ? 'none' : 'block';
      });
      document.addEventListener('click', (e) => {
        if (!menu.contains(e.target) && !trigger.contains(e.target)) {
          menu.style.display = 'none';
        }
      });
    }

    // Search palette
    const searchTrigger = document.getElementById('search-trigger');
    const palette = document.getElementById('palette-backdrop');
    const paletteInput = document.getElementById('palette-input');
    const paletteList = document.getElementById('palette-list');

    function openPalette() {
      if (!palette) return;
      palette.classList.add('open');
      setTimeout(() => paletteInput && paletteInput.focus(), 30);
    }
    function closePalette() {
      if (!palette) return;
      palette.classList.remove('open');
      if (paletteInput) paletteInput.value = '';
      filterPalette('');
    }

    if (searchTrigger) searchTrigger.addEventListener('click', openPalette);

    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        if (palette && palette.classList.contains('open')) closePalette();
        else openPalette();
      }
      if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach((m) => m.classList.remove('open'));
      }
    });

    if (palette) {
      palette.addEventListener('click', (e) => {
        if (e.target === palette) closePalette();
      });
    }

    if (paletteInput) {
      paletteInput.addEventListener('input', (e) => filterPalette(e.target.value.toLowerCase()));
    }

    function filterPalette(q) {
      if (!paletteList) return;
      const items = paletteList.querySelectorAll('.palette-item');
      const sections = paletteList.querySelectorAll('.palette-section');
      items.forEach((item) => {
        const txt = item.textContent.toLowerCase();
        item.style.display = !q || txt.includes(q) ? 'flex' : 'none';
      });
      // Hide sections with no visible items
      sections.forEach((section) => {
        let next = section.nextElementSibling;
        let anyVisible = false;
        while (next && !next.classList.contains('palette-section')) {
          if (next.style.display !== 'none') anyVisible = true;
          next = next.nextElementSibling;
        }
        section.style.display = anyVisible || !q ? 'block' : 'none';
      });
    }
  }

  // Generic modal open/close — any element with data-open="modal-id" or data-close
  function bindModals() {
    document.addEventListener('click', (e) => {
      const opener = e.target.closest('[data-open]');
      if (opener) {
        const id = opener.getAttribute('data-open');
        const modal = document.getElementById(id);
        if (modal) modal.classList.add('open');
      }
      const closer = e.target.closest('[data-close]');
      if (closer) {
        const modal = closer.closest('.modal-backdrop');
        if (modal) modal.classList.remove('open');
      }
      // Backdrop click closes
      if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
        e.target.classList.remove('open');
      }
    });
  }

  // Confirm dialog — usage: <button data-confirm="text" data-confirm-action="...">
  function bindConfirms() {
    document.addEventListener('click', (e) => {
      const trigger = e.target.closest('[data-confirm]');
      if (!trigger) return;
      e.preventDefault();
      const text = trigger.getAttribute('data-confirm') || 'Are you sure?';
      const href = trigger.getAttribute('href') || trigger.getAttribute('data-confirm-href');
      if (window.confirm(text)) {
        if (href) window.location.href = href;
      }
    });
  }

  // Popovers — any [data-popover] toggles its sibling .popover-panel
  function bindPopovers() {
    document.addEventListener('click', (e) => {
      const trigger = e.target.closest('[data-popover]');
      if (trigger) {
        e.stopPropagation();
        const wrap = trigger.closest('.popover');
        if (!wrap) return;
        const wasOpen = wrap.classList.contains('open');
        // Close any other open popovers
        document.querySelectorAll('.popover.open').forEach((p) => p.classList.remove('open'));
        if (!wasOpen) wrap.classList.add('open');
        return;
      }
      // Click inside an open popover-panel — let it through
      if (e.target.closest('.popover-panel')) return;
      // Otherwise close all
      document.querySelectorAll('.popover.open').forEach((p) => p.classList.remove('open'));
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        document.querySelectorAll('.popover.open').forEach((p) => p.classList.remove('open'));
      }
    });
  }

  // Single-select chips inside a [data-chip-group]
  function bindChipGroups() {
    document.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-chip]');
      if (!chip) return;
      const group = chip.closest('[data-chip-group]');
      if (!group) return;
      group.querySelectorAll('[data-chip]').forEach((c) => c.classList.toggle('active', c === chip));
    });
  }

  // Tabs
  function bindTabs() {
    document.addEventListener('click', (e) => {
      const tab = e.target.closest('[data-tab]');
      if (!tab) return;
      const group = tab.closest('[data-tab-group]');
      if (!group) return;
      e.preventDefault();
      const id = tab.getAttribute('data-tab');
      group.querySelectorAll('[data-tab]').forEach((t) => t.classList.toggle('active', t === tab));
      const container = group.parentElement;
      container.querySelectorAll('[data-tab-content]').forEach((c) => {
        c.style.display = c.getAttribute('data-tab-content') === id ? '' : 'none';
      });
    });
  }

  // Run on DOMContentLoaded
  document.addEventListener('DOMContentLoaded', () => {
    buildShell();
    bindModals();
    bindConfirms();
    bindTabs();
    bindPopovers();
    bindChipGroups();
  });
})();
