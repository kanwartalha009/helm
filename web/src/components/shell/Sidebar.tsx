import { NavLink, useNavigate } from 'react-router-dom';
import { cn } from '@/lib/cn';
import { Avatar, Dropdown, DropdownItem, DropdownDivider, Wordmark } from '@/components/ui';
import { logout } from '@/lib/auth';
import { useCurrentUser } from '@/hooks/useSettings';

interface NavSection {
  label: string;
  items: { to: string; label: string; icon: JSX.Element }[];
}

const NAV: NavSection[] = [
  {
    label: 'Operate',
    items: [
      {
        to: '/dashboard',
        label: 'Dashboard',
        icon: (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
          </svg>
        ),
      },
      {
        to: '/sync-health',
        label: 'Sync health',
        icon: (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <path d="M3 12h4l3-9 4 18 3-9h4" />
          </svg>
        ),
      },
      {
        to: '/brands',
        label: 'Brands',
        icon: (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
          </svg>
        ),
      },
      {
        to: '/reports',
        label: 'Reports',
        icon: (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="8" y1="17" x2="8" y2="13" />
            <line x1="12" y1="17" x2="12" y2="11" />
            <line x1="16" y1="17" x2="16" y2="15" />
          </svg>
        ),
      },
      {
        to: '/tickets',
        label: 'Tickets',
        icon: (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
          </svg>
        ),
      },
    ],
  },
  {
    label: 'Manage',
    items: [
      {
        to: '/team',
        label: 'Team',
        icon: (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <circle cx="9" cy="8" r="4" />
            <path d="M3 21a6 6 0 0 1 12 0" />
            <circle cx="17" cy="8" r="3" />
            <path d="M15 21a4 4 0 0 1 6 0" />
          </svg>
        ),
      },
      {
        to: '/audit-log',
        label: 'Audit log',
        icon: (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="9" y1="15" x2="15" y2="15" />
            <line x1="9" y1="11" x2="15" y2="11" />
          </svg>
        ),
      },
      {
        to: '/settings',
        label: 'Settings',
        icon: (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.16.34.25.7.25 1.08V12c0 .38-.09.74-.25 1.08z" />
          </svg>
        ),
      },
    ],
  },
];

export function Sidebar() {
  const navigate = useNavigate();
  const { data: user } = useCurrentUser();
  const initials = user?.displayInitials || user?.name?.slice(0, 1)?.toUpperCase() || '?';
  return (
    <aside className="sidebar">
      <Wordmark to="/dashboard" style={{ padding: '0 8px', marginBottom: 8 }} />

      {NAV.map((section) => (
        <div key={section.label}>
          <div className="sidebar-section-label">{section.label}</div>
          {section.items.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) => cn('nav-item', isActive && 'active')}
            >
              {item.icon}
              {item.label}
            </NavLink>
          ))}
        </div>
      ))}

      <div style={{ marginTop: 'auto', padding: '12px 8px', borderTop: '1px solid var(--border)' }}>
        <Dropdown
          align="left"
          direction="up"
          trigger={
            <button
              style={{
                background: 'none',
                border: 0,
                padding: 0,
                width: '100%',
                cursor: 'pointer',
                fontFamily: 'inherit',
              }}
            >
              <div className="flex items-center gap-8" style={{ width: '100%' }}>
                {user?.avatarUrl ? (
                  <img
                    src={user.avatarUrl}
                    alt=""
                    style={{
                      width: 28,
                      height: 28,
                      borderRadius: '50%',
                      objectFit: 'cover',
                      border: '1px solid var(--border)',
                    }}
                  />
                ) : (
                  <Avatar initials={initials} inverted round />
                )}
                <div style={{ lineHeight: 1.3, textAlign: 'left', flex: 1 }}>
                  <div style={{ fontSize: 13, fontWeight: 500, color: 'var(--text)' }}>
                    {user?.name ?? 'Loading…'}
                  </div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                    {roleLabel(user?.role)}
                  </div>
                </div>
                <svg
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.75"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <polyline points="6 9 12 15 18 9" />
                </svg>
              </div>
            </button>
          }
        >
          <DropdownItem onClick={() => navigate('/profile')}>Profile</DropdownItem>
          <DropdownItem onClick={() => navigate('/settings')}>Settings</DropdownItem>
          <DropdownItem onClick={() => navigate('/settings#mfa')}>Two-factor auth</DropdownItem>
          <DropdownDivider />
          <DropdownItem
            danger
            onClick={async () => {
              await logout();
              navigate('/login');
            }}
          >
            Sign out
          </DropdownItem>
        </Dropdown>
      </div>
    </aside>
  );
}

function roleLabel(role?: string): string {
  switch (role) {
    case 'master_admin': return 'Master admin';
    case 'manager': return 'Manager';
    case 'team_member': return 'Team member';
    case 'brand_user': return 'Brand user';
    default: return '';
  }
}
