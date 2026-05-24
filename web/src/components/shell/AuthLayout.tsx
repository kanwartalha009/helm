import type { ReactNode } from 'react';
import { Wordmark } from '@/components/ui';

interface AuthLayoutProps {
  children: ReactNode;
  homeTo?: string;
}

export function AuthLayout({ children, homeTo = '/' }: AuthLayoutProps) {
  return (
    <div className="auth-shell">
      <header className="auth-nav">
        <Wordmark to={homeTo} />
      </header>
      <main className="auth-main">{children}</main>
      <footer className="auth-footer">
        <div className="flex items-center justify-between">
          <span>© 2026 Nova Solution</span>
          <span>Helm v1.0</span>
        </div>
      </footer>
    </div>
  );
}
