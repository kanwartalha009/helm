import { useEffect, useState } from 'react';
import { Button } from '@/components/ui';
import {
  useApplyLayoutToAllBrands,
  useClearReportLayout,
  useReportLayout,
  useSaveAgencyDefaultLayout,
  useSaveReportLayout,
  type ReportLayoutSection,
} from '@/hooks/useReportLayouts';
import { useCurrentUser } from '@/hooks/useSettings';
import { toast } from '@/stores/toastStore';

const REPORT_TYPE = 'mom';

/**
 * M1 + REV2 R2 (monthly-report-v2-mom.md) — "Customize report format for this
 * brand." Reorders sections (up/down buttons — the spec explicitly permits this
 * over drag-and-drop: "plain HTML5 drag or up/down buttons — no new deps"),
 * toggles a section on/off, and picks its default view (chart | table | both).
 *
 * Until M2 ships the mom report itself, this edits the LAYOUT that M2's section
 * endpoints will read — the section list here comes from api/config/momreport.php
 * (M1's catalog), so the customizer has real content from day one instead of
 * waiting for M2.
 */
export function ReportFormatSection({ slug, canEdit }: { slug?: string; canEdit: boolean }) {
  const { data } = useReportLayout(slug, REPORT_TYPE);
  const save = useSaveReportLayout(slug, REPORT_TYPE);
  const clear = useClearReportLayout(slug, REPORT_TYPE);
  const saveAgencyDefault = useSaveAgencyDefaultLayout(REPORT_TYPE);
  const applyAll = useApplyLayoutToAllBrands(REPORT_TYPE);
  const { data: currentUser } = useCurrentUser();
  const isMasterAdmin = currentUser?.role === 'master_admin';

  const [sections, setSections] = useState<ReportLayoutSection[]>([]);

  useEffect(() => {
    if (data) setSections(data.sections);
  }, [data]);

  const move = (i: number, dir: -1 | 1) => {
    setSections((s) => {
      const next = [...s];
      const j = i + dir;
      if (j < 0 || j >= next.length) return s;
      [next[i], next[j]] = [next[j], next[i]];
      return next.map((sec, idx) => ({ ...sec, position: idx }));
    });
  };

  const toggle = (i: number) => {
    setSections((s) => s.map((sec, idx) => (idx === i ? { ...sec, enabled: !sec.enabled } : sec)));
  };

  const setView = (i: number, view: ReportLayoutSection['view']) => {
    setSections((s) => s.map((sec, idx) => (idx === i ? { ...sec, view } : sec)));
  };

  const onSave = () => {
    save.mutate(
      sections.map((s, idx) => ({ ...s, position: idx })),
      {
        onSuccess: () => toast.success('Report format saved', 'The mom report will use this section order.'),
        onError: () => toast.error('Could not save', 'Admins and managers only.'),
      },
    );
  };

  const onResetToAgencyDefault = () => {
    clear.mutate(undefined, {
      onSuccess: () => toast.success('Reverted', 'This brand now follows the agency default report format.'),
    });
  };

  const orderedSections = () => sections.map((s, idx) => ({ ...s, position: idx }));

  const onSaveAgencyDefault = () => {
    saveAgencyDefault.mutate(orderedSections(), {
      onSuccess: () => toast.success('Agency default saved', 'New brands, and any brand without its own format, now use this layout.'),
      onError: () => toast.error('Could not save', 'Master admins only.'),
    });
  };

  const onApplyToAllBrands = () => {
    const ok = window.confirm(
      'Apply this format to EVERY brand?\n\nThis makes it the agency default AND removes every brand’s own custom format so they all use this one. This cannot be undone.',
    );
    if (!ok) return;
    applyAll.mutate(orderedSections(), {
      onSuccess: (res) =>
        toast.success('Applied to all brands', `${res.brandsReset} brand${res.brandsReset === 1 ? '' : 's'} reset to this format.`),
      onError: () => toast.error('Could not apply', 'Master admins only.'),
    });
  };

  return (
    <>
      <div className="field" style={{ marginTop: 8 }}>
        <label className="field-label">Report format — MoM Strategy Report</label>
        <span className="field-hint">
          {data?.hasOverride
            ? 'This brand has its own section order.'
            : 'Following the agency default format — reorder below to customize for this brand.'}
        </span>
      </div>

      {sections.map((sec, i) => (
        <div
          key={sec.key}
          className="flex items-center gap-8"
          style={{ marginBottom: 6, opacity: sec.enabled ? 1 : 0.5 }}
        >
          <span style={{ width: 28, fontFamily: 'monospace', fontSize: 12 }}>{sec.key}</span>
          <span style={{ flex: 1 }}>{sec.label ?? sec.key}</span>
          <select
            className="input"
            style={{ width: 100 }}
            value={sec.view}
            onChange={(e) => setView(i, e.target.value as ReportLayoutSection['view'])}
            disabled={!canEdit}
          >
            <option value="chart">Chart</option>
            <option value="table">Table</option>
            <option value="both">Both</option>
          </select>
          {canEdit && (
            <>
              <Button size="sm" variant="ghost" type="button" onClick={() => toggle(i)}>
                {sec.enabled ? 'Hide' : 'Show'}
              </Button>
              <Button size="sm" variant="ghost" type="button" disabled={i === 0} onClick={() => move(i, -1)}>
                ↑
              </Button>
              <Button size="sm" variant="ghost" type="button" disabled={i === sections.length - 1} onClick={() => move(i, 1)}>
                ↓
              </Button>
            </>
          )}
        </div>
      ))}

      {canEdit && (
        <div className="flex items-center gap-8" style={{ marginTop: 8 }}>
          <Button size="sm" variant="secondary" type="button" disabled={save.isPending} onClick={onSave}>
            {save.isPending ? 'Saving…' : 'Save format'}
          </Button>
          {data?.hasOverride && (
            <Button size="sm" variant="ghost" type="button" disabled={clear.isPending} onClick={onResetToAgencyDefault}>
              Reset to agency default
            </Button>
          )}
        </div>
      )}

      {/* Agency-wide controls (master_admin only, Kanwar 2026-07-17): set the
          default every brand inherits, or push this format to every brand at once. */}
      {isMasterAdmin && (
        <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--border)' }}>
          <span className="field-hint" style={{ display: 'block', marginBottom: 6 }}>
            Agency-wide — applies across every brand, not just this one.
          </span>
          <div className="flex items-center gap-8">
            <Button size="sm" variant="ghost" type="button" disabled={saveAgencyDefault.isPending} onClick={onSaveAgencyDefault}>
              {saveAgencyDefault.isPending ? 'Saving…' : 'Save as agency default'}
            </Button>
            <Button size="sm" variant="secondary" type="button" disabled={applyAll.isPending} onClick={onApplyToAllBrands}>
              {applyAll.isPending ? 'Applying…' : 'Apply to all brands'}
            </Button>
          </div>
        </div>
      )}
    </>
  );
}
