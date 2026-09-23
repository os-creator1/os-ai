<?php

namespace App\Exceptions\PlatformBilling;

use RuntimeException;

/**
 * Implementation Contract 21 §5 — every reason lane A refuses, as one closed
 * vocabulary of reason CODES.
 *
 * NO PROVIDER TEXT EVER REACHES THESE MESSAGES. A Stripe error message can
 * carry a customer id, an account id, a request id and request detail; §5
 * keeps all of it inside the gateway. `customerMessage()` is calm copy chosen
 * here, never something the provider said.
 *
 * NO SECRET EVER REACHES THEM EITHER — not the key, not a prefix, not a
 * length (§5.1).
 */
final class PlatformBillingException extends RuntimeException
{
    /** Lane A has no usable Stripe configuration, so it cannot take money. */
    public const NOT_CONFIGURED = 'not_configured';

    /** The provider call failed. The provider's own text is deliberately dropped. */
    public const PROVIDER_FAILED = 'provider_failed';

    /** A webhook signature did not verify against the raw body. */
    public const INVALID_SIGNATURE = 'invalid_signature';

    /** The provider reported a status this contract has no mapping for. */
    public const UNMAPPED_PROVIDER_STATUS = 'unmapped_provider_status';

    /** The tier is not currently sold to new customers (§7/§11). */
    public const TIER_NOT_AVAILABLE = 'tier_not_available';

    /** The tier has no usable commercial terms configured (price/currency/Price id). */
    public const TIER_NOT_PRICED = 'tier_not_priced';

    /** This Workspace already has a live lane-A subscription. */
    public const ALREADY_SUBSCRIBED = 'already_subscribed';

    /** There is no live lane-A subscription to act on. */
    public const NO_SUBSCRIPTION = 'no_subscription';

    /** The requested change is not meaningful from the current state. */
    public const CHANGE_NOT_PERMITTED = 'change_not_permitted';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason, match ($reason) {
            self::NOT_CONFIGURED => 'Payments are not available right now.',
            self::PROVIDER_FAILED => 'We could not reach the payment provider. Please try again.',
            self::INVALID_SIGNATURE => 'That request could not be verified.',
            self::UNMAPPED_PROVIDER_STATUS => 'The payment provider reported a state we do not recognise.',
            self::TIER_NOT_AVAILABLE => 'That plan is not available right now.',
            self::TIER_NOT_PRICED => 'That plan is not ready to be purchased yet.',
            self::ALREADY_SUBSCRIBED => 'This account already has a subscription.',
            self::NO_SUBSCRIPTION => 'This account does not have a subscription yet.',
            self::CHANGE_NOT_PERMITTED => 'That change cannot be made from the current plan state.',
            default => 'That could not be completed.',
        });
    }

    public function customerMessage(): string
    {
        return $this->getMessage();
    }
}
