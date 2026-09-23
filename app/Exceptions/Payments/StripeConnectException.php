<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Implementation Contract 17 §12.D — every refusal the lane-B Stripe Connect
 * onboarding path can make, as one closed vocabulary.
 *
 * `reason` is machine-readable for tests and control flow; customerMessage()
 * is calm fixed copy. NOTHING here ever carries a secret key, a provider
 * payload, or another tenant's account id: an exception message is a log line
 * waiting to happen.
 */
final class StripeConnectException extends RuntimeException
{
    /** The actor is not an owner of this Business (§6.2 — owner-only). */
    public const NOT_OWNER = 'not_owner';

    /** The Business already holds a live (non-terminal) connection (§5.7). */
    public const ALREADY_CONNECTED = 'already_connected';

    /** No live connection exists to act on. */
    public const NOT_CONNECTED = 'not_connected';

    /** The connection named does not belong to this Business. */
    public const CONNECTION_NOT_FOUND = 'connection_not_found';

    /** The provider refused or was unreachable. */
    public const PROVIDER_FAILED = 'provider_failed';

    /** The platform is not configured for Connect at all. */
    public const NOT_CONFIGURED = 'not_configured';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notOwner(): self
    {
        return new self(self::NOT_OWNER, 'Only an owner may change the Stripe connection.');
    }

    public static function alreadyConnected(): self
    {
        return new self(self::ALREADY_CONNECTED, 'This business already has a live Stripe connection.');
    }

    public static function notConnected(): self
    {
        return new self(self::NOT_CONNECTED, 'This business has no live Stripe connection.');
    }

    public static function connectionNotFound(): self
    {
        return new self(self::CONNECTION_NOT_FOUND, 'The connection does not belong to this business.');
    }

    /**
     * The provider's own message is deliberately NOT propagated: it can carry
     * account identifiers and request details that do not belong in our logs
     * or in a customer-facing string.
     */
    public static function providerFailed(): self
    {
        return new self(self::PROVIDER_FAILED, 'Stripe could not complete the request.');
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'Stripe Connect is not configured on this platform.');
    }

    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::ALREADY_CONNECTED => 'This business is already connected to Stripe.',
            self::NOT_CONNECTED => 'Connect a Stripe account first.',
            self::PROVIDER_FAILED => 'Stripe could not complete that request. Please try again.',
            self::NOT_CONFIGURED => 'Online payments are not available on this platform yet.',
            default => 'That could not be completed.',
        };
    }
}
