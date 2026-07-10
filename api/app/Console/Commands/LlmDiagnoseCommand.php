<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Llm\LlmManager;
use Illuminate\Console\Command;

/**
 * `php artisan llm:diagnose` — the proof step after pasting an LLM key.
 * Mirrors google:diagnose / tiktok:diagnose: says exactly what is configured,
 * what is missing, and runs the cheapest possible live completion.
 */
class LlmDiagnoseCommand extends Command
{
    protected $signature = 'llm:diagnose';

    protected $description = 'Check LLM provider config + key and run a live completion ping';

    public function handle(LlmManager $llm): int
    {
        $provider = $llm->providerName();
        $source   = \App\Models\WorkspaceSetting::getValue('llm_provider') ? 'Settings UI' : 'HELM_LLM_PROVIDER env';
        $this->line("Provider: <info>{$provider}</info> (from {$source})");
        $this->line('Model: <info>' . config("llm.{$provider}.model") . '</info>'
            . ' (override via ' . ($provider === 'anthropic' ? 'HELM_LLM_MODEL_ANTHROPIC' : 'HELM_LLM_MODEL_OPENAI') . ')');
        $this->line('Key on file: ' . ($llm->enabled() ? '<info>yes</info> (DB or env)' : '<error>NO</error>'));

        $ping = $llm->ping();

        if ($ping['ok']) {
            $this->info("Live completion OK — {$ping['model']}, {$ping['ms']} ms.");
            $this->line('Narrative generation and chat are ready.');

            return self::SUCCESS;
        }

        $this->error($ping['message']);
        $this->line('Fix: Settings → Platform keys → AI / LLM, or set the env key and run `php artisan config:cache`.');

        return self::FAILURE;
    }
}
