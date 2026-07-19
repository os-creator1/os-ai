<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by OpportunityScorer when a score component it is given falls
 * outside its valid range (RFC-002 §34) — impact/urgency/effort/rank
 * outside [0,5], or a non-finite/out-of-[0,1] confidence. OpportunityScorer
 * enforces this itself rather than relying solely on its future caller.
 */
class InvalidScoreComponentException extends OpportunityException
{
}
