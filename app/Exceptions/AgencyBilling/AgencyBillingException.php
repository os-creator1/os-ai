<?php

namespace App\Exceptions\AgencyBilling;

use RuntimeException;

/**
 * Lane C §C4 — every reason lane C refuses, as one closed vocabulary of reason
 * CODES.
 *
 * NO PROVIDER TEXT EVER REACHES THESE MESSAGES. A Stripe error can carry a
 * customer id, a connected account id, a request id and request detail; the
 * gateway keeps all of it. `customerMessage()` is calm copy chosen here, never
 * something the provider said.
 *
 * NO SECRET EVER REACHES THEM EITHER — not a key, not a prefix, not a length.
 * A connected ACCOUNT id is an identifier rather than a credential, but it
 * still names one Agency's merchant account, so it does not appear here.
 */
final class AgencyBillingException extends RuntimeException
{
    // ---- configuration and connection -------------------------------------

    /** Lane C has no usable Stripe configuration at all. */
    public const NOT_CONFIGURED = 'not_configured';

    /** The provider call failed. The provider's own text is deliberately dropped. */
    public const PROVIDER_FAILED = 'provider_failed';

    /** A webhook signature did not verify against the raw body. */
    public const INVALID_SIGNATURE = 'invalid_signature';

    /** The provider reported a status this contract has no mapping for. */
    public const UNMAPPED_PROVIDER_STATUS = 'unmapped_provider_status';

    /** This Agency has not connected a Stripe account to be paid into. */
    public const NO_CONNECTION = 'no_connection';

    /** The connection exists but the provider will not let it take charges yet. */
    public const CONNECTION_NOT_READY = 'connection_not_ready';

    /** This Agency already has a current (non-disconnected) connection. */
    public const ALREADY_CONNECTED = 'already_connected';

    // ---- plans ------------------------------------------------------------

    /** The plan is not published, or has no usable commercial terms. */
    public const PLAN_NOT_SELLABLE = 'plan_not_sellable';

    /** An Agency may resell capability, never the Agency tier itself (§C3.2). */
    public const TIER_NOT_RESELLABLE = 'tier_not_resellable';

    /**
     * §C5.2 — the Price could not be retrieved from THIS Agency's connected
     * account. Either it does not exist, or it belongs to the platform or to a
     * different Agency, neither of which is reachable with this account's
     * header.
     */
    public const PRICE_NOT_RETRIEVABLE = 'price_not_retrievable';

    /** §C5.2 — the Price exists but does not represent the submitted terms. */
    public const PRICE_TERMS_MISMATCH = 'price_terms_mismatch';

    // ---- relationship and eligibility (§C6.1) -----------------------------

    /** There is no active managing relationship between this Agency and client. */
    public const NO_ACTIVE_RELATIONSHIP = 'no_active_relationship';

    /** The client pays the platform directly; two paid authorities is not allowed. */
    public const CLIENT_HAS_PLATFORM_SUBSCRIPTION = 'client_has_platform_subscription';

    /** The Platform Owner is deliberately carrying this account at no charge. */
    public const CLIENT_IS_COMPLIMENTARY = 'client_is_complimentary';

    /** This client already has a live lane-C subscription. */
    public const CLIENT_ALREADY_SUBSCRIBED = 'client_already_subscribed';

    /** There is no offer or subscription to act on. */
    public const NO_SUBSCRIPTION = 'no_subscription';

    /** The requested change is not meaningful from the current state. */
    public const CHANGE_NOT_PERMITTED = 'change_not_permitted';

    /** A durable plan-change operation for a different target is still in flight. */
    public const CHANGE_IN_PROGRESS = 'change_in_progress';

    // ---- consent (§C6) ----------------------------------------------------

    /**
     * Only the CLIENT may authorize the client's payment. The Agency, the
     * Platform Owner and a View As actor are all refused here, by design.
     */
    public const CONSENT_NOT_AUTHORIZED = 'consent_not_authorized';

    /** A View As session is active; it can never fabricate financial consent. */
    public const CONSENT_THROUGH_VIEW_AS = 'consent_through_view_as';

    // ---- checkout ---------------------------------------------------------

    /** §C7 — the previous checkout session was already completed. */
    public const CHECKOUT_ALREADY_COMPLETED = 'checkout_already_completed';

    /** §C7 — too many concurrent requests replaced the attempt under us. */
    public const CHECKOUT_CONTENDED = 'checkout_contended';

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
            self::NO_CONNECTION => 'This agency has not connected a Stripe account yet.',
            self::CONNECTION_NOT_READY => 'This agency\'s Stripe account cannot take payments yet.',
            self::ALREADY_CONNECTED => 'This agency already has a connected Stripe account.',
            self::PLAN_NOT_SELLABLE => 'That plan is not ready to be sold yet.',
            self::TIER_NOT_RESELLABLE => 'That plan level cannot be resold.',
            self::PRICE_NOT_RETRIEVABLE => 'That Stripe price could not be found on this agency\'s own Stripe account.',
            self::PRICE_TERMS_MISMATCH => 'That Stripe price does not match the plan terms you entered.',
            self::NO_ACTIVE_RELATIONSHIP => 'This agency does not currently manage that client.',
            self::CLIENT_HAS_PLATFORM_SUBSCRIPTION => 'That client already subscribes directly and would be billed twice. They need to cancel their own subscription first.',
            self::CLIENT_IS_COMPLIMENTARY => 'That account is provided at no charge and cannot be billed by an agency.',
            self::CLIENT_ALREADY_SUBSCRIBED => 'That client already has a subscription with this agency.',
            self::NO_SUBSCRIPTION => 'There is no subscription to act on.',
            self::CHANGE_NOT_PERMITTED => 'That change cannot be made from the current state.',
            self::CHANGE_IN_PROGRESS => 'A plan change is still being confirmed. Please try again shortly.',
            self::CONSENT_NOT_AUTHORIZED => 'Only the account owner can authorise this payment.',
            self::CONSENT_THROUGH_VIEW_AS => 'Payments cannot be authorised while viewing an account as someone else.',
            self::CHECKOUT_ALREADY_COMPLETED => 'That checkout has already been paid.',
            self::CHECKOUT_CONTENDED => 'Something else changed this checkout. Please try again.',
            default => 'That could not be completed.',
        });
    }

    public function customerMessage(): string
    {
        return $this->getMessage();
    }
}
