<?php

namespace App\DTO\GoogleAds;

use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\GoogleAdsCustomerId;

/**
 * Google Ads Module V1 — everything a provider call needs about WHO it acts
 * as, and nothing else. The access token is derived per unit of work from
 * the encrypted refresh token and is NEVER persisted; this object lives for
 * one request/job and redacts the token from dumps.
 *
 * `customerId` is the operating customer; `loginCustomerId` is the manager
 * through which access is obtained (required whenever access is via a
 * manager — omitting it yields USER_PERMISSION_DENIED), or null for direct
 * access. Both are normalised to 10 digits here, so a hyphenated or
 * malformed id can never reach a URL or a header. A malformed id is a
 * `validation` failure with ZERO requests made.
 */
final readonly class GoogleAdsAccessContext
{
    public string $customerId;

    public ?string $loginCustomerId;

    public function __construct(
        public string $accessToken,
        string $customerId,
        ?string $loginCustomerId = null,
    ) {
        $customer = GoogleAdsCustomerId::normalize($customerId);
        $login = $loginCustomerId === null ? null : GoogleAdsCustomerId::normalize($loginCustomerId);

        if ($customer === null || ($loginCustomerId !== null && $login === null) || $accessToken === '') {
            throw GoogleAdsProviderException::validation();
        }

        $this->customerId = $customer;
        $this->loginCustomerId = $login;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '[redacted]',
            'customerId' => $this->customerId,
            'loginCustomerId' => (string) $this->loginCustomerId,
        ];
    }
}
