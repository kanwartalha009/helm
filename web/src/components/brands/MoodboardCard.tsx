import { useEffect, useState } from 'react';
import { Card, Button } from '@/components/ui';
import { formatRoas } from '@/lib/formatters';
import {
  useBrandStyle,
  useSaveBrandStyle,
  useSuggestBrandStyle,
  type StyleSwatch,
} from '@/hooks/useBrandStyle';
import { toast } from '@/stores/toastStore';

/**
 * GO-4.4 (master plan §7.4) — the brand moodboard. Shows the brand's own
 * verified winning creatives, an extracted colour palette, tone words and
 * do/don't guidance, and the confirm gate: a style is a DRAFT (suggestions)
 * until an operator confirms it, at which point GO-5 may generate against it.
 *
 * "Suggest from winners" pulls a palette (dominant-colour binning of the winner
 * thumbnails) and LLM-drafted tone words for the operator to review/edit — it
 * never auto-saves. Save keeps a draft; Confirm signs it off.
 */
export function MoodboardCard({ slug, canEdit }: { slug?: string; canEdit: boolean }) {
  const { data } = useBrandStyle(slug);
  const suggest = useSuggestBrandStyle(slug);
  const save = useSaveBrandStyle(slug);

  const [palette, setPalette] = useState<StyleSwatch[]>([]);
  const [tone, setTone] = useState<string[]>([]);
  const [dos, setDos] = useState<string[]>([]);
  const [donts, setDonts] = useState<string[]>([]);
  const [toneInput, setToneInput] = useState('');
  const [dirty, setDirty] = useState(false);

  useEffect(() => {
    if (!data) return;
    setPalette(data.palette ?? []);
    setTone(data.toneWords ?? []);
    setDos(data.doDont?.do ?? []);
    setDonts(data.doDont?.dont ?? []);
    setDirty(false);
  }, [data]);

  const body = () => ({ palette, toneWords: tone, doDont: { do: dos, dont: donts } });

  const onSuggest = () => {
    suggest.mutate(undefined, {
      onSuccess: (s) => {
        if (s.palette?.length) setPalette(s.palette);
        if (s.toneWords?.length) setTone((t) => Array.from(new Set([...t, ...s.toneWords])));
        setDirty(true);
        toast.success('Suggestions ready', 'Review and edit, then Save or Confirm.');
      },
    });
  };

  const onSave = (confirm: boolean) =>
    save.mutate(
      { ...body(), confirm },
      {
        onSuccess: () =>
          toast.success(confirm ? 'Style confirmed' : 'Draft saved', confirm ? 'GO-5 can now generate against it.' : 'Still a draft.'),
      },
    );

  const addTone = () => {
    const w = toneInput.trim();
    if (w && !tone.includes(w)) {
      setTone((t) => [...t, w]);
      setDirty(true);
    }
    setToneInput('');
  };

  const status = data?.status ?? 'none';
  const winners = data?.winners ?? [];

  return (
    <div className="field">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
        <label className="field-label" style={{ margin: 0 }}>Moodboard / brand style</label>
        <span
          className="chip"
          style={{
            background: status === 'confirmed' ? '#1f6f5c1a' : '#9a9a9a1a',
            borderColor: status === 'confirmed' ? '#1f6f5c' : 'var(--border)',
            fontSize: 11,
          }}
        >
          {status === 'confirmed' ? 'Confirmed' : status === 'draft' ? 'Draft — not confirmed' : 'Not set up'}
        </span>
      </div>
      <span className="field-hint">
        The palette, tone and winners GO-5 creative generation is grounded in. Suggestions are drafts — an operator confirms
        before anything generates against them.
      </span>

      <Card style={{ padding: 16, marginTop: 10, display: 'flex', flexDirection: 'column', gap: 16 }}>
        {/* Palette */}
        <div>
          <div style={{ fontSize: 12, fontWeight: 650, marginBottom: 6 }}>Palette</div>
          {palette.length === 0 && <span className="muted text-sm">No palette yet — “Suggest from winners” extracts one from your top ads.</span>}
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {palette.map((s, i) => (
              <span key={`${s.hex}-${i}`} title={s.hex} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                <span style={{ width: 26, height: 26, borderRadius: 6, background: s.hex, border: '1px solid var(--border)' }} />
                {canEdit && (
                  <button
                    type="button"
                    onClick={() => { setPalette((p) => p.filter((_, idx) => idx !== i)); setDirty(true); }}
                    aria-label={`Remove ${s.hex}`}
                    style={{ border: 0, background: 'transparent', cursor: 'pointer', color: 'var(--text-muted)', fontSize: 12 }}
                  >×</button>
                )}
              </span>
            ))}
          </div>
        </div>

        {/* Tone words */}
        <div>
          <div style={{ fontSize: 12, fontWeight: 650, marginBottom: 6 }}>Tone words</div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
            {tone.map((w) => (
              <span key={w} className="chip" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                {w}
                {canEdit && (
                  <button type="button" onClick={() => { setTone((t) => t.filter((x) => x !== w)); setDirty(true); }}
                    aria-label={`Remove ${w}`} style={{ border: 0, background: 'transparent', cursor: 'pointer', color: 'var(--text-muted)', fontSize: 12 }}>×</button>
                )}
              </span>
            ))}
            {tone.length === 0 && <span className="muted text-sm">None yet.</span>}
            {canEdit && (
              <input
                className="input"
                style={{ width: 120, fontSize: 12 }}
                value={toneInput}
                placeholder="+ word"
                onChange={(e) => setToneInput(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addTone(); } }}
                onBlur={addTone}
              />
            )}
          </div>
        </div>

        {/* Do / Don't */}
        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
          <DoDontColumn title="Do" items={dos} canEdit={canEdit} onChange={(v) => { setDos(v); setDirty(true); }} />
          <DoDontColumn title="Don't" items={donts} canEdit={canEdit} onChange={(v) => { setDonts(v); setDirty(true); }} />
        </div>

        {/* Winners */}
        <div>
          <div style={{ fontSize: 12, fontWeight: 650, marginBottom: 6 }}>Verified winners (top creatives, last 90d)</div>
          {winners.length === 0 && <span className="muted text-sm">No creatives with meaningful spend + a thumbnail yet.</span>}
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
            {winners.slice(0, 8).map((w) => (
              <div key={w.adId} style={{ width: 96 }}>
                <img
                  src={w.thumbnailUrl}
                  alt={w.adName ?? w.adId}
                  style={{ width: 96, height: 96, objectFit: 'cover', borderRadius: 8, border: '1px solid var(--border)', background: '#f4f4f2' }}
                  onError={(e) => { (e.currentTarget as HTMLImageElement).style.visibility = 'hidden'; }}
                />
                <div className="muted" style={{ fontSize: 10, marginTop: 2 }}>{formatRoas(w.roas)} ROAS</div>
              </div>
            ))}
          </div>
        </div>

        {canEdit && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <Button size="sm" variant="ghost" type="button" disabled={suggest.isPending} onClick={onSuggest}>
              {suggest.isPending ? 'Generating…' : 'Suggest from winners'}
            </Button>
            <span style={{ flex: 1 }} />
            <Button size="sm" variant="ghost" type="button" disabled={save.isPending || (!dirty && status !== 'none')} onClick={() => onSave(false)}>
              Save draft
            </Button>
            <Button size="sm" variant="secondary" type="button" disabled={save.isPending} onClick={() => onSave(true)}>
              {status === 'confirmed' ? 'Re-confirm' : 'Confirm style'}
            </Button>
          </div>
        )}
      </Card>
    </div>
  );
}

