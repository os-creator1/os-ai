<?php

namespace Tests\Support\PlatformBilling;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\CheckoutSessionResult;
use App\Library\PlatformBilling\PlatformStripeGateway;
use App\Library\PlatformBilling\PlatformSubscriptionSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TEST-ONLY lane-A gateway. No network anywhere in this lane's suite.
 *
 * It also POLICES Implementation Contract 21 §5 for us: every method asserts
 * DB::transactionLevel() is at the test's own baseline, so a provider call
 * made inside a transaction or while a row lock is held fails loudly instead
 * of silently holding a lock across a network round trip.
 *
 * It models Stripe's own idempotency: the same key returns the ORIGINAL
 * checkout session rather than opening a second one.
 */
class FakePlatformStripeGateway implements PlatformStripeGateway
{
    /** @var array<int, array{method: string, args: array<string, mixed>, transaction_level: int}> */
    public array $calls = [];

    /** Transaction depth the test itself runs at (RefreshDatabase opens one). */
    public int $baselineTransactionLevel = 0;

    public int $sequence = 0;

    /** Session id keyed by idempotency key, mimicking Stripe's own behaviour. */
    public array $sessionsByKey = [];

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    /** The status a newly completed subscription reports. */
    public PlatformSubscriptionStatus $subscriptionStatus = PlatformSubscriptionStatus::Active;

    public ?PlatformBillingException $failWith = null;

    public string $validSignature = 'v1=fake-valid-signature';

    public bool $configured = true;

    public bool $webhookConfigured = true;

    public string $mode = 'test';

    public function createSubscriptionCheckout(
        string $providerPriceId,
        string $clientReferenceId,
        string $idempotencyKey,
        string $successUrl,
        string $cancelUrl,
        string $customerEmail,
        ?int $trialDays,
        ?string $existingCustomerId = null,
    ): CheckoutSessionResult {
        $this->record('createSubscriptionCheckout', [
            'price' => $providerPriceId,
            'client_reference_id' => $clientReferenceId,
            'idempotency_key' => $idempotencyKey,
            'email' => $customerEmail,
            'trial_days' => $trialDays,
            'customer' => $existingCustomerId,
        ]);

        // Stripe's own idempotency: the same key returns the ORIGINAL session.
        $sessionId = $this->sessionsByKey[$idempotencyKey]
            ?? ('cs_fake' . str_pad((string) (++$this->sequence), 6, '0', STR_PAD_LEFT));

        $this->sessionsByKey[$idempotencyKey] = $sessionId;
        $this->sessions[$sessionId] ??= [
            'client_reference_id' => $clientReferenceId,
            'customer' => $existingCustomerId ?? ('cus_fake' . str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT)),
            'subscription' => null,
            'status' => 'open',
            'price' => $providerPriceId,
            'trial_days' => $trialDays,
        ];

