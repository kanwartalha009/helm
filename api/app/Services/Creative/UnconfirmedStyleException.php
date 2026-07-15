<?php

declare(strict_types=1);

namespace App\Services\Creative;

use RuntimeException;

/**
 * GO-5.1 refusal (master plan §8 + §7.4): creative generation REFUSES when the
 * brand has no CONFIRMED style/moodboard. An auto-extracted palette and an
 * LLM-drafted tone are suggestions until an operator signs off (GO-4.4's
 * confirm gate) — generating ungrounded creative is exactly the "generic
 * output" failure the doctrine exists to prevent. The controller maps this to a
 * 422 with a clear "confirm the style first" message.
 */
final class UnconfirmedStyleException extends RuntimeException
{
    public function __construct(string $message = 'Confirm this brand’s moodboard/style before generating creative.')
    {
        parent::__construct($message);
    }
}
