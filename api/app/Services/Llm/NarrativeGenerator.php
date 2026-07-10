<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Brand;
use App\Models\ReportNarrative;
use App\Reports\Contracts\ReportFilters;
use Illuminate\Support\Facades\Auth;

/**
 * Turns a brand's aggregate data (BrandDataScope — the D-016 privacy
 * boundary) into the four editable narrative blocks the report renders:
 * observations, actionable outputs, action plan, new ideas (spec §5.1's
 * Observaciones / Outputs Accionables / Plan de Acción / Nuevas Ideas).
 *
 * Contract: the LLM writes PROSE about numbers that already exist in the
 * data payload. It is instructed never to compute or invent figures; the
 * report's numeric sections come from rules regardless, so a hallucinated
 * number can only ever appear inside an editable prose block the operator
 * reviews before send (D-016: always a draft, edited before send).
 */
final class NarrativeGenerator
{
    private const BLOCK_KEYS = ['observations', 'actions', 'plan', 'ideas'];

    public function __construct(
        private readonly LlmManager $llm,
        private readonly BrandDataScope $scope,
    ) {}

    public function generate(Brand $brand, string $reportType, ReportFilters $filters, ?string $language = null): ReportNarrative
    {
        $language = in_array($language, ['en', 'es'], true) ? $language : 'en';
        $data     = $this->scope->build($brand, $filters);
        $client   = $this->llm->client();

        $raw    = $client->complete($this->systemPrompt($language), [
            ['role' => 'user', 'content' => $this->userPrompt($data)],
        ]);
        $blocks = $this->parseBlocks($raw);

        [$start, $end] = $filters->window($brand->timezone ?: 'UTC');

        return ReportNarrative::updateOrCreate(
            [
                'brand_id'    => $brand->id,
                'report_type' => $reportType,
                'period_key'  => $this->periodKey($filters),
            ],
            [
                'blocks'               => $blocks,
                'edited_blocks'        => null, // a fresh draft resets edits — regenerate is explicit
                'provider'             => $client->provider(),
                'model'                => $client->model(),
                'language'             => $language,
                'window_start'         => $start,
                'window_end'           => $end,
                'generated_by_user_id' => Auth::id(),
                'generated_at'         => now(),
                'edited_at'            => null,
            ],
        );
    }

    public function find(Brand $brand, string $reportType, ReportFilters $filters): ?ReportNarrative
    {
        return ReportNarrative::query()
            ->where('brand_id', $brand->id)
            ->where('report_type', $reportType)
            ->where('period_key', $this->periodKey($filters))
            ->first();
    }

    public function periodKey(ReportFilters $filters): string
    {
        // custom ranges key on their dates so two different ranges never share a draft.
        $suffix = $filters->period === 'custom' ? ":{$filters->from}:{$filters->to}" : '';

        return "{$filters->period}|{$filters->compare}{$suffix}";
    }

    private function systemPrompt(string $language): string
    {
        $lang = $language === 'es' ? 'Spanish' : 'English';

        return <<<PROMPT
You are the senior performance analyst at a direct-to-consumer marketing agency, writing the narrative for a client-facing monthly/periodic performance report.

Rules, in order of importance:
1. NEVER invent, estimate, or recompute a number. Only quote figures that appear verbatim in the DATA JSON. If a figure you want is absent, describe the trend qualitatively or say the data is not available.
2. Missing data is missing, not zero. If a section of the data is null or empty (no ad spend, no product rows), say it plainly instead of guessing.
3. Be specific and factual. Name the campaigns, products and countries from the data. No hype, no filler, no "in today's competitive landscape".
4. Write for the brand's owner: professional, direct, focused on what to DO next to improve conversions and profitability.
5. Write in {$lang}.

Output format — STRICT JSON, nothing before or after it, no markdown fences:
{
  "observations": "...",  // what happened this period and why — root causes, referencing the daily series and comparisons
  "actions": "...",       // actionable outputs — concrete, prioritised moves grounded in the campaign/product data
  "plan": "...",          // the action plan for next period — who does what, sequenced
  "ideas": "..."          // new ideas worth testing — creative angles, products, audiences suggested BY the data
}

Each value is a markdown string: short paragraphs and "- " bullet lists only (no headings, no tables, no bold spam). 80–160 words per block.
PROMPT;
    }

    /** @param array<string, mixed> $data */
    private function userPrompt(array $data): string
    {
        return "DATA JSON for this reporting period:\n\n"
            . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nWrite the four narrative blocks now.";
    }

    /**
     * Parse the model's JSON, tolerating code fences and stray prose around
     * the object. Anything unparseable throws — the operator sees a clean
     * error and can regenerate, never a half-written draft.
     *
     * @return array<string, string>
     */
    private function parseBlocks(string $raw): array
    {
        $candidate = trim($raw);

        // Strip ```json fences if the model added them despite instructions.
        if (str_starts_with($candidate, '```')) {
            $candidate = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $candidate) ?? $candidate;
        }

        // Fall back to the outermost {...} if prose leaked around the object.
        if (! str_starts_with(ltrim($candidate), '{')) {
            $from = strpos($candidate, '{');
            $to   = strrpos($candidate, '}');
            if ($from === false || $to === false || $to <= $from) {
                throw new LlmException('The model did not return JSON — regenerate.');
            }
            $candidate = substr($candidate, $from, $to - $from + 1);
        }

        $decoded = json_decode(trim($candidate), true);
        if (! is_array($decoded)) {
            throw new LlmException('The model returned malformed JSON — regenerate.');
        }

        $blocks = [];
        foreach (self::BLOCK_KEYS as $key) {
            $value = $decoded[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new LlmException("The model's draft is missing the '{$key}' block — regenerate.");
            }
            $blocks[$key] = trim($value);
        }

        return $blocks;
    }
}
