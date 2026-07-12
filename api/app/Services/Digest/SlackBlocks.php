<?php

declare(strict_types=1);

namespace App\Services\Digest;

/**
 * Renders a WeeklyDigest as Slack Block Kit (GO-3.5).
 *
 * Pure formatting — no data access, no decisions. It renders whatever the digest says,
 * INCLUDING the weeks where Helm's own advice lost. A digest that only reports wins is a
 * marketing email, and people learn to skim marketing emails.
 *
 * An honest empty renders as a single line and nothing else. No padding, no vanity
 * metrics to look busy.
 */
class SlackBlocks
{
    private const KIND_EMOJI = [
        'pause'            => ':octagonal_sign:',
        'fix'              => ':wrench:',
        'scale'            => ':chart_with_upwards_trend:',
        'budget_shift'     => ':moneybag:',
        'creative_refresh' => ':recycle:',
        'investigate'      => ':mag:',
        'launch'           => ':rocket:',
    ];

    /**
     * @param array<string, mixed> $digest
     * @return array<int, array<string, mixed>>
     */
    public function build(array $digest): array
    {
        $blocks = [[
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => 'Helm — week of ' . $digest['periodStart'], 'emoji' => true],
        ]];

        // Quiet week: say so and stop. Do not pad.
        if (($digest['empty'] ?? false) === true) {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => ':sleeping: ' . ($digest['emptyNote'] ?? 'Quiet week — nothing actionable.')],
            ];

            return $blocks;
        }

        $s = (array) ($digest['sections'] ?? []);

        // ── New recommendations ──
        $recs = (array) ($s['newRecommendations'] ?? []);
        if ((int) ($recs['count'] ?? 0) > 0) {
            $lines = [];
            foreach ((array) ($recs['rows'] ?? []) as $r) {
                $emoji = self::KIND_EMOJI[$r['kind'] ?? ''] ?? ':small_blue_diamond:';
                $lines[] = "{$emoji} *{$r['brand']}* — {$r['title']}";
            }
            $blocks[] = $this->section('*' . $recs['count'] . " new recommendation(s)*\n" . implode("\n", $lines));
        }

        // ── Open anomalies ──
        $an = (array) ($s['anomalies'] ?? []);
        if ((int) ($an['count'] ?? 0) > 0) {
            $lines = [];
            foreach ((array) ($an['rows'] ?? []) as $a) {
                $dot = ($a['severity'] ?? '') === 'critical' ? ':red_circle:' : ':large_orange_circle:';
                $kind = str_replace('_', ' ', (string) ($a['kind'] ?? ''));
                $lines[] = "{$dot} *{$a['brand']}* — {$kind} ({$a['date']})";
            }
            $blocks[] = $this->section('*' . $an['count'] . " open anomaly(ies)*\n" . implode("\n", $lines));
        }

        // ── Track record — including what Helm got WRONG ──
        $tr = (array) ($s['trackRecord'] ?? []);
        if ((int) ($tr['measuredThisWeek'] ?? 0) > 0 || $tr['overallImprovedPct'] !== null) {
            $overall = $tr['overallImprovedPct'] === null
                ? 'not enough measured yet'
                : $tr['overallImprovedPct'] . '% of accepted advice improved the target metric';

            $text = "*Helm's own track record*\n"
                . "This week: {$tr['measuredThisWeek']} measured — "
                . ":white_check_mark: {$tr['improvedThisWeek']} improved, "
                . ":x: {$tr['worsenedThisWeek']} worsened.\n"
                . "Overall: {$tr['overallTotal']} made, {$tr['overallAccepted']} accepted, {$overall}.";

            $blocks[] = $this->section($text);
        }

        // ── Competitor movement (Proxy) ──
        $cm = (array) ($s['competitorMovement'] ?? []);
        if ((int) ($cm['count'] ?? 0) > 0) {
            $lines = [];
            foreach ((array) ($cm['rows'] ?? []) as $m) {
                $lines[] = ':eyes: ' . (string) ($m['message'] ?? '');
            }
            $blocks[] = $this->section("*Competitor movement*\n" . implode("\n", $lines));
            // The label rides along — this is public-signal data, not performance.
            $blocks[] = [
                'type'     => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => (string) ($cm['label'] ?? 'Proxy — public signals')]],
            ];
        }

        return $blocks;
    }

    /** @return array<string, mixed> */
    private function section(string $mrkdwn): array
    {
        return [
            'type' => 'section',
            // Slack truncates a section at 3000 chars — clip rather than have it rejected.
            'text' => ['type' => 'mrkdwn', 'text' => mb_substr($mrkdwn, 0, 2900)],
        ];
    }
}
