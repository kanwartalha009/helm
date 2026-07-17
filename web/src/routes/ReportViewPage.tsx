import { useEffect, useRef, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { AppLayout } from '@/components/shell/AppLayout';
import { Button, Card, Segmented } from '@/components/ui';
import { ReportDocument } from '@/components/reports/ReportDocument';
import { MonthlyReportDocument } from '@/components/reports/MonthlyReportDocument';
import { WeeklyReportDocument } from '@/components/reports/WeeklyReportDocument';
import { CreativeReportDocument } from '@/components/reports/CreativeReportDocument';
import { AdsAuditReportDocument } from '@/components/reports/AdsAuditReportDocument';
import { useCreateShare, useGenerateNarrative, useReport, useSaveNarrative } from '@/hooks/useReports';
import { useBrandDetail } from '@/hooks/useApiData';
import { adPlatformsOf } from '@/components/ads/AdPlatformToggle';
import { useTriggerSync } from '@/hooks/useBrands';
import { toast } from '@/stores/toastStore';
import type { AdsAuditPlatformFilter, NarrativeBlocksShape, ReportFiltersInput, ReportWindowOption } from '@/types/reports';

/**
 * In-app report view: filters, the editable white-label document, and the
 * delivery action (share link — link sharing only, the Export PDF button was
 * removed 2026-07-10). Before the report renders, a freshness gate checks the
 * data is current for the selected window — a client should never receive
 * stale numbers, so when the latest synced day is behind we block on a fresh
 * sync (with a "show anyway" escape).
 */

type PeriodChoice = ReportFiltersInput['period']; // includes 'custom'
type PlatformChoice = 'all' | AdsAuditPlatformFilter;

const PLATFORM_FILTER_LABEL: Record<string, string> = { meta: 'Meta', google: 'Google', tiktok: 'TikTok' };

// Latest complete day — custom date inputs cap here so a partial "today"
// never enters a client report.
function yesterdayIso(): string {
  const d = new Date();
  d.setDate(d.getDate() - 1);
  return d.toISOString().slice(0, 10);
}

export function ReportViewPage() {
  const { slug, type } = useParams();
  const [searchParams] = useSearchParams();
  const qc = useQueryClient();

  const [period, setPeriod] = useState<PeriodChoice>('last30');
  const [compare, setCompare] = useState<ReportFiltersInput['compare']>('previous');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [month, setMonth] = useState<string | undefined>(undefined);
  const [week, setWeek] = useState<string | undefined>(undefined);
  // ?platform=meta deep link from the picker page seeds the ads-audit filter.
  const urlPlatform = searchParams.get('platform');
  const [platform, setPlatform] = useState<PlatformChoice>(
    urlPlatform === 'meta' || urlPlatform === 'google' || urlPlatform === 'tiktok' ? urlPlatform : 'all',
  );
  const [commentary, setCommentary] = useState('');
  const [nextSteps, setNextSteps] = useState('');
  const [targets, setTargets] = useState<{ blendedRoas: number | null; newCustomerRoas: number | null }>({ blendedRoas: null, newCustomerRoas: null });
  const [showAnyway, setShowAnyway] = useState(false);

  const isMonthly = type === 'monthly';
  const isWeekly = type === 'weekly';
  const isAdsAudit = type === 'ads-audit';
  const hasFixedWindow = isMonthly || isWeekly;

  // The brand's connected ad platforms drive the ads-audit filter options — a
  // brand without TikTok never sees a TikTok chip. A deep-linked ?platform=
  // for an unconnected platform falls back to All (honest empty is avoided).
  const { data: brandDetail } = useBrandDetail(isAdsAudit ? slug : undefined);
  const availablePlatforms = adPlatformsOf(brandDetail?.brand?.platforms);
  useEffect(() => {
    if (isAdsAudit && platform !== 'all' && brandDetail && !availablePlatforms.includes(platform)) {
      setPlatform('all');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAdsAudit, brandDetail, platform]);

  // A custom window only switches the query once BOTH dates are valid — until
  // then we keep serving the last concrete preset so the report never flashes
  // a half-specified window.
  const lastPresetRef = useRef<Exclude<PeriodChoice, 'custom'>>('last30');
  if (period !== 'custom') lastPresetRef.current = period;
  const customReady = period === 'custom' && !!fromDate && !!toDate && fromDate <= toDate;
  const effectivePeriod: PeriodChoice = period === 'custom' ? (customReady ? 'custom' : lastPresetRef.current) : period;

  const filters: ReportFiltersInput = {
    period: effectivePeriod,
    compare,
    ...(customReady ? { from: fromDate, to: toDate } : {}),
    ...(isMonthly && month ? { month } : {}),
    ...(isWeekly && week ? { week } : {}),
    ...(isAdsAudit && platform !== 'all' ? { platform } : {}),
  };

  const { data, isLoading, isError, error } = useReport(slug, type, filters);
  const createShare = useCreateShare(slug, type);
  const triggerSync = useTriggerSync();
  const generateNarrative = useGenerateNarrative(slug, type);
  const saveNarrative = useSaveNarrative(slug, type);
  // Local copy of the narrative blocks while the operator edits — the server
  // draft stays the base; edits PATCH on every block blur (D-016: edited
  // before send).
  const [narrativeEdits, setNarrativeEdits] = useState<NarrativeBlocksShape | null>(null);

  const filterSignature = [effectivePeriod, compare, filters.from ?? '', filters.to ?? '', filters.month ?? '', filters.week ?? '', filters.platform ?? ''].join('|');

  // A new window must re-check freshness, so the gate can't be bypassed for it.
  useEffect(() => setShowAnyway(false), [filterSignature, slug, type]);
  useEffect(() => setNarrativeEdits(null), [filterSignature, slug, type]);

  // Keep the month / week option lists across refetches so the picker doesn't
  // vanish while a newly selected window loads.
  const [monthOptions, setMonthOptions] = useState<ReportWindowOption[]>([]);
  const [weekOptions, setWeekOptions] = useState<ReportWindowOption[]>([]);
  useEffect(() => {
    if (data?.reportType === 'monthly' && data.availableMonths?.length) setMonthOptions(data.availableMonths);
    if (data?.reportType === 'weekly' && data.availableWeeks?.length) setWeekOptions(data.availableWeeks);
  }, [data]);
  // The route component is reused across /brands/:slug/reports/:type param
  // changes — window selections and cached option lists must not leak from one
  // brand/report to the next.
  useEffect(() => {
    setMonth(undefined);
    setWeek(undefined);
    setMonthOptions([]);
    setWeekOptions([]);
  }, [slug, type]);

  const effectiveNarrativeBlocks = (): NarrativeBlocksShape | null =>
    narrativeEdits ??
    (data?.reportType === 'overall-performance' || data?.reportType === 'weekly' || data?.reportType === 'creatives'
      ? data.narrative?.blocks ?? null
      : null);

  const onGenerateNarrative = () => {
    generateNarrative.mutate(
      { ...filters },
      {
        onSuccess: () => {
          setNarrativeEdits(null);
          toast.success('Draft ready', 'Review and edit the AI analysis before you share.');
        },
      },
    );
  };

  const onNarrativeBlockChange = (key: keyof NarrativeBlocksShape, value: string) => {
    const base = effectiveNarrativeBlocks();
    if (!base) return;
    const blocks = { ...base, [key]: value };
    setNarrativeEdits(blocks);
    saveNarrative.mutate({ ...filters, blocks });
  };

  const stale = !!data?.freshness && !data.freshness.upToDate;

  const monthLabel = data?.reportType === 'monthly' ? data.month.label : null;
  const weekLabel = data?.reportType === 'weekly' ? data.week.label : null;

  const onShare = () => {
    const narrativeBlocks = effectiveNarrativeBlocks() ?? undefined;
    createShare.mutate(
      { filters, content: { commentary, nextSteps, targets, narrativeBlocks } },
      {
        onSuccess: (res) => {
          const url = window.location.origin + res.url;
          navigator.clipboard?.writeText(url).catch(() => undefined);
          toast.success('Share link created', `${url} — copied to clipboard.`);
        },
      },
    );
  };

  const onSyncAndRefresh = () => {
    if (!slug) return;
    triggerSync.mutate(slug, {
      onSuccess: () => {
        toast.success('Sync started', 'Pulling fresh data — the report refreshes here in a moment.');
        // The sync runs on the queue; give it a head start, then refetch so the
        // gate clears itself once the latest day lands.
        setTimeout(() => qc.invalidateQueries({ queryKey: ['report', slug] }), 15000);
      },
      onError: (e: unknown) =>
        toast.error('Couldn’t start sync', (e as { message?: string })?.message ?? 'Unknown error'),
    });
  };

  const maxDate = yesterdayIso();

  const periodControls = (
    <>
      <Segmented
        options={[
          { value: 'last7', label: 'Last 7 days' },
          { value: 'last30', label: 'Last 30 days' },
          { value: 'mtd', label: 'Month to date' },
          { value: 'custom', label: 'Custom' },
        ]}
        value={period}
        onChange={(v) => setPeriod(v as PeriodChoice)}
      />
      {period === 'custom' && (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          <input
            type="date"
            className="input"
            style={{ width: 150 }}
            value={fromDate}
            max={toDate || maxDate}
            onChange={(e) => setFromDate(e.target.value)}
            aria-label="Custom period from"
          />
          <span className="muted text-sm">to</span>
          <input
            type="date"
            className="input"
            style={{ width: 150 }}
            value={toDate}
            min={fromDate || undefined}
            max={maxDate}
            onChange={(e) => setToDate(e.target.value)}
            aria-label="Custom period to"
          />
        </span>
      )}
      <Segmented
        options={[
          { value: 'previous', label: 'vs previous' },
          { value: 'last_year', label: 'vs last year' },
        ]}
        value={compare}
        onChange={(v) => setCompare(v as ReportFiltersInput['compare'])}
      />
    </>
  );

  return (
    <AppLayout title="Report">
      <div className="filter-bar mb-12" style={{ gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
        {!hasFixedWindow && periodControls}
        {/* Platform filter offers ONLY the brand's connected ad platforms —
            a brand without TikTok never sees a TikTok option (Kanwar
            2026-07-10). Hidden entirely when fewer than 2 are connected. */}
        {isAdsAudit && availablePlatforms.length >= 2 && (
          <Segmented
            options={[
              { value: 'all', label: 'All platforms' },
              ...availablePlatforms.map((p) => ({ value: p, label: PLATFORM_FILTER_LABEL[p] ?? p })),
            ]}
            value={platform}
            onChange={(v) => setPlatform(v as PlatformChoice)}
          />
        )}
        {/* Monthly: Month vs Custom day-range mode (Kanwar, 2026-07-17 — item 1).
            A custom range drives the whole report off a sub-month window compared
            to the same range last year; the month-by-month tables collapse. */}
        {isMonthly && (
          <Segmented
            options={[
              { value: 'month', label: 'Month' },
              { value: 'range', label: 'Custom range' },
            ]}
            value={period === 'custom' ? 'range' : 'month'}
            onChange={(v) => {
              if (v === 'range') {
                setPeriod('custom');
                setCompare('last_year'); // range default: same range last year
              } else {
                setPeriod('last30');
              }
            }}
          />
        )}
        {isMonthly && period === 'custom' && (
          <>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              <input type="date" className="input" style={{ width: 150 }} value={fromDate} max={toDate || maxDate} onChange={(e) => setFromDate(e.target.value)} aria-label="Range from" />
              <span className="muted text-sm">to</span>
              <input type="date" className="input" style={{ width: 150 }} value={toDate} min={fromDate || undefined} max={maxDate} onChange={(e) => setToDate(e.target.value)} aria-label="Range to" />
            </span>
            <Segmented
              options={[
                { value: 'last_year', label: 'vs same range last year' },
                { value: 'previous', label: 'vs previous period' },
              ]}
              value={compare}
              onChange={(v) => setCompare(v as ReportFiltersInput['compare'])}
            />
            {(!fromDate || !toDate) && <span className="muted text-sm">Pick a start and end date.</span>}
          </>
        )}
        {isMonthly && period !== 'custom' && monthOptions.length > 0 && (
          <>
            <span className="muted text-sm">Month</span>
            <select
              className="input"
              style={{ maxWidth: 200 }}
              value={month ?? monthOptions[0].key}
              onChange={(e) => setMonth(e.target.value === monthOptions[0].key ? undefined : e.target.value)}
              aria-label="Report month"
            >
              {monthOptions.map((m) => (
                <option key={m.key} value={m.key}>{m.label}</option>
              ))}
            </select>
          </>
        )}
        {isMonthly && period !== 'custom' && !month && monthLabel && <span className="muted text-sm">Last complete month · {monthLabel}</span>}
        {isWeekly && weekOptions.length > 0 && (
          <>
            <span className="muted text-sm">Week</span>
            <select
              className="input"
              style={{ maxWidth: 230 }}
              value={week ?? weekOptions[0].key}
              onChange={(e) => setWeek(e.target.value === weekOptions[0].key ? undefined : e.target.value)}
              aria-label="Report week"
            >
              {weekOptions.map((w) => (
                <option key={w.key} value={w.key}>{w.label}</option>
              ))}
            </select>
          </>
        )}
        {isWeekly && weekOptions.length === 0 && weekLabel && <span className="muted text-sm">Week · {weekLabel}</span>}
        <span style={{ flex: 1 }} />
        <Button variant="primary" onClick={onShare} disabled={!data || (stale && !showAnyway) || createShare.isPending}>
          {createShare.isPending ? 'Creating…' : 'Create share link'}
        </Button>
      </div>

      {isLoading && <div className="muted" style={{ padding: 24 }}>Building report…</div>}
      {isError && (
        <div className="muted" style={{ padding: 24 }}>
          Couldn’t load the report: {(error as any)?.response?.data?.message ?? (error as Error)?.message}
        </div>
      )}
      {data &&
        (stale && !showAnyway ? (
          <FreshnessGate
            staleDays={data.freshness!.staleDays}
            lastSynced={data.freshness!.lastSynced}
            windowEnd={data.freshness!.windowEnd}
            syncing={triggerSync.isPending}
            onSync={onSyncAndRefresh}
            onShowAnyway={() => setShowAnyway(true)}
          />
        ) : data.reportType === 'monthly' ? (
          <MonthlyReportDocument data={data} editable onCommentaryChange={setCommentary} onNextStepsChange={setNextSteps} onTargetsChange={setTargets} />
        ) : data.reportType === 'weekly' ? (
          <WeeklyReportDocument
            data={data}
            editable
            onCommentaryChange={setCommentary}
            generatingNarrative={generateNarrative.isPending}
            onGenerateNarrative={onGenerateNarrative}
            onNarrativeBlockChange={onNarrativeBlockChange}
          />
        ) : data.reportType === 'creatives' ? (
          <CreativeReportDocument
            data={data}
            editable
            onCommentaryChange={setCommentary}
            generatingNarrative={generateNarrative.isPending}
            onGenerateNarrative={onGenerateNarrative}
            onNarrativeBlockChange={onNarrativeBlockChange}
          />
        ) : data.reportType === 'ads-audit' ? (
          // No narrative blocks for ads-audit v1 — commentary only.
          <AdsAuditReportDocument data={data} editable onCommentaryChange={setCommentary} />
        ) : (
          <ReportDocument
            data={data}
            editable
            onCommentaryChange={setCommentary}
            generatingNarrative={generateNarrative.isPending}
            onGenerateNarrative={onGenerateNarrative}
            onNarrativeBlockChange={onNarrativeBlockChange}
          />
        ))}
    </AppLayout>
  );
}

function FreshnessGate({
  staleDays,
  lastSynced,
  windowEnd,
  syncing,
  onSync,
  onShowAnyway,
}: {
  staleDays: number;
  lastSynced: string | null;
  windowEnd: string;
  syncing: boolean;
  onSync: () => void;
  onShowAnyway: () => void;
}) {
  return (
    <Card style={{ padding: 28, maxWidth: 560, margin: '24px auto', textAlign: 'center' }}>
      <div style={{ fontSize: 15, fontWeight: 600, marginBottom: 8 }}>
        Sync fresh data before generating this report
      </div>
      <p className="muted text-sm" style={{ marginBottom: 18, lineHeight: 1.6 }}>
        {lastSynced ? (
          <>
            The latest complete day on file is <b>{lastSynced}</b>, but this report covers through{' '}
            <b>{windowEnd}</b> — it’s <b>{staleDays} day{staleDays === 1 ? '' : 's'}</b> behind. Sync now so the
            numbers are correct before you send it.
          </>
        ) : (
          <>No synced data is on file for this brand yet. Run a sync to pull the data this report needs.</>
        )}
      </p>
      <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
        <Button variant="primary" onClick={onSync} disabled={syncing}>
          {syncing ? 'Starting sync…' : 'Sync now'}
        </Button>
        <Button variant="secondary" onClick={onShowAnyway}>
          Show anyway
        </Button>
      </div>
    </Card>
  );
}
