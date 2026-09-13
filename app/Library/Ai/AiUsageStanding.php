<?php

namespace App\Library\Ai;

/**
 * Contract §11.3 — the three facts a usage state is decided from, for one
 * scope (a Workspace or one of its Businesses) in one period.
 *
 * Internal only. The figures exist so AiUsagePresenter can decide a state;
 * they are never handed to a customer view.
 */
final readonly class AiUsageStanding
{
    public function __construct(
        public int $committedMicrousd,
        public int $capMicrousd,
        public bool $refusedForBudgetThisPeriod,
    ) {
    }
}
