<?php

namespace App\Library\Usage;

use App\Exceptions\Usage\ProviderCardDeclinedException;
use App\Exceptions\Usage\WebhookSignatureVerificationException;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use Illuminate\Support\Str;

/**
 * M3 contract §8 — the sole test double for PaymentProviderGateway.
 * Deterministic, in-memory, never makes a real HTTP call. Bound only
 * inside the automated test suite via container override in each test's
 * own setUp() — never AppServiceProvider's default binding, so a local
 * preview always exercises the real StripePaymentProviderGateway.
 */
class FakePaymentProviderGateway implements PaymentProviderGateway
{
    /** @var array<string, string> providerPaymentIntentId => outcome ('succeeded'|'requires_action'|'declined') */
    public array $paymentIntentOutcomes = [];

    /** @var array<string, array{type: string, object_id: string, metadata: array<string, mixed>, amount: ?int, currency: ?string, customer: ?string}> */
    public array $pendingWebhookEvents = [];

    public bool $nextWebhookSignatureIsValid = true;

    private array $createdCustomers = [];

    private array $createdPaymentMethods = [];

    public function createOrRetrieveCustomer(?string $existingProviderCustomerId, string $idempotencyKey): ProviderCustomerResult
    {
        if ($existingProviderCustomerId !== null) {
            return new ProviderCustomerResult($existingProviderCustomerId, false);
        }

        $id = 'cus_fake_'.Str::random(16);
        $this->createdCustomers[$id] = true;

        return new ProviderCustomerResult($id, true);
    }

    public function createSetupIntent(string $providerCustomerId, string $idempotencyKey): SetupIntentResult
    {
        $id = 'seti_fake_'.Str::random(16);

        return new SetupIntentResult($id, 'seti_fake_secret_'.Str::random(8), 'requires_payment_method', null);
    }

    public function retrieveSetupIntent(string $providerSetupIntentId): SetupIntentResult
    {
        // Deterministic pairing: every fake SetupIntent resolves to a
        // fake PaymentMethod derived from its own id, so tests never need
        // to separately track the association.
        $paymentMethodId = 'pm_fake_'.substr($providerSetupIntentId, strlen('seti_fake_'));

        return new SetupIntentResult($providerSetupIntentId, null, 'succeeded', $paymentMethodId);
    }

    public function retrievePaymentMethod(string $providerPaymentMethodId): PaymentMethodResult
    {
        return $this->createdPaymentMethods[$providerPaymentMethodId] ?? new PaymentMethodResult(
            $providerPaymentMethodId,
            'cus_fake_unknown',
            'card',
            'visa',
            '4242',
            12,
            2030,
        );
    }

    public function detachPaymentMethod(string $providerPaymentMethodId): void
    {
        unset($this->createdPaymentMethods[$providerPaymentMethodId]);
    }

    public function createOffSessionPaymentIntent(
        string $providerCustomerId,
        string $providerPaymentMethodId,
        int $amountMinorUnits,
        string $currencyCode,
        string $idempotencyKey,
        array $metadata,
    ): PaymentIntentResult {
        $id = 'pi_fake_'.Str::random(16);
        // '*' is a test-only wildcard fallback — the manager's own
        // idempotency key is a fresh UUID per attempt and cannot be
        // predicted from outside, so a test that must force a decline for
        // whichever key is actually generated sets $paymentIntentOutcomes['*'].
        $outcome = $this->paymentIntentOutcomes[$idempotencyKey] ?? $this->paymentIntentOutcomes['*'] ?? 'succeeded';

        if ($outcome === 'declined') {
            throw new ProviderCardDeclinedException('generic_decline');
        }

        $status = $outcome === 'requires_action' ? 'requires_action' : 'succeeded';

        return new PaymentIntentResult($id, $status, $status === 'requires_action' ? 'pi_fake_secret_'.Str::random(8) : null, $amountMinorUnits, $currencyCode);
    }

    public function retrievePaymentIntent(string $providerPaymentIntentId): PaymentIntentResult
    {
        return new PaymentIntentResult($providerPaymentIntentId, 'succeeded', null, 0, 'USD');
    }

    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret): WebhookVerificationResult
    {
        if (! $this->nextWebhookSignatureIsValid) {
            throw new WebhookSignatureVerificationException();
        }

        $decoded = json_decode($rawBody, true);

        return new WebhookVerificationResult(
            (string) ($decoded['id'] ?? 'evt_fake_'.Str::random(16)),
            (string) ($decoded['type'] ?? 'payment_intent.succeeded'),
            (string) ($decoded['data']['object']['id'] ?? ''),
            $rawBody,
            (array) ($decoded['data']['object']['metadata'] ?? []),
            $decoded['data']['object']['amount'] ?? null,
            isset($decoded['data']['object']['currency']) ? strtoupper($decoded['data']['object']['currency']) : null,
            $decoded['data']['object']['customer'] ?? null,
        );
    }

    /**
     * Test helper — registers a deterministic PaymentMethodResult for a
     * given provider payment method id, so SetupIntent confirmation tests
     * can assert against known brand/last-four/expiry values.
     */
    public function registerPaymentMethod(PaymentMethodResult $result): void
    {
        $this->createdPaymentMethods[$result->providerPaymentMethodId] = $result;
    }
}
