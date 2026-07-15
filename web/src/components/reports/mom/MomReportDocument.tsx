import type { MomFiltersInput, MomReportShell } from '@/hooks/useMomReport';
import { MomSectionCard } from './MomSectionCard';

/**
 * The MoM Strategy Report document — loops the shell's RESOLVED, ordered
 * section manifest (brand override -> agency default -> code default,
 * already resolved server-side by MomReport::build()) and renders one
 * MomSectionCard per ENABLED entry. Disabled sections are the customizer's
 * "Hide" toggle taking effect — they simply don't render, same as v1's
 * report documents honor their own layout.
 */
export function MomReportDocument({ slug, shell, filters }: { slug: string; shell: MomReportShell; filters: MomFiltersInput }) {
  const enabled = shell.sections.filter((s) => s.enabled);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between' }}>
        <h2 style={{ margin: 0, fontSize: 20 }}>
          {shell.brand.name} — MoM Strategy Report
        </h2>
        <span className="muted text-sm">{shell.month.label}</span>
      </div>

      {enabled.map((section) => (
        <MomSectionCard key={section.key} slug={slug} section={section} filters={filters} currency={shell.currency} />
      ))}
    </div>
  );
}
