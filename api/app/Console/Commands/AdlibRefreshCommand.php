<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdLibraryPage;
use App\Models\AdLibrarySearch;
use App\Models\SyncLog;
use App\Models\WorkspaceSetting;
use App\Platforms\MetaAdLibrary\AdLibraryClient;
use App\Platforms\Support\PlatformRateLimitedException;
use App\Services\AdsLibrary\AdLibrarySync;
use App\Services\AdsLibrary\SignalScorer;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nightly refresh of the market Ad Library corpus (Ads Library Phase 2.3): sweep
 * every tracked page (ALL/IMAGE/VIDEO to label media), run due saved searches,
 * upsert to ad_library_ads, mark departed ads inactive, then materialize Signal
 * Scores. One process, RANGED internally.
 *
 * Budget: when the hourly call budget is spent the client raises a rate-limit; we
 * SLEEP to the next hour and resume — UNLESS that would cross the 06:00 UTC
 * hard-stop, in which case we stop and record what remains (resumes next night).
 * Never blows the limit. A sync_log row (brand_id=null, platform=meta_adlib) lets
 * Sync health show it ran.
 *
 *   php artisan adlib:refresh
 *   php artisan adlib:refresh --pages=3 --stop=05:30
 */
class AdlibRefreshCommand extends Command
{
    protected $signature = 'adlib:refresh {--pages= : cursor pages per sweep} {--stop= : hard-stop HH:MM UTC}';

    protected $description = 'Refresh the market Ad Library corpus (tracked pages + saved searches) and materialize Signal Scores.';

    public function handle(AdLibrarySync $sync, SignalScorer $scorer, AdLibraryClient $client, PlatformCredentialService $creds): int
    {
        if (! $creds->has('meta_adlib', 'access_token')) {
            $this->warn('No Ad Library token configured — skipping. Add it in Settings → Platform keys.');

            return self::SUCCESS;
        }

        $maxPages = max(1, (int) ($this->option('pages') ?: config('adslibrary.refresh.pages_per_chunk_per_night', 5)));
        $stopAt   = $this->resolveStop((string) ($this->option('stop') ?: config('adslibrary.refresh.hard_stop_utc', '06:00')));
        $countries = (array) (WorkspaceSetting::getValue('adlib_countries', null) ?: config('adslibrary.default_countries', ['ES']));

        $log = SyncLog::create([
            'brand_id'    => null,
            'platform'    => 'meta_adlib',
            'target_date' => CarbonImmutable::now('UTC')->toDateString(),
            'status'      => 'running',
            'started_at'  => now(),
        ]);

        $upserted = 0;
        $pagesDone = 0;
        $searchesDone = 0;
        $stopped = false;

        try {
            foreach (AdLibraryPage::query()->where('status', 'active')->get() as $page) {
                $pageCountries = $page->country_default ? [(string) $page->country_default] : $countries;
                $r = $this->withBudget(fn () => $sync->syncPage($page, $pageCountries, $maxPages), $stopAt);
                if ($r['stopped']) {
                    $stopped = true;
                    break;
                }
                $upserted += (int) ($r['result']['upserted'] ?? 0);
                $pagesDone++;
            }

            if (! $stopped) {
                foreach ($this->dueSearches() as $search) {
                    $r = $this->withBudget(fn () => $sync->syncSearch($search, $maxPages), $stopAt);
                    if ($r['stopped']) {
                        $stopped = true;
                        break;
                    }
                    $upserted += (int) $r['result'];
                    $searchesDone++;
                }
            }

            // Score whatever landed, even on a partial run.
            $scorer->materialize();

            $log->update([
                'status'            => 'success',
                'completed_at'      => now(),
                'records_processed' => $upserted,
                'error_message'     => $stopped ? 'Hard-stopped at the UTC cutoff; the rest resumes next run.' : null,
            ]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            report($e);
            $this->error('adlib:refresh failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("adlib:refresh done — {$pagesDone} page(s), {$searchesDone} search(es), {$upserted} ads upserted"
            . ($stopped ? ' (hard-stopped — resumes next run)' : '') . '.');

        return self::SUCCESS;
    }

    /**
     * Run a unit; on a budget rate-limit, sleep to the next hour and retry — unless
     * that crosses the hard-stop, then bail. Reruns are idempotent (keyed upserts).
     *
     * @param callable(): mixed $unit
     * @return array{stopped: bool, result: mixed}
     */
    private function withBudget(callable $unit, CarbonImmutable $stopAt): array
    {
        while (true) {
            try {
                return ['stopped' => false, 'result' => $unit()];
            } catch (PlatformRateLimitedException $e) {
                $resumeAt = CarbonImmutable::now()->addSeconds($e->retryAfterSeconds);
                if ($resumeAt->greaterThan($stopAt)) {
                    Log::info('adlib.hard_stop', ['resume_would_be' => $resumeAt->toIso8601String()]);

                    return ['stopped' => true, 'result' => null];
                }
                $this->line("Hourly budget reached — sleeping {$e->retryAfterSeconds}s to the next hour…");
                sleep(max(1, $e->retryAfterSeconds));
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int, AdLibrarySearch> nightly always; weekly if ≥6 days since last run. */
    private function dueSearches(): \Illuminate\Support\Collection
    {
        return AdLibrarySearch::query()
            ->where('schedule', '!=', 'off')
            ->get()
            ->filter(function (AdLibrarySearch $s): bool {
                if ($s->schedule === 'nightly') {
                    return true;
                }
                $last = $s->last_run_at;

                return $last === null || $last->lt(now()->subDays(6));
            });
    }

    private function resolveStop(string $hhmm): CarbonImmutable
    {
        $stop = CarbonImmutable::parse($hhmm . ' UTC');
        // Manual daytime run (cutoff already passed today) → give a sane 6h window
        // instead of stopping immediately.
        return $stop->isPast() ? CarbonImmutable::now()->addHours(6) : $stop;
    }
}
