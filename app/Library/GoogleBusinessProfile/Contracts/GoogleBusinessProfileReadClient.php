<?php

namespace App\Library\GoogleBusinessProfile\Contracts;

use App\DTO\GoogleBusinessProfile\GoogleAccessGrant;
use App\DTO\GoogleBusinessProfile\GoogleAccountSummary;
use App\DTO\GoogleBusinessProfile\GoogleLocationCandidate;
use App\DTO\GoogleBusinessProfile\GoogleLocationProfile;
use App\DTO\GoogleBusinessProfile\GoogleTokenGrant;
use App\DTO\GoogleBusinessProfile\GoogleVoiceOfMerchantState;

/**
 * GBP Slice A contract §14.1 — the ENTIRE provider surface, and the whole
 * of the read-only guarantee.
 *
 * Google exposes exactly one Business Profile OAuth scope,
 * https://www.googleapis.com/auth/business.manage, cited identically by
 * every read AND write method; there is no read-only scope. The read-only
 * property of Slice A therefore CANNOT be delegated to OAuth (contract
 * §9.2, correction A-3). It is enforced structurally, here:
 *
 *   - This interface declares exactly seven methods and no others.
 *   - No implementation may declare a patch/update/create/delete/set/
 *     publish/reply/upload/verify method, or any method that issues an
 *     HTTP verb other than GET against a googleapis.com host. The two
 *     POSTs the real client makes are the OAuth token endpoints, which are
 *     not Business Profile resources.
 *   - There is deliberately NO generic request()/call()/send() method: a
 *     caller cannot reach an arbitrary Google endpoint through this seam
 *     even if it wanted to.
 *   - Dormant "for later" write methods are forbidden (contract §14.2).
 *
 * Tests\Feature\GoogleBusinessProfile\GoogleBusinessProfileReadOnlyTest
 * (T-PROV-1) asserts all of the above by reflection over this interface
 * and both implementations, so a future "harmless" addition fails the
 * suite rather than silently widening Slice A.
 *
 * Every method takes an access token as its first argument rather than
 * resolving one internally, because Slice A never persists an access token
 * (contract §9.7) — the caller derives one per unit of work.
 */
interface GoogleBusinessProfileReadClient
{
    /**
     * Builds the Google authorization URL. Never performs a request.
     */
    public function authorizationUrl(string $signedState, bool $forceConsent): string;

    public function exchangeAuthorizationCode(string $code): GoogleTokenGrant;

    public function exchangeRefreshToken(string $refreshToken): GoogleAccessGrant;

    /**
     * Account Management v1 `accounts.list`. Paged internally; returns
     * every account the grant can reach.
     *
     * @return array<int, GoogleAccountSummary>
     */
    public function listAccounts(string $accessToken): array;

    /**
     * Business Information v1 `accounts.locations.list`. `readMask` is
     * REQUIRED by Google and is supplied by the caller
     * (GoogleBusinessProfileReadMask) — never defaulted here, so the
     * privacy control in contract §23.2 cannot be bypassed by omission.
     *
     * @param  array<int, string>  $readMask
     * @return array<int, GoogleLocationCandidate>
     */
    public function listLocations(string $accessToken, string $accountResourceName, array $readMask, bool $addressPermitted): array;

    /**
     * Business Information v1 `locations.get`.
     *
     * @param  array<int, string>  $readMask
     */
    public function getLocation(string $accessToken, string $locationResourceName, array $readMask, bool $addressPermitted): GoogleLocationProfile;

    /**
     * Verifications v1 `locations.getVoiceOfMerchantState`. Read-only
     * state retrieval; it never starts, completes or otherwise mutates a
     * verification.
     */
    public function getVoiceOfMerchantState(string $accessToken, string $locationResourceName): GoogleVoiceOfMerchantState;
}
