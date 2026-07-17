import { Drawer } from '@/components/ui';
import { ReportFormatSection } from './ReportFormatSection';

/**
 * Report-format customizer as a slide-over (Kanwar, 2026-07-17 — "move this
 * setting to sidebar and the button to interact should be visible inside the
 * report"). Wraps the existing ReportFormatSection unchanged — one editor, now
 * opened from a button on the MoM report itself instead of buried in brand
 * Settings (same move already made for Goals and Country tiers).
 */
export function ReportFormatDrawer({
  slug,
  canEdit,
  open,
  onClose,
}: {
  slug: string | undefined;
  canEdit: boolean;
  open: boolean;
  onClose: () => void;
}) {
  return (
    <Drawer open={open} onClose={onClose} size="lg" title="Report format">
      <div className="field" style={{ marginBottom: 10 }}>
        <span className="field-hint">
          Reorder sections, hide them, or switch chart / table / both. Changes apply to this brand's MoM Strategy
          Report and its share links.
        </span>
      </div>
      <ReportFormatSection slug={slug} canEdit={canEdit} />
    </Drawer>
  );
}
