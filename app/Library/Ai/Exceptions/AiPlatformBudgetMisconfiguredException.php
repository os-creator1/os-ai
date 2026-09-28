<?php

namespace App\Library\Ai\Exceptions;

use RuntimeException;

/**
 * Contract §5.7a D — "fail closed on invalid configuration", the
 * interactive-share half. An absent, non-numeric or out-of-range
 * `config('ai.platform.interactive_share_bps')` (must be `0…10000`) is a
 * configuration failure, raised here rather than silently clamped or
 * allowed to become an unbounded interactive lane. Carries no amount or
 * internal detail beyond the one fact an operator needs: which key is
 * wrong.
 */
final class AiPlatformBudgetMisconfiguredException extends RuntimeException
{
    public static function invalidInteractiveShareBps(): self
    {
        return new self("config('ai.platform.interactive_share_bps') must be an integer between 0 and 10000.");
    }
}
