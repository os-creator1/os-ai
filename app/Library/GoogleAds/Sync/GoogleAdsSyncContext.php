<?php

namespace App\Library\GoogleAds\Sync;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\Models\GoogleAdsAccount;
use Carbon\CarbonImmutable;

/**
 * Everything one sync run's stages share. Dates are `Y-m-d` in the ACCOUNT
 * time zone (the provider's reporting calendar); `syncedAt` is the single
 * instant stamped on every row this run touches, which is also how a complete
 * listing finds the rows it did NOT return.
 */
final readonly class GoogleAdsSyncContext
{
    public function __construct(
        public GoogleAdsAccount $account,
        public GoogleAdsAccessContext $access,
        public CarbonImmutable $syncedAt,
        public string $metricsStart,
        public string $searchTermsStart,
        public string $endDate,
    ) {
    }

    public function businessId(): int
    {
        return (int) $this->account->business_id;
    }

    public function accountId(): int
    {
        return (int) $this->account->id;
    }

    /** The stored form of `syncedAt` (the app time zone, second precision). */
    public function stamp(): string
    {
        return $this->syncedAt->format('Y-m-d H:i:s');
    }
}
