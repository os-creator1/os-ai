<?php

namespace App\Library\GoogleAds\Sync;

use App\Models\BusinessGoogleConnection;
use App\Models\GoogleAdsAccount;

final readonly class GoogleAdsSyncEligibilityResult
{
    private function __construct(
        public ?GoogleAdsAccount $account,
        public ?BusinessGoogleConnection $connection,
        public ?string $reason,
    ) {
    }

    public static function allowed(GoogleAdsAccount $account, BusinessGoogleConnection $connection): self
    {
        return new self($account, $connection, null);
    }

    public static function refused(string $reason, ?GoogleAdsAccount $account = null): self
    {
        return new self($account, null, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->reason === null && $this->account !== null && $this->connection !== null;
    }
}
