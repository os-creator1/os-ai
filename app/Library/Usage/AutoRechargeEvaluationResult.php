<?php

namespace App\Library\Usage;

/**
 * M3 contract §15 — EvaluateBusinessAutoRecharge's own outcome shape,
 * useful for tests asserting exactly what the job decided without
 * inspecting database side effects alone.
 */
final readonly class AutoRechargeEvaluationResult
{
    public function __construct(
        public bool $triggered,
        public ?int $fundingAttemptId,
        public ?string $skippedReason,
    ) {
    }
}
