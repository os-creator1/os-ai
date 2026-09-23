<?php

namespace Tests\Support\Payments;

use App\Exceptions\Payments\StripeConnectException;
use App\Library\Payments\ConnectedAccountSnapshot;
use App\Library\Payments\PaymentIntentSnapshot;
use App\Library\Payments\StripeConnectGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TEST-ONLY lane-B gateway. No network anywhere in this slice's suite.
 *
 * It also POLICES §7 for us: every method asserts DB::transactionLevel() is
 * at the test's own baseline, so a provider call made inside a transaction or
 * while a row lock is held fails loudly instead of silently holding a lock
 * across a network round trip.
 */
class FakeStripeConnectGateway implements StripeConnectGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public int $accountSequence = 0;

    /** Transaction depth the test itself runs at (RefreshDatabase opens one). */
    public int $baselineTransactionLevel = 0;

    public bool $chargesEnabled = false;

    public bool $payoutsEnabled = false;

    public bool $detailsSubmitted = false;

    public ?string $disabledReason = null;

    public ?string $defaultCurrency = 'USD';

    public ?StripeConnectException $failWith = null;

    /**
     * Invoked DURING retrieveAccount(), i.e. in the exact window where the
     * provider call is in flight and another process could still change the
     * row. That window is the only place optimistic-lock staleness can really
     * arise, so a test simulates a concurrent writer here rather than by
     * pretending a caller held a stale version it could never have held.
     *
     * @var null|callable():void
     */
    public $duringRetrieve = null;

    public function createAccount(string $country, ?string $email, string $businessUid): ConnectedAccountSnapshot
    {
        $this->record('createAccount', ['country' => $country, 'business_uid' => $businessUid]);

        return $this->snapshot('acct_fake' . str_pad((string) (++$this->accountSequence), 6, '0', STR_PAD_LEFT));
    }

    // =================================================================
    // Sub-slice E — payment execution
    // =================================================================

    /** Intent id keyed by the Stripe idempotency key, mimicking Stripe's own behaviour. */
    public array $intentsByKey = [];

    /** Intent state keyed by intent id: ['status' => ..., 'amount' => ..., ...]. */
    public array $intents = [];

    public int $intentSequence = 0;

    /** The local status every newly created intent reports. */
    public \App\Enums\Documents\BusinessDocumentPaymentStatus $intentStatus = \App\Enums\Documents\BusinessDocumentPaymentStatus::Created;

    /**
     * Simulates §7.2.1 Case B: Stripe RECEIVED and created the intent, but the
     * HTTP response was lost. The intent really exists under the idempotency
     * key, yet the caller sees a failure and the local row never learns the
     * id — exactly the situation a retry must not turn into a second charge.
     */
    public bool $loseNextCreateResponse = false;

    public ?StripeConnectException $failCreateWith = null;

    /** Signature header value this fake treats as verifying. */
    public string $validSignature = 'v1=fake-valid-signature';

    public function createPaymentIntent(
        string $connectedAccountId,
        int $amountMinor,
        string $currencyCode,
        string $idempotencyKey,
        string $operationId,
        string $description,
    ): PaymentIntentSnapshot {
        $this->record('createPaymentIntent', [
            'account' => $connectedAccountId,
            'amount' => $amountMinor,
            'currency' => $currencyCode,
            'idempotency_key' => $idempotencyKey,
            'operation_id' => $operationId,
        ]);

        if ($this->failCreateWith !== null) {
            throw $this->failCreateWith;
        }

        // Stripe's own idempotency: the same key returns the ORIGINAL intent.
        $intentId = $this->intentsByKey[$idempotencyKey]
            ?? ('pi_fake' . str_pad((string) (++$this->intentSequence), 6, '0', STR_PAD_LEFT));

        $this->intentsByKey[$idempotencyKey] = $intentId;
        $this->intents[$intentId] ??= [
            'status' => $this->intentStatus,
            'amount' => $amountMinor,
            'currency' => $currencyCode,
            'account' => $connectedAccountId,
            'operation_id' => $operationId,
        ];

        if ($this->loseNextCreateResponse) {
            $this->loseNextCreateResponse = false;

            // The intent above exists at the provider; the caller never learns it.
            throw StripeConnectException::providerFailed();
        }

        return $this->intentSnapshot($intentId);
    }

    /**
     * Invoked DURING retrievePaymentIntent(), with the intent id, so a test
     * can make one specific row's provider call fail while the others succeed
     * — which is how "one bad row must not abort the batch" is proved.
     *
     * @var null|callable(string):void
     */
    public $duringRetrieveIntent = null;

    public function retrievePaymentIntent(string $connectedAccountId, string $providerPaymentIntentId): PaymentIntentSnapshot
    {
        $this->record('retrievePaymentIntent', [
            'account' => $connectedAccountId,
            'intent' => $providerPaymentIntentId,
        ]);

        if ($this->duringRetrieveIntent !== null) {
            ($this->duringRetrieveIntent)($providerPaymentIntentId);
        }

        return $this->intentSnapshot($providerPaymentIntentId);
    }

    public function verifyWebhookPayload(string $rawPayload, string $signatureHeader): array
    {
        $this->record('verifyWebhookPayload', []);

        if ($signatureHeader !== $this->validSignature) {
            throw StripeConnectException::invalidSignature();
        }

        return json_decode($rawPayload, true) ?: [];
    }

    /** Moves a provider-side intent to a new status, as Stripe would. */
    public function setIntentStatus(string $intentId, \App\Enums\Documents\BusinessDocumentPaymentStatus $status): void
    {
        $this->intents[$intentId]['status'] = $status;
    }

    public function intentSnapshot(string $intentId): PaymentIntentSnapshot
    {
        $intent = $this->intents[$intentId] ?? [];

        return new PaymentIntentSnapshot(
            providerPaymentIntentId: $intentId,
            status: $intent['status'] ?? \App\Enums\Documents\BusinessDocumentPaymentStatus::Created,
            amountMinor: (int) ($intent['amount'] ?? 0),
            currencyCode: (string) ($intent['currency'] ?? 'USD'),
            connectedAccountId: (string) ($intent['account'] ?? ''),
            operationId: $intent['operation_id'] ?? null,
            providerChargeId: null,
            failureCode: null,
            clientSecret: $intentId . '_secret_fake',
        );
    }

    // =================================================================
    // Sub-slice F — refunds
    // =================================================================

    /** Refund id keyed by the Stripe idempotency key, mimicking Stripe's own behaviour. */
    public array $refundsByKey = [];

    /** Refund state keyed by refund id. */
    public array $refunds = [];

    public int $refundSequence = 0;

    /** The local status every newly created refund reports. */
    public \App\Enums\Documents\BusinessDocumentRefundStatus $refundStatus = \App\Enums\Documents\BusinessDocumentRefundStatus::Pending;

    /**
     * The refund equivalent of loseNextCreateResponse: Stripe really created
     * the refund under the key, but the response never arrived. A re-drive
     * with the SAME key must find that same refund rather than returning money
     * a second time.
     */
    public bool $loseNextRefundResponse = false;

    /**
     * Invoked DURING createRefund(), i.e. in the exact window where refund A
     * has been admitted and committed as `pending` but its outcome is not yet
     * known. That window is where §8.7's race actually lives — a second
     * admission arriving while the first reservation is outstanding — so a
     * test drives the concurrent request from here rather than pretending the
     * two calls were sequential.
     *
     * @var null|callable():void
     */
    public $duringCreateRefund = null;

    public function createRefund(
        string $connectedAccountId,
        string $providerPaymentIntentId,
        int $amountMinor,
        string $idempotencyKey,
        string $operationId,
    ): \App\Library\Payments\RefundSnapshot {
        $this->record('createRefund', [
            'account' => $connectedAccountId,
            'intent' => $providerPaymentIntentId,
            'amount' => $amountMinor,
            'idempotency_key' => $idempotencyKey,
            'operation_id' => $operationId,
        ]);

        $refundId = $this->refundsByKey[$idempotencyKey]
            ?? ('re_fake' . str_pad((string) (++$this->refundSequence), 6, '0', STR_PAD_LEFT));

        $this->refundsByKey[$idempotencyKey] = $refundId;
        $this->refunds[$refundId] ??= [
            'status' => $this->refundStatus,
            'amount' => $amountMinor,
            'currency' => $this->intents[$providerPaymentIntentId]['currency'] ?? 'USD',
            'account' => $connectedAccountId,
            'operation_id' => $operationId,
        ];

        if ($this->duringCreateRefund !== null) {
            $hook = $this->duringCreateRefund;
            // One-shot, so a concurrent request driven from the hook does not
            // recurse into it forever.
            $this->duringCreateRefund = null;
            $hook();
        }

        if ($this->loseNextRefundResponse) {
            $this->loseNextRefundResponse = false;

            throw StripeConnectException::providerFailed();
        }

        return $this->refundSnapshot($refundId);
    }

    public function retrieveRefund(string $connectedAccountId, string $providerRefundId): \App\Library\Payments\RefundSnapshot
    {
        $this->record('retrieveRefund', ['account' => $connectedAccountId, 'refund' => $providerRefundId]);

        return $this->refundSnapshot($providerRefundId);
    }

    /** Moves a provider-side refund to a new status, as Stripe would. */
    public function setRefundStatus(string $refundId, \App\Enums\Documents\BusinessDocumentRefundStatus $status): void
    {
        $this->refunds[$refundId]['status'] = $status;
    }

    public function refundSnapshot(string $refundId): \App\Library\Payments\RefundSnapshot
    {
        $refund = $this->refunds[$refundId] ?? [];

        return new \App\Library\Payments\RefundSnapshot(
            providerRefundId: $refundId,
            status: $refund['status'] ?? \App\Enums\Documents\BusinessDocumentRefundStatus::Pending,
            amountMinor: (int) ($refund['amount'] ?? 0),
            currencyCode: (string) ($refund['currency'] ?? 'USD'),
            connectedAccountId: (string) ($refund['account'] ?? ''),
            operationId: $refund['operation_id'] ?? null,
            providerChargeId: null,
        );
    }

    /** Every option recorded for provider calls of one kind. */
    public function callsOf(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $call['method'] === $method));
    }

    public function createOnboardingLink(string $stripeAccountId, string $refreshUrl, string $returnUrl): string
    {
        $this->record('createOnboardingLink', [
            'account' => $stripeAccountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
        ]);

        return 'https://connect.stripe.test/setup/' . $stripeAccountId;
    }

    public function retrieveAccount(string $stripeAccountId): ConnectedAccountSnapshot
    {
        $this->record('retrieveAccount', ['account' => $stripeAccountId]);

        if ($this->duringRetrieve !== null) {
            ($this->duringRetrieve)();
        }

        return $this->snapshot($stripeAccountId);
    }

    /** Every account id this fake was ever asked about. */
    public function accountsTouched(): array
    {
        return array_values(array_filter(array_map(fn (array $call) => $call['args']['account'] ?? null, $this->calls)));
    }

    public function callNames(): array
    {
        return array_map(fn (array $call) => $call['method'], $this->calls);
    }

    private function snapshot(string $accountId): ConnectedAccountSnapshot
    {
        return new ConnectedAccountSnapshot(
            stripeAccountId: $accountId,
            chargesEnabled: $this->chargesEnabled,
            payoutsEnabled: $this->payoutsEnabled,
            detailsSubmitted: $this->detailsSubmitted,
            requirementsDisabledReason: $this->disabledReason,
            defaultCurrency: $this->defaultCurrency,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        $level = DB::transactionLevel();

        if ($level > $this->baselineTransactionLevel) {
            throw new RuntimeException(
                "Contract 17 §7 violated: {$method}() was called at DB::transactionLevel() {$level}, "
                . "above the baseline {$this->baselineTransactionLevel}. No provider call may happen inside a "
                . 'transaction or while a row lock is held.'
            );
        }

        $this->calls[] = ['method' => $method, 'args' => $args, 'transaction_level' => $level];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
