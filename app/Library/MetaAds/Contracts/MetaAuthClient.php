<?php

namespace App\Library\MetaAds\Contracts;

use App\DTO\MetaAds\MetaTokenGrant;
use App\DTO\MetaAds\MetaUserProfile;
use App\Exceptions\MetaAds\MetaProviderException;

/**
 * Meta Ads Module V1 contract §2/§3 — the OAuth half of the provider seam.
 * Nothing here is persisted: the connection manager is the only writer of the
 * encrypted token.
 */
interface MetaAuthClient
{
    /**
     * The Facebook OAuth dialog URL (`ads_read,ads_management`, response_type=code).
     */
    public function authorizationUrl(string $signedState): string;

    /**
     * Server-side code -> short-lived token -> `fb_exchange_token` long-lived
     * token. Neither token is ever returned to a browser.
     *
     * @throws MetaProviderException
     */
    public function exchangeCode(string $code): MetaTokenGrant;

    /**
     * `GET /me?fields=id,name`.
     *
     * @throws MetaProviderException
     */
    public function profile(string $accessToken): MetaUserProfile;

    /**
     * `GET /me/permissions`: the names whose status is `granted`.
     *
     * @return array<int, string>
     *
     * @throws MetaProviderException
     */
    public function grantedPermissions(string $accessToken): array;
}
