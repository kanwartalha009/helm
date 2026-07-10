<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LLM layer (feature spec §6 intelligence layer; D-016, ratified 2026-07-10)
|--------------------------------------------------------------------------
| Provider-agnostic: one LlmClient interface, two Guzzle drivers (no SDK —
| the stack lock holds). The API key lives in platform_credentials
| (Settings → Platform keys → "AI / LLM") with an env fallback, exactly
| like every ad-platform credential.
|
| Turn it on: paste the key for your chosen provider, set HELM_LLM_PROVIDER
| if not anthropic, run `php artisan llm:diagnose` to prove the wiring, done.
|
| Privacy stance (D-016): only AGGREGATES leave Helm — see
| App\Services\Llm\BrandDataScope, the single payload builder. No customer
| rows exist in Helm's schema, and the scope never sends raw DB rows.
*/

return [
    // 'anthropic' | 'openai' — which driver serves narrative + chat.
    'provider' => env('HELM_LLM_PROVIDER', 'anthropic'),

    'anthropic' => [
        // Override when Anthropic ships newer models — no deploy needed
        // beyond the .env change + config:cache.
        'model'   => env('HELM_LLM_MODEL_ANTHROPIC', 'claude-sonnet-4-20250514'),
        'version' => env('HELM_ANTHROPIC_API_VERSION', '2023-06-01'),
        'base'    => 'https://api.anthropic.com',
    ],

    'openai' => [
        'model' => env('HELM_LLM_MODEL_OPENAI', 'gpt-4o'),
        'base'  => 'https://api.openai.com',
    ],

    // Generation budget. Narrative asks for 4 compact blocks; chat replies
    // are conversational. Both fit comfortably in this cap.
    'max_tokens'  => (int) env('HELM_LLM_MAX_TOKENS', 2000),
    'temperature' => (float) env('HELM_LLM_TEMPERATURE', 0.4),

    // Seconds. LLM calls are user-triggered (generate button / chat), never
    // inside sync jobs, so a generous timeout is safe.
    'timeout' => (int) env('HELM_LLM_TIMEOUT', 90),
];
