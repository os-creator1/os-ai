<?php

namespace App\Library\Ai\Enums;

/**
 * Contract §11.3 — the three states a customer's included AI can be in.
 *
 * A state, never a number: the customer surface that renders these shows
 * no tokens, no amounts and no percentage, so nothing downstream of this
 * enum ever needs one.
 */
enum AiUsageState: string
{
    case Normal = 'normal';
    case NearingLimit = 'nearing_limit';
    case LimitReached = 'limit_reached';
}
