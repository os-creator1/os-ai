<?php

namespace App\DTO\GoogleAds;

/**
 * Google Ads Module V1 contract §3 — one account the owner may choose,
 * produced SERVER-SIDE by GoogleAdsAccountDirectory on every render and
 * re-derived on the selecting POST. The POST carries only a customer id;
 * `loginCustomerId`, currency and time zone always come from here.
 *
 * Managers are listed but never selectable.
 */
final readonly class GoogleAdsAccountCandidate
{
    public function __construct(
        public string $customerId,
        public ?string $name,
        public string $currencyCode,
        public string $timeZone,
        public ?string $loginCustomerId,
        public bool $isManager,
        public bool $isTest,
    ) {
    }

    public function isSelectable(): bool
    {
        return ! $this->isManager;
    }
}