        return $this->sessionResult($sessionId);
    }

    /**
     * The customer finishing checkout on Stripe's hosted page. Creates the
     * provider subscription exactly as Stripe would, including the trial
     * window when one was requested.
     */
    public function completeCheckout(string $sessionId, ?PlatformSubscriptionStatus $status = null): string
    {
        $session = $this->sessions[$sessionId] ?? throw new RuntimeException("Unknown fake session [{$sessionId}].");
        $subscriptionId = 'sub_fake' . str_pad((string) (++$this->sequence), 6, '0', STR_PAD_LEFT);
        $trialDays = $session['trial_days'];
        $resolved = $status ?? ($trialDays !== null ? PlatformSubscriptionStatus::Trialing : $this->subscriptionStatus);
        $now = CarbonImmutable::now();

        $this->subscriptions[$subscriptionId] = [
            'customer' => $session['customer'],
            'status' => $resolved,
            'price' => $session['price'],
            'period_start' => $now,
            'period_end' => $trialDays !== null ? $now->addDays($trialDays) : $now->addMonth(),
            'trial_end' => $trialDays !== null ? $now->addDays($trialDays) : null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'operation_id' => $session['client_reference_id'],
        ];

        $this->sessions[$sessionId]['subscription'] = $subscriptionId;
        $this->sessions[$sessionId]['status'] = 'complete';

        return $subscriptionId;
    }

    /** Moves a provider-side subscription to a new status, as Stripe would. */
    public function setSubscriptionStatus(string $subscriptionId, PlatformSubscriptionStatus $status): void
    {
        $this->subscriptions[$subscriptionId]['status'] = $status;

        if ($status === PlatformSubscriptionStatus::Canceled) {
            $this->subscriptions[$subscriptionId]['canceled_at'] ??= CarbonImmutable::now();
            $this->subscriptions[$subscriptionId]['ended_at'] ??= CarbonImmutable::now();
        }
    }

    /** Rolls the billing period forward, as a renewal would. */
    public function advancePeriod(string $subscriptionId): void
    {
        $end = $this->subscriptions[$subscriptionId]['period_end'] ?? CarbonImmutable::now();
        $this->subscriptions[$subscriptionId]['period_start'] = $end;
        $this->subscriptions[$subscriptionId]['period_end'] = $end->addMonth();
        $this->subscriptions[$subscriptionId]['trial_end'] = null;
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionResult
    {
        $this->record('retrieveCheckoutSession', ['session' => $sessionId]);

        return $this->sessionResult($sessionId);
    }

    public function retrieveSubscription(string $providerSubscriptionId): PlatformSubscriptionSnapshot
    {
        $this->record('retrieveSubscription', ['subscription' => $providerSubscriptionId]);

        return $this->subscriptionSnapshot($providerSubscriptionId);
    }

    public function changeSubscriptionPrice(
        string $providerSubscriptionId,
        string $providerPriceId,
        bool $prorate,
        string $idempotencyKey,
    ): PlatformSubscriptionSnapshot {
        $this->record('changeSubscriptionPrice', [
            'subscription' => $providerSubscriptionId,
            'price' => $providerPriceId,
            'prorate' => $prorate,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->subscriptions[$providerSubscriptionId]['price'] = $providerPriceId;

        return $this->subscriptionSnapshot($providerSubscriptionId);
    }

    public function setCancelAtPeriodEnd(string $providerSubscriptionId, bool $cancelAtPeriodEnd): PlatformSubscriptionSnapshot
    {
        $this->record('setCancelAtPeriodEnd', [
            'subscription' => $providerSubscriptionId,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
        ]);

        $this->subscriptions[$providerSubscriptionId]['cancel_at_period_end'] = $cancelAtPeriodEnd;

        return $this->subscriptionSnapshot($providerSubscriptionId);
    }

    public function verifyWebhookPayload(string $rawPayload, string $signatureHeader): array
    {
        $this->record('verifyWebhookPayload', []);

        if ($signatureHeader !== $this->validSignature) {
            throw PlatformBillingException::because(PlatformBillingException::INVALID_SIGNATURE);
        }

        return json_decode($rawPayload, true) ?: [];
    }

    public function configurationStatus(): array
    {
        return [
            'configured' => $this->configured,
            'webhook_configured' => $this->webhookConfigured,
            'mode' => $this->mode,
        ];
    }

    /** Every option recorded for provider calls of one kind. */
    public function callsOf(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $call['method'] === $method));
    }

    public function subscriptionSnapshot(string $subscriptionId): PlatformSubscriptionSnapshot
    {
        $subscription = $this->subscriptions[$subscriptionId] ?? [];

        return new PlatformSubscriptionSnapshot(
            providerSubscriptionId: $subscriptionId,
            providerCustomerId: (string) ($subscription['customer'] ?? ''),
            status: $subscription['status'] ?? PlatformSubscriptionStatus::Active,
            providerPriceId: $subscription['price'] ?? null,
            periodStart: $subscription['period_start'] ?? null,
            periodEnd: $subscription['period_end'] ?? null,
            trialEndsAt: $subscription['trial_end'] ?? null,
            cancelAtPeriodEnd: (bool) ($subscription['cancel_at_period_end'] ?? false),
            canceledAt: $subscription['canceled_at'] ?? null,
            endedAt: $subscription['ended_at'] ?? null,
            operationId: $subscription['operation_id'] ?? null,
        );
    }

    private function sessionResult(string $sessionId): CheckoutSessionResult
    {
        $session = $this->sessions[$sessionId] ?? [];

        return new CheckoutSessionResult(
            sessionId: $sessionId,
            url: 'https://checkout.stripe.test/' . $sessionId,
            customerId: $session['customer'] ?? null,
            subscriptionId: $session['subscription'] ?? null,
            status: $session['status'] ?? null,
            clientReferenceId: $session['client_reference_id'] ?? null,
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
                "Contract 21 §5 violated: {$method}() was called at DB::transactionLevel() {$level}, "
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
