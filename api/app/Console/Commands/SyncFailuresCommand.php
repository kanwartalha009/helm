<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * WHY are the syncs failing — grouped by the actual error, not by brand.
 *
 * "Sync health shows failures" is a symptom shared by a dozen different causes (expired token,
 * exhausted quota, throttling, a bad customer id, a timeout). Reading them brand-by-brand in the UI
 * tells you how MANY are broken; it does not tell you WHAT is broken, and 200 rows of the same
 * message look like 200 problems when they are one.
 *
 * This collapses the failures into distinct error signatures with counts, newest first. One glance
 * = the real number of distinct faults.
 *
 *   php artisan sync:failures                 # last 24h
 *   php artisan sync:failures --hours=2       # since the fix was deployed
 *   php artisan sync:failures --platform=google
 *   php artisan sync:failures --full          # don't truncate the message
 */
class SyncFailuresCommand extends Command
{
    protected $signature = 'sync:failures '
        . '{--hours=24 : how far back to look} '
        . '{--platform= : shopify|meta|google|tiktok} '
        . '{--full : print the whole error message, untruncated}';

    protected $description = 'Group recent sync failures by their actual error message.';

    public function handle(): int
    {
        $hours    = max(1, (int) $this->option('hours'));
        $platform = (string) ($this->option('platform') ?? '');
        $since    = Carbon::now()->subHours($hours);

        $q = SyncLog::query()
            ->where('status', 'failed')
            ->where('created_at', '>=', $since);

        if ($platform !== '') {
            $q->where('platform', $platform);
        }

        $rows = $q->orderByDesc('created_at')->get(['platform', 'error_message', 'created_at', 'brand_id']);

        if ($rows->isEmpty()) {
            $this->info("No failed syncs in the last {$hours}h" . ($platform !== '' ? " for {$platform}" : '') . '.');
            $this->newLine();
            $this->line('If Sync Health still shows red, the rows you are looking at are OLDER than that');
            $this->line('window — a failure row stays failed forever; it is a record of what happened, not');
            $this->line('a live status. Re-run the sync and watch for NEW rows:');
            $this->line('    php artisan sync:daily');

            return self::SUCCESS;
        }

        // Group by the error SIGNATURE, not the literal message: ids, dates and numbers differ per
        // brand and would otherwise split one fault into 200 "distinct" ones.
        $groups = [];
        foreach ($rows as $r) {
            $msg = (string) ($r->error_message ?? '(no message recorded)');

            $sig = preg_replace('/\d{6,}/', '<id>', $msg) ?? $msg;          // customer ids, tokens
            $sig = preg_replace('/\d{4}-\d{2}-\d{2}/', '<date>', $sig) ?? $sig;
            $sig = preg_replace('/\d+/', 'N', $sig) ?? $sig;                 // retry seconds, counts
            $sig = mb_substr(trim($sig), 0, 300);

            $key = $r->platform . "\0" . $sig;

            $groups[$key] ??= [
                'platform' => (string) $r->platform,
                'count'    => 0,
                'brands'   => [],
                'sample'   => $msg,
                'latest'   => $r->created_at,
            ];
            $groups[$key]['count']++;
            $groups[$key]['brands'][(int) $r->brand_id] = true;
        }

        uasort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $this->newLine();
        $this->error(sprintf(
            '%d failed sync(s) in the last %dh — %d DISTINCT fault(s):',
            $rows->count(),
            $hours,
            count($groups),
        ));
        $this->newLine();

        $full = (bool) $this->option('full');
        $n    = 0;

        foreach ($groups as $g) {
            $n++;
            $this->line(sprintf(
                '%d) [%s] %d failure(s) across %d brand(s) — latest %s',
                $n,
                strtoupper($g['platform']),
                $g['count'],
                count($g['brands']),
                $g['latest']?->diffForHumans() ?? '?',
            ));

            $msg = $full ? $g['sample'] : mb_substr($g['sample'], 0, 400);
            foreach (explode("\n", wordwrap($msg, 110, "\n", true)) as $line) {
                $this->line('     ' . $line);
            }
            $this->newLine();
        }

        $this->line('Re-run ONE brand to see whether the fault is still live (a failed row is a record of');
        $this->line('what happened, not a live status — it stays red until a NEW run replaces it):');
        $this->line('    php artisan sync:daily --brand={slug}');

        return self::SUCCESS;
    }
}