function DoDontColumn({
  title,
  items,
  canEdit,
  onChange,
}: {
  title: string;
  items: string[];
  canEdit: boolean;
  onChange: (v: string[]) => void;
}) {
  const [input, setInput] = useState('');
  const add = () => {
    const v = input.trim();
    if (v) onChange([...items, v]);
    setInput('');
  };
  return (
    <div style={{ flex: '1 1 200px', minWidth: 180 }}>
      <div style={{ fontSize: 12, fontWeight: 650, marginBottom: 6 }}>{title}</div>
      <ul style={{ margin: 0, paddingLeft: 16 }}>
        {items.map((it, i) => (
          <li key={i} style={{ fontSize: 12, display: 'flex', gap: 6, alignItems: 'baseline' }}>
            <span style={{ flex: 1 }}>{it}</span>
            {canEdit && (
              <button type="button" onClick={() => onChange(items.filter((_, idx) => idx !== i))}
                aria-label={`Remove ${it}`} style={{ border: 0, background: 'transparent', cursor: 'pointer', color: 'var(--text-muted)', fontSize: 12 }}>×</button>
            )}
          </li>
        ))}
        {items.length === 0 && <li className="muted" style={{ fontSize: 12, listStyle: 'none', marginLeft: -16 }}>None yet.</li>}
      </ul>
      {canEdit && (
        <input
          className="input"
          style={{ fontSize: 12, marginTop: 6, width: '100%' }}
          value={input}
          placeholder={`+ ${title.toLowerCase()}`}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } }}
          onBlur={add}
        />
      )}
    </div>
  );
}
