<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiUsageState;

/**
 * Contract §11.3 — one Business's row in an Agency's AI usage summary: its
 * name and its state against its own per-Business allowance. No figure.
 */
final readonly class AiUsageBusinessRow
{
    public function __construct(
        public string $businessUid,
        public string $businessName,
        public AiUsageState $state,
        public string $label,
    ) {
    }
}
