<?php

namespace App\Library\Usage\Contracts;

use App\Library\Usage\CheckoutSessionResult;
use App\Library\Usage\PaymentIntentResult;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\ProviderCustomerResult;
use App\Library\Usage\SetupIntentResult;
use App\Library\Usage\WebhookVerificationResult;

/**
 * M3 contract §8 — the entire Stripe boundary. No manager, controller, or
 * job ever references a Stripe\* SDK class directly; every call goes
 * through this interface and returns a normalized DTO, never a raw
 * provider object. Every method that performs an outbound provider call
 * must be invoked with no open database transaction/lock held by the
 * caller (M3 contract §8/§16).
 */
interface PaymentProviderGateway
{
    public function createOrRetrieveCustomer(?string $existingProviderCustomerId, string $idempotencyKey): ProviderCustomerResult;

    public function createSetupIntent(string $providerCustomerId, string $idempotencyKey): SetupIntentResult;

    public function retrieveSetupIntent(string $providerSetupIntentId): SetupIntentResult;

    public function retrievePaymentMethod(string $providerPaymentMethodId): PaymentMethodResult;

    public function detachPaymentMethod(string $providerPaymentMethodId): void;

    public function createOffSessionPaymentIntent(
        string $providerCustomerId,
        string $providerPaymentMethodId,
        int $amountMinorUnits,
        string $currencyCode,
        string $idempotencyKey,
        array $metadata,
    ): PaymentIntentResult;

    public function retrievePaymentIntent(string $providerPaymentIntentId): PaymentIntentResult;

    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret): WebhookVerificationResult;

    /**
     * M4 contract §15a — the customer-present initial additional-slot
     * purchase's own Checkout Session create. $lineItemName is the
     * already-defined feature's own truthful label ('Additional Business
     * slots'), never a new commercial product or price. Fails closed
     * (throws) if the newly-created Session unexpectedly carries no url.
     */
    /**
     * RFC-005 Funding Provider-Flow Correction Contract §8.A/§8.B —
     * $setupFutureUsageOffSession controls whether the outbound Session
     * requests setup_future_usage: 'off_session' (true, for the
     * additional-slot agreement's own recurring-renewal design) or omits
     * it entirely (false, the default — a one-time Checkout Session that
     * never establishes future off-session authority, per ManualTopUp/
     * AddonPurchase).
     */
    public function createCheckoutSession(
        string $providerCustomerId,
        int $amountMinorUnits,
        string $currencyCode,
        string $lineItemName,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata,
        bool $setupFutureUsageOffSession = false,
    ): CheckoutSessionResult;

    /**
     * M4 contract §15a — never trusts the browser redirect alone. May
     * legitimately return redirectUrl: null for an already-complete
     * Session.
     */
    public function retrieveCheckoutSession(string $providerCheckoutSessionId): CheckoutSessionResult;

    /**
     * M4 contract §15a/§23 — the exact, narrow addition that makes an M4
     * renewal retry a genuine second provider-side attempt: re-confirms
     * an existing PaymentIntent against a (possibly new) payment method,
     * never a bare re-retrieval.
     */
    public function confirmPaymentIntent(
        string $providerPaymentIntentId,
        string $providerPaymentMethodId,
        string $idempotencyKey,
    ): PaymentIntentResult;
}
