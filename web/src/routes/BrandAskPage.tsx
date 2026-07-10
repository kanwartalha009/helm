import { useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Breadcrumb, Button, Card, Segmented } from '@/components/ui';
import { useBrandDetail } from '@/hooks/useApiData';
import { api } from '@/lib/api';

type Turn = { role: 'user' | 'assistant'; content: string };

/**
 * Custom reports via chat (feature spec §6, D-016): ask this brand's data a
 * question in natural language. The model sees ONLY the brand's aggregate
 * payload for the selected period (the same privacy boundary as the report
 * narrative) — no other brands, no customer data, no live DB access.
 * Conversations live in this page only; nothing is stored server-side.
 */
export function BrandAskPage() {
  const { slug } = useParams();
  const { data: detail } = useBrandDetail(slug);
  const brand = detail?.brand;

  const [period, setPeriod] = useState<'last7' | 'last30' | 'mtd'>('last30');
  const [thread, setThread] = useState<Turn[]>([]);
  const [input, setInput] = useState('');
  const [pending, setPending] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  const send = async () => {
    const message = input.trim();
    if (!message || pending || !slug) return;

    const history = thread.slice(-12);
    setThread((t) => [...t, { role: 'user', content: message }]);
    setInput('');
    setPending(true);
    setNotice(null);

    try {
      const { data } = await api.post<{ reply: string }>(`/brands/${slug}/ask`, {
        message,
        period,
        history,
      });
      setThread((t) => [...t, { role: 'assistant', content: data.reply }]);
    } catch (e: unknown) {
      const msg =
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        (e as Error)?.message ??
        'The request failed.';
      setNotice(msg);
      // Put the question back so nothing typed is lost.
      setThread((t) => t.slice(0, -1));
      setInput(message);
    } finally {
      setPending(false);
      setTimeout(() => scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' }), 50);
    }
  };

  const brandName = brand?.name ?? 'Brand';

  return (
    <AppLayout title="Ask the data">
      <Breadcrumb
        crumbs={[
          { label: 'Brands', to: '/brands' },
          { label: brandName, to: `/brands/${slug}` },
          { label: 'Ask' },
        ]}
      />

      <div className="page-header">
        <div>
          <h2 className="page-title">Ask {brandName}’s data</h2>
          <p className="page-subtitle">
            Answers come only from this brand's aggregate metrics for the selected window — figures are quoted, never
            invented. Verify anything important against the dashboard before sending it onward.
          </p>
        </div>
        <Segmented
          options={[
            { value: 'last7', label: 'Last 7 days' },
            { value: 'last30', label: 'Last 30 days' },
            { value: 'mtd', label: 'MTD' },
          ]}
          value={period}
          onChange={(v) => setPeriod(v as typeof period)}
        />
      </div>

      <Card style={{ display: 'flex', flexDirection: 'column', height: '60vh', minHeight: 420 }}>
        <div ref={scrollRef} style={{ flex: 1, overflowY: 'auto', padding: 20, display: 'grid', gap: 12, alignContent: 'start' }}>
          {thread.length === 0 && (
            <div className="muted text-sm" style={{ lineHeight: 1.7 }}>
              Try: “What drove revenue this period?” · “Which campaigns are wasting spend?” · “Compare my top 3
              products” · “Draft a summary for the client”.
            </div>
          )}
          {thread.map((t, i) => (
            <div
              key={i}
              style={{
                justifySelf: t.role === 'user' ? 'end' : 'start',
                maxWidth: '82%',
                padding: '10px 14px',
                borderRadius: 10,
                whiteSpace: 'pre-wrap',
                lineHeight: 1.6,
                fontSize: 14,
                background: t.role === 'user' ? 'var(--accent, #1f6f5c)' : 'var(--surface-subtle, #f5f4f0)',
                color: t.role === 'user' ? 'var(--accent-fg, #fff)' : 'inherit',
                border: t.role === 'user' ? 'none' : '1px solid var(--border)',
              }}
            >
              {t.content}
            </div>
          ))}
          {pending && <div className="muted text-sm">Thinking…</div>}
          {notice && (
            <div className="text-sm" style={{ color: 'var(--warning, #9a6700)' }}>
              {notice}
            </div>
          )}
        </div>

        <div style={{ borderTop: '1px solid var(--border)', padding: 12, display: 'flex', gap: 8 }}>
          <input
            className="input"
            style={{ flex: 1 }}
            placeholder={`Ask about ${brandName}’s ${period === 'mtd' ? 'month to date' : period.replace('last', 'last ')} days…`}
            value={input}
            maxLength={2000}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                void send();
              }
            }}
            disabled={pending}
          />
          <Button variant="primary" onClick={() => void send()} disabled={pending || !input.trim()}>
            {pending ? 'Sending…' : 'Send'}
          </Button>
        </div>
      </Card>
    </AppLayout>
  );
}
