<?php

namespace App\Library\GoogleAds\Contracts;

use App\DTO\GoogleBusinessProfile\GoogleAccessGrant;
use App\DTO\GoogleBusinessProfile\GoogleTokenGrant;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;

/**
 * Google Ads Module V1 contract §2/§3 — the OAuth half of the provider
 * seam. The token DTOs are the product-neutral ones shared with the Business
 * Profile client (a grant is a grant); nothing here is persisted by the
 * client — GoogleAdsConnectionManager is the only writer of the encrypted
 * refresh token.
 */
interface GoogleAdsAuthClient
{
    /**
     * The Google consent URL for the adwords scope. Always
     * `access_type=offline` and `include_granted_scopes=false`; when
     * $forceConsent is true, `prompt=consent` (Google returns a refresh
     * token only on a fresh consent).
     */
    public function authorizationUrl(string $signedState, bool $forceConsent): string;

    /**
     * @throws GoogleAdsProviderException
     */
    public function exchangeAuthorizationCode(string $code): GoogleTokenGrant;

    /**
     * @throws GoogleAdsProviderException `invalid_grant` when Google has revoked the grant
     */
    public function exchangeRefreshToken(string $refreshToken): GoogleAccessGrant;
}
