<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\WorkspacePlanTier;

final readonly class WorkspacePlanCatalogSummary
{
    /**
     * @param array<int, string> $planFeatureKeys structural packaging (§10.2) — every feature_key this tier includes, regardless of availability.
     * @param array<string, bool> $featureAvailability feature_key => PlatformFeatureRegistry::isAvailable(), one entry per key in $planFeatureKeys.
     */
    public function __construct(
        public int $id,
        public WorkspacePlanTier $tier,
        public string $displayName,
        public ?string $price,
        public ?int $currencyId,
        public string $billingCycle,
        public int $businessSlotIncluded,
        public ?int $businessSlotMax,
        public bool $unlimitedBusinessSlots,
        public ?string $additionalBusinessSlotPriceRatio,
        public bool $isActive,
        public array $planFeatureKeys,
        public array $featureAvailability,
    ) {
    }
}
