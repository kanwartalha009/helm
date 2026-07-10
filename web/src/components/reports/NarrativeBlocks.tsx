import type { NarrativeBlocksShape, ReportNarrativePayload } from '@/types/reports';

const BLOCKS: { key: keyof NarrativeBlocksShape; label: string; hint: string }[] = [
  { key: 'observations', label: 'Observations', hint: 'What happened this period and why.' },
  { key: 'actions', label: 'Actionable outputs', hint: 'Concrete, prioritised moves grounded in the data.' },
  { key: 'plan', label: 'Action plan', hint: 'Next period, sequenced.' },
  { key: 'ideas', label: 'New ideas', hint: 'Worth testing, suggested by the data.' },
];

/**
 * The four LLM narrative blocks (spec §5.1 Observaciones / Outputs Accionables /
 * Plan de Acción / Nuevas Ideas; D-016). Always a draft the operator edits
 * before send — blocks are contentEditable in editable mode and save on blur.
 * Rules own every number in the report; these blocks are prose only.
 *
 * Renders nothing when there is neither a stored narrative nor an editable
 * context (a shared report without narrative stays clean).
 */
export function NarrativeBlocks({
  narrative,
  sharedBlocks,
  editable = false,
  llmEnabled = false,
  generating = false,
  onGenerate,
  onBlockChange,
}: {
  narrative?: ReportNarrativePayload | null;
  /** Blocks frozen into a share's content — the public render path. */
  sharedBlocks?: NarrativeBlocksShape | null;
  editable?: boolean;
  llmEnabled?: boolean;
  generating?: boolean;
  onGenerate?: () => void;
  onBlockChange?: (key: keyof NarrativeBlocksShape, value: string) => void;
}) {
  const blocks = sharedBlocks ?? narrative?.blocks ?? null;

  if (!editable && !blocks) return null;

  return (
    <div className="rpt-narrative" style={{ marginTop: 14 }}>
      <style>{`@media print { .rpt-npr { display: none !important } }`}</style>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <div className="rpt-ai-tag">
          AI analysis{editable ? ' · editable' : ''}
          {narrative && !sharedBlocks && (
            <span style={{ opacity: 0.65 }}>
              {' '}· drafted by {narrative.provider} ({narrative.model})
              {narrative.generatedAt ? ` · ${new Date(narrative.generatedAt).toLocaleDateString()}` : ''}
              {narrative.isEdited ? ' · edited' : ''}
            </span>
          )}
        </div>
        <span style={{ flex: 1 }} />
        {editable && (
          <span className="rpt-npr">
            {llmEnabled ? (
              <button
                type="button"
                onClick={onGenerate}
                disabled={generating}
                style={{
                  border: '1px solid var(--rpt-accent, #1f6f5c)',
                  color: 'var(--rpt-accent, #1f6f5c)',
                  background: 'transparent',
                  borderRadius: 6,
                  padding: '4px 12px',
                  fontSize: 12,
                  fontWeight: 500,
                  cursor: generating ? 'wait' : 'pointer',
                  fontFamily: 'inherit',
                }}
              >
                {generating ? 'Drafting…' : blocks ? 'Regenerate draft' : 'Generate with AI'}
              </button>
            ) : (
              <span style={{ fontSize: 12, opacity: 0.65 }}>
                Add an LLM key (Settings → Platform keys → AI / LLM) to draft this analysis.
              </span>
            )}
          </span>
        )}
      </div>

      {blocks &&
        BLOCKS.map(({ key, label, hint }) => (
          <div key={key} style={{ marginTop: 10 }}>
            <div style={{ fontSize: 11, fontWeight: 600, letterSpacing: '0.04em', textTransform: 'uppercase', opacity: 0.7, marginBottom: 4 }}>
              {label}
            </div>
            {editable ? (
              <div
                className="rpt-note"
                contentEditable
                suppressContentEditableWarning
                aria-label={`${label} — ${hint}`}
                onBlur={(e) => onBlockChange?.(key, e.currentTarget.textContent ?? '')}
                style={{ whiteSpace: 'pre-wrap' }}
              >
                {blocks[key]}
              </div>
            ) : (
              <div className="rpt-note" style={{ whiteSpace: 'pre-wrap' }}>
                {blocks[key]}
              </div>
            )}
          </div>
        ))}

      {editable && !blocks && llmEnabled && (
        <div className="rpt-note rpt-npr" style={{ marginTop: 10, opacity: 0.7 }}>
          No draft yet for this period — generate one, review it, edit anything, then share. The numbers above never
          come from the AI; it only writes the analysis.
        </div>
      )}
    </div>
  );
}
