<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiModelRoute;

/**
 * The resolved route, its price-version snapshot, and the estimated
 * upper-bound cost for one call (§10.1 step 3). `priceVersion` is
 * snapshotted here and carried onto the ledger row, so a later config
 * price change never retroactively recomputes an already-committed
 * entry (§10.1 step 6, "do not retroactively recompute").
 */
final readonly class AiUsageCostEstimate
{
    public function __construct(
        public AiModelRoute $route,
        public string $provider,
        public string $model,
        public int $priceVersion,
        public int $costMicrousd,
        public int $maxRequestCostMicrousd,
    ) {
    }
}
