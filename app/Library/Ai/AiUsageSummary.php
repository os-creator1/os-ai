<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiUsageState;

/**
 * Contract §11.3 — everything Settings -> Billing -> AI usage renders, and
 * nothing more: a state, its sentence, the trial line when the canonical
 * policy is trial, and — for an Agency owner or Agency-wide admin only — one
 * row per Business.
 *
 * Deliberately carries no figure of any kind. A view handed this object has
 * no token count, amount or percentage it could show by mistake.
 */
final readonly class AiUsageSummary
{
    /**
     * @param  array<int, AiUsageBusinessRow>  $businessRows
     */
    public function __construct(
        public AiUsageState $state,
        public string $sentence,
        public ?string $trialLine,
        public array $businessRows,
    ) {
    }
}
