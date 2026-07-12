<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platforms\Slack\SlackClient;
use App\Services\Digest\SlackBlocks;
use App\Services\Digest\WeeklyDigest;
use Illuminate\Console\Command;

/**
 * The weekly digest (GO-3.5). Composes the week and, if a Slack webhook is configured,
 * posts it.
 *
 * NO WEBHOOK IS NOT AN ERROR. The in-app digest works regardless; Slack is optional
 * delivery. If the workspace hasn't done the Slack install (a Kanwar gate), this command
 * says so and exits 0 — a missing nice-to-have must never look like a broken cron.
 *
 * Slack failures are likewise tolerated: down, rate-limited, or webhook revoked all log
 * and return ok:false. The scheduler stays green.
 *
 *   php artisan digest:weekly
 *   php artisan digest:weekly --dry-run    # print it, send nothing
 */
class DigestWeeklyCommand extends Command
{
    protected $signature = 'digest:weekly {--dry-run : compose and print, send nothing}';

    protected $description = 'Compose the weekly digest and post it to Slack (if a webhook is configured).';

    public function handle(WeeklyDigest $digest, SlackBlocks $blocks, SlackClient $slack): int
    {
        $data = $digest->compose();

        if ($data['empty'] === true) {
            // An honest empty still goes out — silence from a tool you rely on is
            // ambiguous ("is it broken?"); "quiet week" is information.
            $this->line('Quiet week — ' . $data['emptyNote']);
        } else {
            $s = $data['sections'];
            $this->line(sprintf(
                'Week %s: %d new rec(s), %d open anomaly(ies), %d measured, %d competitor move(s).',
                $data['periodStart'],
                $s['newRecommendations']['count'] ?? 0,
                $s['anomalies']['count'] ?? 0,
                $s['trackRecord']['measuredThisWeek'] ?? 0,
                $s['competitorMovement']['count'] ?? 0,
            ));
        }

        if ($this->option('dry-run')) {
            $this->line(json_encode($blocks->build($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');

            return self::SUCCESS;
        }

        if (! $slack->configured()) {
            // The Slack install is Kanwar's to do. Not having done it is not a failure.
            $this->comment('No Slack webhook configured — the digest is available in-app. Add one at Settings → Platform keys → Slack.');

            return self::SUCCESS;
        }

        $res = $slack->post($blocks->build($data), 'Helm weekly digest');

        if (! $res['ok']) {
            // Logged inside the client. A chat integration must never fail the scheduler.
            $this->warn('Slack: ' . $res['message']);

            return self::SUCCESS;
        }

        $this->info('Digest posted to Slack.');

        return self::SUCCESS;
    }
}
