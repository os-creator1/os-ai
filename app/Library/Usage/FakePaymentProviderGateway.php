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

    /**
     * M4 contract §21 (Correction Round 2 §B) — idempotencyKey (or '*') =>
     * true forces a 'declined' createOffSessionPaymentIntent() outcome to
     * omit the PaymentIntent id, reproducing an incomplete real Stripe
     * error response for the fail-closed null-evidence test.
     *
     * @var array<string, bool>
     */
    public array $declineOmitsPaymentIntentId = [];

    /** @var array<string, array{type: string, object_id: string, metadata: array<string, mixed>, amount: ?int, currency: ?string, customer: ?string}> */
    public array $pendingWebhookEvents = [];

    public bool $nextWebhookSignatureIsValid = true;

    /**
     * M4 contract §15a — keyed by the idempotency key supplied to
     * createCheckoutSession(); configures what retrieveCheckoutSession()
     * later returns for that exact session ('*' is the same test-only
     * wildcard fallback createOffSessionPaymentIntent() already
     * establishes). Unconfigured defaults to a fully paid, complete
     * Session — the happy path most tests exercise.
     *
     * @var array<string, array{status?: string, paymentStatus?: string, providerPaymentMethodId?: ?string}>
     */
    public array $checkoutSessionOutcomes = [];

    /**
     * M4 contract §15a/§23 — keyed by the idempotency key
     * confirmPaymentIntent() itself receives; configures that call's own
     * outcome, identically to $paymentIntentOutcomes for the initial
     * off-session create.
     *
     * @var array<string, string>
     */
    public array $confirmPaymentIntentOutcomes = [];

    /**
     * M4 contract §3 item 7k — records every confirmPaymentIntent() call
     * received (provider PaymentIntent id, payment method, idempotency
     * key), so a test can assert a genuine second provider-side attempt
     * actually occurred, never merely that the existing PaymentIntent was
     * re-retrieved.
     *
     * @var array<int, array{providerPaymentIntentId: string, providerPaymentMethodId: string, idempotencyKey: string}>
     */
    public array $confirmPaymentIntentCalls = [];

    private array $createdCustomers = [];

    private array $createdPaymentMethods = [];

    /** @var array<string, array{idempotencyKey: string, amountMinorUnits: int, currencyCode: string, providerCustomerId: string, providerPaymentIntentId: string}> */
    private array $createdCheckoutSessions = [];

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §8.B — records
     * every createCheckoutSession() call received, including the
     * setupFutureUsageOffSession flag, so a test can assert which value a
     * specific caller actually passed (mirrors confirmPaymentIntentCalls,
     * M4 contract §3 item 7k).
     *
     * @var array<int, array{providerCustomerId: string, amountMinorUnits: int, currencyCode: string, lineItemName: string, successUrl: string, cancelUrl: string, idempotencyKey: string, metadata: array<string, mixed>, setupFutureUsageOffSession: bool}>
     */
    public array $createCheckoutSessionCalls = [];

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §16.A — a test-only
     * explicit Checkout Session retrieval registration, keyed by
     * providerCheckoutSessionId, checked first by retrieveCheckoutSession()
     * before its own existing createdCheckoutSessions-keyed lookup and
     * unknown-Session fallback. Needed by ConcurrentTopUpConcurrencyTest's
     * independently-booted child processes, which share no in-memory state
     * with the parent process that actually created the Session.
     *
     * @var array<string, CheckoutSessionResult>
     */
    private array $registeredCheckoutSessionResults = [];

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
            // M4 contract §21 (Correction Round 2 §B) — reproduces the real
            // gateway's own behavior of carrying the declined PaymentIntent's
            // real id on the exception. A test proving the §B fail-closed
            // null-evidence case sets $declineOmitsPaymentIntentId to force
            // this fake to omit it, exactly as an incomplete real Stripe
            // error response would.
            $omitReference = $this->declineOmitsPaymentIntentId[$idempotencyKey] ?? $this->declineOmitsPaymentIntentId['*'] ?? false;
            throw new ProviderCardDeclinedException('generic_decline', $omitReference ? null : $id);
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

    /**
     * M4 contract §15a — a freshly created Session is always 'open'/
     * 'unpaid' with a redirect url (never immediately paid, mirroring
     * real Stripe Checkout), with no PaymentIntent/PaymentMethod reference
     * yet. Session state is configured via $checkoutSessionOutcomes for
     * the later retrieveCheckoutSession() call.
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
    ): CheckoutSessionResult {
        $id = 'cs_fake_'.Str::random(16);
        $paymentIntentId = 'pi_fake_'.Str::random(16);

        $this->createdCheckoutSessions[$id] = [
            'idempotencyKey' => $idempotencyKey,
            'amountMinorUnits' => $amountMinorUnits,
            'currencyCode' => $currencyCode,
            'providerCustomerId' => $providerCustomerId,
            'providerPaymentIntentId' => $paymentIntentId,
        ];

        $this->createCheckoutSessionCalls[] = [
            'providerCustomerId' => $providerCustomerId,
            'amountMinorUnits' => $amountMinorUnits,
            'currencyCode' => $currencyCode,
            'lineItemName' => $lineItemName,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'idempotencyKey' => $idempotencyKey,
            'metadata' => $metadata,
            'setupFutureUsageOffSession' => $setupFutureUsageOffSession,
        ];

        return new CheckoutSessionResult(
            $id,
            'open',
            'unpaid',
            'https://checkout.fake.stripe.test/'.$id,
            $amountMinorUnits,
            $currencyCode,
            $providerCustomerId,
            null,
            null,
        );
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §16.A — registers
     * an explicit CheckoutSessionResult this Fake must return the next
     * time retrieveCheckoutSession() is called for that exact Session id,
     * regardless of whether this same Fake instance ever created it.
     */
    public function registerCheckoutSessionResult(CheckoutSessionResult $result): void
    {
        $this->registeredCheckoutSessionResults[$result->providerCheckoutSessionId] = $result;
    }

    public function retrieveCheckoutSession(string $providerCheckoutSessionId): CheckoutSessionResult
    {
        if (isset($this->registeredCheckoutSessionResults[$providerCheckoutSessionId])) {
            return $this->registeredCheckoutSessionResults[$providerCheckoutSessionId];
        }

        $session = $this->createdCheckoutSessions[$providerCheckoutSessionId] ?? null;

        if ($session === null) {
            // Deterministic fallback for a session this fake never itself
            // created (e.g. a test constructing a webhook event against a
            // manually chosen id) — treated as already paid by default,
            // mirroring createOffSessionPaymentIntent()'s own succeeded
            // default.
            return new CheckoutSessionResult($providerCheckoutSessionId, 'complete', 'paid', null, 0, 'USD', 'cus_fake_unknown', 'pi_fake_'.Str::random(16), 'pm_fake_'.Str::random(16));
        }

        $outcome = $this->checkoutSessionOutcomes[$session['idempotencyKey']] ?? $this->checkoutSessionOutcomes['*'] ?? [];
        $status = $outcome['status'] ?? 'complete';
        $paymentStatus = $outcome['paymentStatus'] ?? 'paid';
        $providerPaymentMethodId = array_key_exists('providerPaymentMethodId', $outcome)
            ? $outcome['providerPaymentMethodId']
            : ($status === 'complete' ? 'pm_fake_'.substr($session['providerPaymentIntentId'], strlen('pi_fake_')) : null);

        return new CheckoutSessionResult(
            $providerCheckoutSessionId,
            $status,
            $paymentStatus,
            $status === 'open' ? 'https://checkout.fake.stripe.test/'.$providerCheckoutSessionId : null,
            $session['amountMinorUnits'],
            $session['currencyCode'],
            $session['providerCustomerId'],
            $status === 'complete' ? $session['providerPaymentIntentId'] : null,
            $providerPaymentMethodId,
        );
    }

    public function confirmPaymentIntent(
        string $providerPaymentIntentId,
        string $providerPaymentMethodId,
        string $idempotencyKey,
    ): PaymentIntentResult {
        $this->confirmPaymentIntentCalls[] = [
            'providerPaymentIntentId' => $providerPaymentIntentId,
            'providerPaymentMethodId' => $providerPaymentMethodId,
            'idempotencyKey' => $idempotencyKey,
        ];

        $outcome = $this->confirmPaymentIntentOutcomes[$idempotencyKey] ?? $this->confirmPaymentIntentOutcomes['*'] ?? 'succeeded';

        if ($outcome === 'declined') {
            throw new ProviderCardDeclinedException('generic_decline');
        }

        $status = $outcome === 'requires_action' ? 'requires_action' : 'succeeded';

        return new PaymentIntentResult($providerPaymentIntentId, $status, $status === 'requires_action' ? 'pi_fake_secret_'.Str::random(8) : null, 0, 'USD');
    }
}
