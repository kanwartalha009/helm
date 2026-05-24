import { Link } from 'react-router-dom';
import { AuthLayout } from '@/components/shell/AuthLayout';

export function NotFoundPage() {
  return (
    <AuthLayout>
      <div style={{ textAlign: 'center', maxWidth: 440 }}>
        <div className="num" style={{ fontSize: 72, fontWeight: 600, letterSpacing: '-0.04em', color: 'var(--text)' }}>
          404
        </div>
        <h2 style={{ marginTop: 8 }}>This page doesn’t exist</h2>
        <p className="text-sm mt-16">
          The link may be broken, the brand may have been archived, or the page you’re looking for hasn’t been built yet.
        </p>
        <div className="flex items-center justify-between gap-8 mt-32" style={{ justifyContent: 'center' }}>
          <Link to="/dashboard" className="btn btn-primary btn-sm">Go to dashboard</Link>
          <Link to="/sitemap" className="btn btn-secondary btn-sm">See all pages</Link>
        </div>
      </div>
    </AuthLayout>
  );
}
