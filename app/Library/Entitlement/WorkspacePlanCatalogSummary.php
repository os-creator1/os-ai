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
        /**
         * Physical-location capacity per Business (RFC-004 §33): the same
         * catalog columns EntitlementManager::decideLocationSlotCapacity()
         * reads, exposed here so a plan surface can state a tier's location
         * allowance without re-deriving it.
         */
        public int $locationSlotIncluded,
        public ?int $locationSlotMax,
        public bool $unlimitedLocationSlots,
        public ?string $additionalLocationSlotPriceRatio,
        public bool $isActive,
        public array $planFeatureKeys,
        public array $featureAvailability,
    ) {
    }
}
