<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Services\Llm\BrandDataScope;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Custom reports via chat (feature spec §6, D-016 ratified 2026-07-10):
 * a natural-language question about ONE brand, answered from that brand's
 * aggregate data only.
 *
 * Privacy: the model receives the BrandDataScope payload — the same
 * aggregates-only boundary the narrative uses — for the period the operator
 * selected. It has no tools, no DB access, no other brands. Conversations
 * are NOT persisted server-side; history rides in from the client and dies
 * with the page.
 */
class BrandChatController extends Controller
{
    private const MAX_HISTORY_TURNS = 12;

    public function __construct(
        private readonly LlmManager $llm,
        private readonly BrandDataScope $scope,
    ) {}

    public function ask(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'message'           => ['required', 'string', 'max:2000'],
            'period'            => ['nullable', 'in:last7,last30,mtd'],
            'history'           => ['nullable', 'array', 'max:' . self::MAX_HISTORY_TURNS],
            'history.*.role'    => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:6000'],
        ]);

        if (! $this->llm->enabled()) {
            return response()->json([
                'message' => 'No LLM key on file. Add one at Settings → Platform keys → AI / LLM, then run php artisan llm:diagnose.',
            ], 422);
        }

        $filters = ReportFilters::fromArray(['period' => $data['period'] ?? 'last30', 'compare' => 'previous']);
        $payload = $this->scope->build($brand, $filters);

        $messages   = array_map(
            fn (array $turn) => ['role' => $turn['role'], 'content' => $turn['content']],
            array_slice($data['history'] ?? [], -self::MAX_HISTORY_TURNS),
        );
        $messages[] = ['role' => 'user', 'content' => $data['message']];

        try {
            $client = $this->llm->client();
            $reply  = $client->complete($this->systemPrompt($payload), $messages);
        } catch (LlmException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'reply'    => $reply,
            'provider' => $client->provider(),
            'model'    => $client->model(),
            'period'   => $filters->periodLabel(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function systemPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are the analytics assistant for a direct-to-consumer marketing agency, answering questions about ONE brand's performance.

You have exactly one source of truth — the DATA JSON below, which contains this brand's aggregate metrics for the selected period. Rules:
1. NEVER invent, estimate, or recompute figures. Quote only numbers present in the DATA JSON. Simple arithmetic on quoted figures (a share, a difference) is allowed only when you show both inputs.
2. Missing data is missing, not zero — say so when asked about something the data doesn't contain.
3. You cannot see other brands, customer-level data, or anything outside this JSON. If asked, say that data is out of scope.
4. Be concise and concrete. Use short paragraphs and "- " bullets. Name campaigns/products/countries from the data when relevant.

DATA JSON:
{$json}
PROMPT;
    }
}
