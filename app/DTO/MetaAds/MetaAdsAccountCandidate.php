<?php

namespace App\DTO\MetaAds;

use InvalidArgumentException;

/**
 * Meta Ads Module V1 contract §4 — one ad account the owner may choose,
 * derived SERVER-SIDE from `GET /me/adaccounts` on every render and
 * re-derived on the selecting POST.
 *
 * `adAccountId` is DIGITS ONLY (the `act_` prefix is stripped by the client);
 * the Graph path adds it back. Only an ACTIVE (account_status 1) account is
 * selectable.
 */
final readonly class MetaAdsAccountCandidate
{
    public const STATUS_ACTIVE = 1;

    public function __construct(
        public string $adAccountId,
        public ?string $name,
        public string $currencyCode,
        public string $timeZone,
        public int $accountStatus,
    ) {
        if (preg_match('/\A\d{1,20}\z/', $adAccountId) !== 1) {
            throw new InvalidArgumentException('Meta ad account id must be digits only.');
        }

        if (preg_match('/\A[A-Z]{3}\z/', $currencyCode) !== 1) {
            throw new InvalidArgumentException('Currency must be an ISO 4217 code.');
        }
    }

    public function isSelectable(): bool
    {
        return $this->accountStatus === self::STATUS_ACTIVE;
    }
}
