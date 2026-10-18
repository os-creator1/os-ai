<?php

namespace App\Library\MetaAds\Mutations;

use App\Enums\MetaAds\MetaAdsMutationTargetType;
use App\Enums\MetaAds\MetaAdsRequestedState;
use App\Enums\MetaAds\MetaOperationType;
use Closure;

/**
 * Internal to the mutation service: one fully-resolved, validated pause /
 * resume. Everything in it was derived INSIDE the Business's selected account.
 * `applyLocally` writes the confirmed status to the local row.
 */
final readonly class MetaAdsMutationPlan
{
    /**
     * @param  Closure(): void  $applyLocally
     */
    public function __construct(
        public MetaOperationType $operationType,
        public MetaAdsMutationTargetType $targetType,
        public int $targetLocalId,
        public string $externalId,
        public MetaAdsRequestedState $requestedState,
        public string $dedupeKey,
        public string $summary,
        public Closure $applyLocally,
    ) {
    }
}
