<?php

declare(strict_types=1);

namespace App\Services\Llm;

/**
 * Provider-agnostic completion contract (D-016). Both drivers speak plain
 * HTTPS through Guzzle — no vendor SDK, so the §02 stack lock holds and a
 * provider switch is an .env change.
 */
interface LlmClient
{
    /** 'anthropic' | 'openai' — for logging and the report meta line. */
    public function provider(): string;

    /** The concrete model id in use — stored with every generated draft. */
    public function model(): string;

    /**
     * One completion. $messages is a provider-neutral list of
     * ['role' => 'user'|'assistant', 'content' => string] turns.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @throws LlmException on any transport or API error (message is safe to
     *         surface to the operator — never contains the key)
     */
    public function complete(string $system, array $messages, ?int $maxTokens = null): string;
}
