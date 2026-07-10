<?php

declare(strict_types=1);

namespace App\Services\Llm;

use RuntimeException;

/**
 * Any LLM transport/API failure. Always safe to show the operator: drivers
 * strip the API key from messages before throwing, and the narrative/chat
 * endpoints surface this text so a bad key or model id is diagnosable from
 * the UI (mirrors the platform clients' error contract).
 */
final class LlmException extends RuntimeException
{
}
