<?php

namespace App\Library\Usage\Contracts;

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
}
