import { Segmented } from '@/components/ui';
import type { MomFiltersInput, MomReportShell } from '@/hooks/useMomReport';

type CompareChoice = 'previous' | 'last_year' | 'custom';

/**
 * REV2 R3 — "Report-level filter bar: base month selector + compare mode:
 * Previous month (default) | Same month last year | Custom (pick ANY second
 * month)." `compareChoice` is UI-only state; 'custom' maps to
 * `filters.compareMonth` (which overrides the derived previous/last_year
 * month server-side per ReportFilters::compareMonthWindow()), the other two
 * map straight to `filters.compare`.
 */
export type FilterMode = 'month' | 'range';
export type RangeCompare = 'last_year' | 'previous';

export function MomFilterBar({
  shell,
  mode,
  onModeChange,
  month,
  onMonthChange,
  compareChoice,
  onCompareChoiceChange,
  customCompareMonth,
  onCustomCompareMonthChange,
  from,
  to,
  onFromChange,
  onToChange,
  rangeCompare,
  onRangeCompareChange,
}: {
  shell: MomReportShell | undefined;
  mode: FilterMode;
  onModeChange: (m: FilterMode) => void;
  month: string | undefined;
  onMonthChange: (m: string) => void;
  compareChoice: CompareChoice;
  onCompareChoiceChange: (c: CompareChoice) => void;
  customCompareMonth: string;
  onCustomCompareMonthChange: (m: string) => void;
  from: string;
  to: string;
  onFromChange: (d: string) => void;
  onToChange: (d: string) => void;
  rangeCompare: RangeCompare;
  onRangeCompareChange: (c: RangeCompare) => void;
}) {
  const options = shell?.availableMonths ?? [];

  return (
    <div className="filter-bar mb-12" style={{ gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
      {/* Month vs custom day-range mode (Kanwar, 2026-07-17 — "compare the first
          2 weeks of the month year over year"). Month mode keeps every existing
          control; range mode swaps in a from/to picker that compares the same
          range a year earlier. */}
      <Segmented
        options={[
          { value: 'month', label: 'Month' },
          { value: 'range', label: 'Custom range' },
        ]}
        value={mode}
        onChange={(v) => onModeChange(v as FilterMode)}
      />

      {mode === 'month' && (
        <>
          {options.length > 0 && (
            <>
              <span className="muted text-sm">Month</span>
              <select
                className="input"
                style={{ maxWidth: 200 }}
                value={month ?? options[0].key}
                onChange={(e) => onMonthChange(e.target.value)}
                aria-label="Report month"
              >
                {options.map((m) => (
                  <option key={m.key} value={m.key}>
                    {m.label}
                  </option>
                ))}
              </select>
            </>
          )}

          <Segmented
            options={[
              { value: 'previous', label: 'vs previous month' },
              { value: 'last_year', label: 'vs same month last year' },
              { value: 'custom', label: 'Custom' },
            ]}
            value={compareChoice}
            onChange={(v) => onCompareChoiceChange(v as CompareChoice)}
          />

          {compareChoice === 'custom' && (
            <input
              type="month"
              className="input"
              style={{ width: 150 }}
              value={customCompareMonth}
              onChange={(e) => onCustomCompareMonthChange(e.target.value)}
              aria-label="Custom compare month"
            />
          )}
        </>
      )}

      {mode === 'range' && (
        <>
          <span className="muted text-sm">From</span>
          <input
            type="date"
            className="input"
            style={{ width: 160 }}
            value={from}
            max={to || undefined}
            onChange={(e) => onFromChange(e.target.value)}
            aria-label="Range start date"
          />
          <span className="muted text-sm">to</span>
          <input
            type="date"
            className="input"
            style={{ width: 160 }}
            value={to}
            min={from || undefined}
            onChange={(e) => onToChange(e.target.value)}
            aria-label="Range end date"
          />
          <Segmented
            options={[
              { value: 'last_year', label: 'vs same range last year' },
              { value: 'previous', label: 'vs previous period' },
            ]}
            value={rangeCompare}
            onChange={(v) => onRangeCompareChange(v as RangeCompare)}
          />
          {(!from || !to) && <span className="muted text-sm">Pick a start and end date.</span>}
        </>
      )}

      {mode === 'month' && shell?.compareMonth && <span className="muted text-sm">vs {shell.compareMonth.label}</span>}

      {shell && !shell.freshness.upToDate && (
        <span className="muted text-sm" title={shell.freshness.note ?? `Last synced ${shell.freshness.lastSynced ?? 'never'}`} style={{ color: 'var(--warning, #9a6700)' }}>
          ⚠ Data may be stale ({shell.freshness.staleDays} day{shell.freshness.staleDays === 1 ? '' : 's'} behind)
        </span>
      )}
    </div>
  );
}

export function toMomFilters(
  month: string | undefined,
  compareChoice: CompareChoice,
  customCompareMonth: string,
): MomFiltersInput {
  return {
    month,
    compare: compareChoice === 'last_year' ? 'last_year' : 'previous',
    compareMonth: compareChoice === 'custom' && customCompareMonth ? customCompareMonth : undefined,
  };
}

export type { CompareChoice };
