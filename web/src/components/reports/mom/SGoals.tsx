import { useState } from 'react';
import { Card, Button } from '@/components/ui';
import { useCurrentUser } from '@/hooks/useSettings';
import { GoalsDrawer } from '@/components/brands/GoalsDrawer';
import type { MomFiltersInput } from '@/hooks/useMomReport';
import { useMomSection } from '@/hooks/useMomReport';
import { SECTION_CHART_RENDERERS } from './sectionCharts';

interface SGoalsPayload {
  status: string;
  note?: string;
  revenue?: { actual: number; target: number; pctOfTarget: number | null; status: string; goalHit: boolean } | null;
  roas?: { actual: number | null; target: number; status: string; goalHit: boolean } | null;
}

/**
 * M5 addendum (Kanwar, 2026-07-15 — "in report section of goals connect so
 * it will be easier to manage"). S-GOALS gets the SAME bespoke-card
 * treatment S0/S19 already have — it's the tightest connection point for
 * goal management, closer than a report-wide header button (which is where
 * Tiers lives, since tiers touch several sections at once; goals only touch
 * this one). The "Edit goals"/"Set a goal" button opens the SAME
 * `GoalsDrawer` brand Settings now uses (GoalsSummary) — one editing
 * surface, never a second copy.
 *
 * Shown even when status isn't 'ok' (no goal set yet) — previously that
 * state was a dead-end note pointing the operator back to Settings; now the
 * button is right there, so a first goal can be set without leaving the report.
 */
export function SGoalsCard({ slug, filters, label, currency }: { slug: string; filters: MomFiltersInput; label: string; currency: string }) {
  const { data, isLoading } = useMomSection<SGoalsPayload>(slug, 'S-GOALS', filters, true);
  const { data: user } = useCurrentUser();
  const canEdit = user?.role === 'master_admin' || user?.role === 'manager';
  const [open, setOpen] = useState(false);

  if (isLoading) {
    return (
      <Card style={{ padding: 18 }}>
        <div style={{ fontSize: 14, fontWeight: 650, marginBottom: 8 }}>{label}</div>
        <div className="muted text-sm">Loading…</div>
      </Card>
    );
  }

  const hasGoal = data?.status === 'ok';

  return (
    <Card style={{ padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10, gap: 12, flexWrap: 'wrap' }}>
        <div style={{ fontSize: 14, fontWeight: 650 }}>{label}</div>
        <Button size="sm" variant="ghost" type="button" onClick={() => setOpen(true)}>
          {hasGoal ? 'Edit goals' : 'Set a goal'}
        </Button>
      </div>

      {!hasGoal && (
        <div className="muted text-sm">
          {data?.note ?? 'No goal set for this brand yet.'}
        </div>
      )}

      {hasGoal && SECTION_CHART_RENDERERS['S-GOALS'](data, currency)}

      <GoalsDrawer slug={slug} canEdit={canEdit} open={open} onClose={() => setOpen(false)} />
    </Card>
  );
}
