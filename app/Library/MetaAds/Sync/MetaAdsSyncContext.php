<?php

namespace App\Library\MetaAds\Sync;

use App\Models\MetaAdsAccount;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * Everything one sync run's stages share. Dates are `Y-m-d` in the ACCOUNT
 * time zone (Meta's reporting calendar); `syncedAt` is the single instant
 * stamped on every row this run touches, which is also how a complete
 * listing finds the rows it did NOT return.
 *
 * The access token lives ONLY here, for the lifetime of one run: it is read
 * through accessToken() by the stage that is about to call Meta, is hidden
 * from var_dump / print_r, and is never copied onto a result, a ledger row or
 * a log line.
 */
final class MetaAdsSyncContext
{
    public function __construct(
        public readonly MetaAdsAccount $account,
        #[SensitiveParameter] private readonly string $accessToken,
        public readonly CarbonImmutable $syncedAt,
        public readonly string $metricsStart,
        public readonly string $metricsEnd,
        public readonly string $frequencyStart,
        public readonly string $frequencyEnd,
    ) {
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function adAccountId(): string
    {
        return (string) $this->account->ad_account_id;
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

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'account' => $this->accountId(),
            'accessToken' => '[redacted]',
            'metricsStart' => $this->metricsStart,
            'metricsEnd' => $this->metricsEnd,
        ];
    }
}
