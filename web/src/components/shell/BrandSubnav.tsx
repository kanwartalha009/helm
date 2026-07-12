import { NavLink } from 'react-router-dom';
import { cn } from '@/lib/cn';
import { useCurrentUser } from '@/hooks/useSettings';

const TABS: { seg: string; label: string; requiresLlm?: boolean }[] = [
  { seg: 'ads', label: 'Ad performance' },
  { seg: 'products', label: 'Products' },
  { seg: 'audit', label: 'Store audit' },
  // GO-2.2 budget planner lives here; GO-3.2's Stop/Scale/Fix board joins it.
  { seg: 'planning', label: 'Planning' },
  { seg: 'ask', label: 'Ask the data', requiresLlm: true },
];

/**
 * Sibling navigation across a brand's deep-dive pages (Ad performance · Products ·
 * Store audit · Ask). Renders under the breadcrumb on each per-brand page so a
 * media buyer can hop between a brand's views without returning to the brand
 * page. The active tab is derived from the URL via NavLink. The AI "Ask the data"
 * tab is hidden when the workspace has no LLM configured — no dead tabs for a
 * workspace without the AI add-on.
 */
export function BrandSubnav({ slug }: { slug?: string }) {
  const { data: user } = useCurrentUser();
  if (!slug) return null;
  const tabs = TABS.filter((t) => !t.requiresLlm || user?.llmEnabled);
  return (
    <nav className="brand-subnav" aria-label="Brand views">
      {tabs.map((t) => (
        <NavLink
          key={t.seg}
          to={`/brands/${slug}/${t.seg}`}
          className={({ isActive }) => cn('brand-subnav-tab', isActive && 'active')}
        >
          {t.label}
        </NavLink>
      ))}
    </nav>
  );
}
