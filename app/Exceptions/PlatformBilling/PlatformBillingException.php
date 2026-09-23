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

    /**
     * §11 — the supplied Stripe Price could not be retrieved from the PLATFORM
     * account. Either it does not exist, or it belongs to a connected account
     * (lane B or lane C), which is not reachable with the platform key alone.
     */
    public const PRICE_NOT_RETRIEVABLE = 'price_not_retrievable';

    /** §11 — the Price exists but does not represent the submitted terms. */
    public const PRICE_TERMS_MISMATCH = 'price_terms_mismatch';

    /** §7 — the previous checkout session was already completed. */
    public const CHECKOUT_ALREADY_COMPLETED = 'checkout_already_completed';

    /** §7 — too many concurrent requests replaced the attempt under us. */
    public const CHECKOUT_CONTENDED = 'checkout_contended';

    /**
     * §10 — a durable plan-change operation for a DIFFERENT target is still
     * in flight. Replacing it would leave the provider on one Price and the
     * local record converging towards another.
     */
    public const CHANGE_IN_PROGRESS = 'change_in_progress';

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
            self::PRICE_NOT_RETRIEVABLE => 'That Stripe Price could not be found on this platform\'s own Stripe account.',
            self::PRICE_TERMS_MISMATCH => 'That Stripe Price does not match the plan terms you entered.',
            self::CHECKOUT_ALREADY_COMPLETED => 'That checkout has already been paid.',
            self::CHECKOUT_CONTENDED => 'Something else changed this checkout. Please try again.',
            self::CHANGE_IN_PROGRESS => 'A plan change is still being confirmed. Please try again shortly.',
            default => 'That could not be completed.',
        });
    }

    public function customerMessage(): string
    {
        return $this->getMessage();
    }
}
