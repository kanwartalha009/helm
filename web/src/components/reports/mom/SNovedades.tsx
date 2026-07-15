import { useEffect, useState } from 'react';
import { Card, Button } from '@/components/ui';
import type { MomFiltersInput } from '@/hooks/useMomReport';
import { useMomSection, useSaveMomNovedades } from '@/hooks/useMomReport';

interface S19Payload {
  status: string;
  month: string;
  body?: string;
  source?: 'brand' | 'workspace';
  isBrandOverride?: boolean;
  note?: string;
}

/**
 * M4 (monthly-report-v2-mom.md §M4) — S19 "Novedades... agency-wide monthly
 * talking points... per-brand editable copy." This component IS the
 * per-brand editable copy; the agency-wide default is written in Settings
 * (a separate surface — Novedades::resolve()'s workspace-default fallback,
 * not duplicated here).
 */
export function SNovedadesCard({ slug, filters, label }: { slug: string; filters: MomFiltersInput; label: string }) {
  const { data, isLoading } = useMomSection<S19Payload>(slug, 'S19', filters, true);
  const save = useSaveMomNovedades(slug);
  const [text, setText] = useState<string | null>(null);

  useEffect(() => {
    setText(null); // reset local edit buffer whenever the underlying data changes (new month, new save)
  }, [data?.month, data?.body]);

  if (isLoading) {
    return (
      <Card style={{ padding: 18 }}>
        <div style={{ fontSize: 14, fontWeight: 650, marginBottom: 8 }}>{label}</div>
        <div className="muted text-sm">Loading…</div>
      </Card>
    );
  }

  const month = data?.month ?? filters.month;
  const value = text ?? data?.body ?? '';

  return (
    <Card style={{ padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 }}>
        <div style={{ fontSize: 14, fontWeight: 650 }}>{label}</div>
        {data?.status === 'ok' && (
          <span className="muted text-sm">
            {data.isBrandOverride ? 'This brand’s own copy' : 'Agency-wide default'}
          </span>
        )}
      </div>

      {data?.status !== 'ok' && !text && <div className="muted text-sm" style={{ marginBottom: 8 }}>{data?.note}</div>}

      <textarea
        className="input"
        style={{ width: '100%', minHeight: 80, fontSize: 13 }}
        placeholder="This month's talking points…"
        value={value}
        onChange={(e) => setText(e.target.value)}
      />
      <Button
        size="sm"
        variant="secondary"
        type="button"
        style={{ marginTop: 8 }}
        disabled={save.isPending || !month || value.trim() === ''}
        onClick={() => month && save.mutate({ month, body: value })}
      >
        {save.isPending ? 'Saving…' : 'Save brand copy'}
      </Button>
    </Card>
  );
}
