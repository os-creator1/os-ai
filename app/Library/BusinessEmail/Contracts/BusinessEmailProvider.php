<?php

namespace App\Library\BusinessEmail\Contracts;

use App\DTO\BusinessEmail\BusinessEmailOutbound;
use App\DTO\BusinessEmail\BusinessEmailProviderCapabilities;
use App\DTO\BusinessEmail\BusinessEmailProviderSendResult;
use App\DTO\BusinessEmail\BusinessEmailTokenGrant;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;

/**
 * The provider-neutral boundary. Everything Google- or Microsoft-shaped
 * (endpoints, MIME vs JSON, scope names, error payloads) stays inside an
 * implementation; nothing above this interface branches on provider type.
 *
 * Implementations MUST:
 *  - throw only BusinessEmailProviderException (never a raw HttpClient or
 *    provider exception, and never one carrying a response body or token);
 *  - never log a token, code or message body;
 *  - never be called inside a database transaction.
 */
interface BusinessEmailProvider
{
    public function provider(): BusinessEmailProviderType;

    public function capabilities(): BusinessEmailProviderCapabilities;

    public function authorizationUrl(string $state, bool $forceConsent): string;

    /**
     * Exchanges the one-time code and identifies the mailbox. Fails closed
     * (PermissionDenied) when the consent did not include the send permission.
     *
     * @throws BusinessEmailProviderException
     */
    public function exchangeAuthorizationCode(string $code): BusinessEmailTokenGrant;

    /** @throws BusinessEmailProviderException */
    public function exchangeRefreshToken(string $refreshToken): BusinessEmailTokenGrant;

    /**
     * Sends one plain-text email. Returning at all means the provider
     * ACCEPTED the request; that is not delivery.
     *
     * @throws BusinessEmailProviderException
     */
    public function send(string $accessToken, BusinessEmailOutbound $email): BusinessEmailProviderSendResult;

    /** Best-effort provider-side revocation; never throws. */
    public function revokeGrant(string $refreshToken): void;
}
