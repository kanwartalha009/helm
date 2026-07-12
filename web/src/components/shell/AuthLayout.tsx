import type { ReactNode } from 'react';
import { Wordmark } from '@/components/ui';
import { APP_NAME } from '@/lib/branding';

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
          <span>© {new Date().getFullYear()} {APP_NAME}</span>
          <span>{APP_NAME} v1.0</span>
        </div>
      </footer>
    </div>
  );
}
