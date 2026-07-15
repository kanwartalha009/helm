<?php

declare(strict_types=1);

namespace App\Services\Creative;

use App\Services\Llm\LlmManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GO-5.1 — the TEXT modality (master plan §8). Turns an allowlisted
 * CreativeBrief into copy variants, hook lines and UGC shot-scripts via the LLM
 * (D-016: prose-only, key-gated). This is the only modality shipping now; image
 * and video are the gated seam.
 *
 * The model receives ONLY `$brief->toLlmPayload()` — the scrubbed whitelist — so
 * no customer data can reach it. Output is requested as strict JSON and parsed
 * defensively; a malformed or failed completion yields [] (honest "nothing
 * generated"), never a fabricated draft.
 */
final class TextCreativeGenerator implements CreativeGenerator
{
    private ?string $modelId = null;

    public function __construct(private readonly LlmManager $llm)
    {
    }

    public function modality(): string
    {
        return 'text';
    }

    public function modelId(): ?string
    {
        return $this->modelId;
    }

    /**
     * @return array<int, array{kind: string, content: array<string, mixed>}>
     */
    public function generate(CreativeBrief $brief, int $n): array
    {
        if (! $this->llm->enabled()) {
            return []; // key-gated (D-016): no key → no call, honest empty
        }

        $n = max(1, min(10, $n));
        $client = $this->llm->client();
        $this->modelId = $client->model();

        $system = 'You are a senior DTC performance copywriter. You will receive a JSON brief with a brand\'s'
            . ' confirmed tone, colour palette, do/don\'t rules, product facts, proven hook tags (with the brand\'s'
            . " OWN verified median ROAS/CTR), and an optional seasonal moment. Write ad creative grounded strictly"
            . " in that brief.\n\nRULES:\n"
            . "- Use ONLY facts in the brief. Do NOT invent prices, claims, discounts, stats or product features.\n"
            . "- Honour the tone words and the do/don't rules. Lean on the proven hook tags where they fit.\n"
            . "- Reply with STRICT JSON only, no prose around it, shaped exactly:\n"
            . '{"copy":[{"headline":"","body":""}],"hooks":["",""],"ugcScripts":[{"title":"","script":""}]}' . "\n"
            . "- Give {$n} copy variants, {$n} hooks, and up to {$n} UGC scripts.";

        $payload = json_encode($brief->toLlmPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $raw = $client->complete($system, [
                ['role' => 'user', 'content' => "BRIEF:\n{$payload}"],
            ], 1500);
        } catch (Throwable $e) {
            Log::info('creative.text_generate_failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $this->parse($raw);
    }

    /**
     * Parse the model's JSON into normalised variant rows. Tolerant of code
     * fences and stray text around the JSON object.
     *
     * @return array<int, array{kind: string, content: array<string, mixed>}>
     */
    private function parse(string $raw): array
    {
        $json = $this->extractJson($raw);
        if ($json === null) {
            return [];
        }

        $out = [];

        foreach ($json['copy'] ?? [] as $c) {
            if (! is_array($c)) {
                continue;
            }
            $headline = trim((string) ($c['headline'] ?? ''));
            $body     = trim((string) ($c['body'] ?? ''));
            if ($headline === '' && $body === '') {
                continue;
            }
            $out[] = ['kind' => 'copy', 'content' => ['headline' => $headline, 'body' => $body]];
        }

        foreach ($json['hooks'] ?? [] as $h) {
            $text = trim((string) (is_array($h) ? ($h['text'] ?? '') : $h));
            if ($text === '') {
                continue;
            }
            $out[] = ['kind' => 'hook', 'content' => ['text' => $text]];
        }

        foreach ($json['ugcScripts'] ?? [] as $u) {
            if (! is_array($u)) {
                continue;
            }
            $script = trim((string) ($u['script'] ?? ''));
            if ($script === '') {
                continue;
            }
            $out[] = ['kind' => 'ugc_script', 'content' => ['title' => trim((string) ($u['title'] ?? '')), 'script' => $script]];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function extractJson(string $raw): ?array
    {
        $raw = trim($raw);
        // Strip a ```json ... ``` fence if present.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m) === 1) {
            $raw = $m[1];
        } elseif (preg_match('/(\{.*\})/s', $raw, $m) === 1) {
            $raw = $m[1];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
