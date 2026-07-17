import { useState } from 'react';
import { Button } from '@/components/ui';
import { useAuth } from '@/hooks/useAuth';
import { useCurrentUser } from '@/hooks/useSettings';
import { toQuery, useCreateMomShare, type MomFiltersInput, type MomReportShell } from '@/hooks/useMomReport';
import { toast } from '@/stores/toastStore';
import { CountryTierDrawer } from '@/components/brands/CountryTierDrawer';
import { GoalsDrawer } from '@/components/brands/GoalsDrawer';
import { ReportFormatDrawer } from '@/components/brands/ReportFormatDrawer';
import { MomSectionCard } from './MomSectionCard';
import { PresentationMode } from './PresentationMode';

/**
 * The MoM Strategy Report document — loops the shell's RESOLVED, ordered
 * section manifest (brand override -> agency default -> code default,
 * already resolved server-side by MomReport::build()) and renders one
 * MomSectionCard per ENABLED entry. Disabled sections are the customizer's
 * "Hide" toggle taking effect — they simply don't render, same as v1's
 * report documents honor their own layout.
 *
 * REV2 R6 — the "Present" button here turns this same resolved section list
 * into PresentationMode's full-screen slideshow, reusing MomSectionCard
 * unmodified per slide (see PresentationMode's own docblock).
 *
 * M5 addendum — "Share" snapshots the CURRENT filter selection + resolved
 * layout into a public token (MomShareController::create), then copies the
 * link — same UX as v1's existing share button (useCreateShare), against
 * mom's own dedicated share routes.
 */
export function MomReportDocument({ slug, shell, filters }: { slug: string; shell: MomReportShell; filters: MomFiltersInput }) {
  // S-GOALS was moved INTO the executive overview as goal cards (Kanwar,
  // 2026-07-15 — "move it to Executive overview cards"), so it never renders as
  // its own section anymore. Filtered here (not just disabled in config) so an
  // older saved layout that still lists S-GOALS can't double-render it.
  const enabled = shell.sections.filter((s) => s.enabled && s.key !== 'S-GOALS');
  const [presenting, setPresenting] = useState(false);
  const [goalsOpen, setGoalsOpen] = useState(false);
  const { data: user } = useAuth();
  const createShare = useCreateMomShare(slug);

  // M5 addendum (Kanwar, 2026-07-15) — "sidebar accessible with button... in
  // report as well" — the SAME CountryTierDrawer the brand Settings tab now
  // opens (BrandDetailPage), just triggered from here too, no second copy.
  const [tierDrawerOpen, setTierDrawerOpen] = useState(false);
  const [formatOpen, setFormatOpen] = useState(false);
  const { data: reportUser } = useCurrentUser();
  const canEditTiers = reportUser?.role === 'master_admin' || reportUser?.role === 'manager';

  const onShare = () => {
    createShare.mutate(
      { filters: toQuery(filters) },
      {
        onSuccess: (res) => {
          const url = window.location.origin + res.url;
          navigator.clipboard?.writeText(url).catch(() => undefined);
          toast.success('Share link created', `${url} — copied to clipboard.`);
        },
      },
    );
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 12 }}>
        <h2 style={{ margin: 0, fontSize: 20 }}>
          {shell.brand.name} — MoM Strategy Report
        </h2>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span className="muted text-sm">{shell.month.label}</span>
          <Button size="sm" variant="ghost" type="button" onClick={() => setGoalsOpen(true)}>
            Goals
          </Button>
          <Button size="sm" variant="ghost" type="button" onClick={() => setTierDrawerOpen(true)}>
            Tiers
          </Button>
          <Button size="sm" variant="ghost" type="button" onClick={() => setFormatOpen(true)}>
            Format
          </Button>
          <Button size="sm" variant="ghost" type="button" disabled={createShare.isPending} onClick={onShare}>
            {createShare.isPending ? 'Creating link…' : 'Share'}
          </Button>
          <Button size="sm" variant="secondary" type="button" onClick={() => setPresenting(true)}>
            Present
          </Button>
        </div>
      </div>

      {enabled.map((section) => (
        <MomSectionCard key={section.key} slug={slug} section={section} filters={filters} currency={shell.currency} />
      ))}

      <GoalsDrawer
        slug={slug}
        canEdit={canEditTiers}
        open={goalsOpen}
        onClose={() => setGoalsOpen(false)}
      />

      <CountryTierDrawer
        slug={slug}
        canEdit={canEditTiers}
        open={tierDrawerOpen}
        onClose={() => setTierDrawerOpen(false)}
        currency={shell.currency}
      />

      <ReportFormatDrawer
        slug={slug}
        canEdit={canEditTiers}
        open={formatOpen}
        onClose={() => setFormatOpen(false)}
      />

      <PresentationMode
        open={presenting}
        onClose={() => setPresenting(false)}
        brandName={shell.brand.name}
        agencyName={user?.agencyName || 'Roasdriven'}
        monthLabel={shell.month.label}
        sections={enabled}
        renderSection={(s) => {
          const full = enabled.find((e) => e.key === s.key)!;
          return <MomSectionCard slug={slug} section={full} filters={filters} currency={shell.currency} />;
        }}
      />
    </div>
  );
}
