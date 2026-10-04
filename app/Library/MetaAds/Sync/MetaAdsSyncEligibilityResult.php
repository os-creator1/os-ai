<?php

namespace App\Library\MetaAds\Sync;

use App\Models\BusinessMetaConnection;
use App\Models\MetaAdsAccount;

/**
 * Allowed: carries the FRESH account and its own Business's active
 * connection. Refused: carries the safe reason and whatever was found (the
 * connection only when the refusal is token_expired, so the caller can retire
 * a dead token).
 */
final readonly class MetaAdsSyncEligibilityResult
{
    private function __construct(
        public ?MetaAdsAccount $account,
        public ?BusinessMetaConnection $connection,
        public ?string $reason,
    ) {
    }

    public static function allowed(MetaAdsAccount $account, BusinessMetaConnection $connection): self
    {
        return new self($account, $connection, null);
    }

    public static function refused(string $reason, ?MetaAdsAccount $account = null, ?BusinessMetaConnection $connection = null): self
    {
        return new self($account, $connection, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->reason === null && $this->account !== null && $this->connection !== null;
    }
}
