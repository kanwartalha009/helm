<?php

declare(strict_types=1);

namespace App\Services\Playbook;

use App\Models\CampaignPlan;
use App\Services\Llm\LlmManager;
use RuntimeException;

/**
 * Turns an assembled plan into client-ready prose (GO-4.3, master plan §7.3).
 *
 * ══ THE LLM REWRITES. IT NEVER COMPUTES. ══ (D-016)
 * This is the ONLY place a model touches a plan, and the boundary is enforced by an
 * ALLOWLIST, not by good intentions:
 *
 *   `payload()` rebuilds a scrubbed copy of the plan containing ONLY the four allowlisted
 *   keys per entry (label, value, basis, detail). Nothing else can reach the model —
 *   not a brand id, not a customer row, not a raw metric, not a database record. Adding a
 *   field to the plan does not silently widen what the LLM sees; it has to be allowlisted
 *   here, deliberately.
 *
 * And the prose is stored in a SEPARATE column from the numbers. If the model hallucinates
 * a figure in its narrative, it cannot overwrite a single real one — the blocks remain
 * exactly as the rules computed them. The system prompt forbids inventing numbers, but the
 * architecture is what guarantees it: prose and figures never share a home.
 */
class PlanNarrator
{
    /** The ONLY keys a model may ever see from a plan entry. */
    private const ALLOWED_ENTRY_KEYS = ['label', 'value', 'basis', 'detail'];

    public function __construct(private readonly LlmManager $llm) {}

    public function available(): bool
    {
        return $this->llm->enabled();
    }

    /**
     * The scrubbed payload. Public so the audit TEST can assert exactly what leaves the
     * building — a boundary you cannot inspect is a boundary you cannot trust.
     *
     * @param array<string, mixed> $blocks
     * @return array<string, mixed>
     */
    public function payload(array $blocks, string $title): array
    {
        $clean = [];

        foreach ($blocks as $name => $block) {
            $entries = [];
            foreach ((array) ($block['entries'] ?? []) as $entry) {
                // Allowlist, not denylist. Anything not named here does not travel.
                $row = [];
                foreach (self::ALLOWED_ENTRY_KEYS as $k) {
                    if (array_key_exists($k, (array) $entry) && $entry[$k] !== null) {
                        $row[$k] = (string) $entry[$k];
                    }
                }
                if ($row !== []) {
                    $entries[] = $row;
                }
            }

            $clean[$name] = ['entries' => $entries];
            if (! empty($block['note'])) {
                $clean[$name]['note'] = (string) $block['note'];
            }
            if (! empty($block['reason'])) {
                $clean[$name]['reason'] = (string) $block['reason'];
            }
        }

        return ['title' => $title, 'blocks' => $clean];
    }

    /**
     * Rewrite the plan as prose. Operator-triggered only (never scheduled — D-016's cost
     * stance: no background LLM spend).
     */
    public function narrate(CampaignPlan $plan): string
    {
        if (! $this->available()) {
            throw new RuntimeException('No LLM key configured. The plan is complete without prose — add a key at Settings → Platform keys → AI / LLM to generate it.');
        }

        $payload = $this->payload((array) $plan->blocks, (string) $plan->title);

        $system = <<<'SYS'
        You are writing a seasonal campaign plan for an e-commerce client, on behalf of their agency.

        You will be given a plan that has ALREADY been computed. Your job is to turn it into clear,
        confident prose a client can read.

        ABSOLUTE RULES:
        - Do NOT invent, infer, estimate, adjust or recompute ANY number. Use only the figures given.
        - If a figure is missing or says "—", say plainly that it is not known. Never fill the gap.
        - Preserve every number exactly as written, including its units.
        - Each entry carries a `basis`: Verified (the client's own measured data), Proxy (public
          competitor signals — presence only, never spend), Modeled (a projection), or Source (an
          industry constant). Respect these: never present a Modeled or Proxy figure as measured fact.
        - Where a block is blocked (e.g. no margin set), say what is missing and why it matters.

        Write in plain British English. No hype, no filler, no emoji. Short paragraphs under clear
        headings. The reader is a busy store owner who wants to know what happens, when, and why.
        SYS;

        return trim($this->llm->client()->complete(
            $system,
            [['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}']],
            maxTokens: 1600,
        ));
    }
}
