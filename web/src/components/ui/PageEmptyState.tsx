import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';

interface Step {
  n: number;
  title: string;
  body: string;
  to?: string;
  onClick?: () => void;
  cta?: string;
}

interface PageEmptyStateProps {
  icon?: ReactNode;
  title: string;
  body: ReactNode;
  primary?: ReactNode;
  secondary?: ReactNode;
  steps?: Step[];
  footnote?: ReactNode;
}

/**
 * Centered empty-state block modelled after DashboardEmptyState. Used by
 * pages that have nothing to show yet (no brands, no users, no audit
 * events) — each one gets a focused next-step CTA instead of a blank table.
 *
 * Visual structure: circular icon → headline → paragraph → CTA row →
 * optional 3-step explainer → optional footnote. See DashboardPage.tsx
 * for the original.
 */
export function PageEmptyState({
  icon,
  title,
  body,
  primary,
  secondary,
  steps,
  footnote,
}: PageEmptyStateProps) {
  return (
    <div style={{ maxWidth: 720, margin: '8vh auto 0', textAlign: 'center' }}>
      <div
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 72,
          height: 72,
          borderRadius: '50%',
          background: 'var(--surface-subtle)',
          border: '1px solid var(--border)',
          marginBottom: 24,
          color: 'var(--text-secondary)',
        }}
      >
        {icon ?? (
          <svg
            width="28"
            height="28"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
          >
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
          </svg>
        )}
      </div>

      <h2 style={{ marginBottom: 8 }}>{title}</h2>
      <p className="lede" style={{ margin: '0 auto 32px', maxWidth: 480 }}>
        {body}
      </p>

      {(primary || secondary) && (
        <div
          className="flex items-center gap-12"
          style={{ justifyContent: 'center', marginBottom: steps ? 48 : 0 }}
        >
          {primary}
          {secondary}
        </div>
      )}

      {steps && steps.length > 0 && (
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: `repeat(${steps.length}, 1fr)`,
            gap: 1,
            background: 'var(--border)',
            border: '1px solid var(--border)',
            borderRadius: 'var(--radius-lg)',
            overflow: 'hidden',
            textAlign: 'left',
          }}
        >
          {steps.map((s) => (
            <StepCard key={s.n} {...s} />
          ))}
        </div>
      )}

      {footnote && (
        <p className="text-xs muted" style={{ marginTop: 24 }}>
          {footnote}
        </p>
      )}
    </div>
  );
}

function StepCard({ n, title, body, to, onClick, cta }: Step) {
  return (
    <div style={{ background: 'var(--surface)', padding: 22 }}>
      <div
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 22,
          height: 22,
          borderRadius: '50%',
          background: 'var(--accent)',
          color: 'var(--accent-fg)',
          fontSize: 12,
          fontWeight: 500,
          marginBottom: 10,
        }}
      >
        {n}
      </div>
      <div style={{ fontWeight: 500, marginBottom: 6, color: 'var(--text)' }}>{title}</div>
      <div
        style={{
          fontSize: 13,
          color: 'var(--text-secondary)',
          marginBottom: cta ? 14 : 0,
          lineHeight: 1.55,
        }}
      >
        {body}
      </div>
      {cta && to && (
        <Link to={to} className="text-sm" style={{ color: 'var(--text)', fontWeight: 500 }}>
          {cta} →
        </Link>
      )}
      {cta && !to && onClick && (
        <button
          onClick={onClick}
          className="text-sm"
          style={{
            background: 'transparent',
            border: 0,
            padding: 0,
            cursor: 'pointer',
            color: 'var(--text)',
            fontWeight: 500,
            fontFamily: 'inherit',
          }}
        >
          {cta} →
        </button>
      )}
    </div>
  );
}
