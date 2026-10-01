<?php

namespace App\Exceptions\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailFailureCategory;
use RuntimeException;

/**
 * A provider call failed. Carries ONLY the provider-neutral category plus an
 * operator-only short code; it never carries a response body, a token or a
 * provider message, so it is always safe to log.
 *
 * isRevocation() is true when the provider says the GRANT itself is dead
 * (invalid_grant, a 401 on a freshly minted token): the account manager then
 * moves the account to `revoked` and destroys the stored credential.
 *
 * isAmbiguous() is true when the request may already have reached the
 * provider (a read timeout / reset AFTER the send was dispatched). The
 * message might have been accepted, so the sender records `unconfirmed` and
 * never retries it blindly.
 */
final class BusinessEmailProviderException extends RuntimeException
{
    public function __construct(
        public readonly BusinessEmailFailureCategory $category,
        public readonly ?string $providerCode = null,
        private readonly bool $revocation = false,
        private readonly bool $ambiguous = false,
    ) {
        parent::__construct('business_email_provider:' . $category->value);
    }

    public function isRevocation(): bool
    {
        return $this->revocation;
    }

    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }

    public function userMessage(): string
    {
        return $this->category->customerMessage();
    }
}
