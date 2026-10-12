<?php

namespace Tests\Support\PlatformBilling;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\CheckoutOptions;
use App\Library\PlatformBilling\CheckoutSessionResult;
use App\Library\PlatformBilling\ProviderPriceSnapshot;
use App\Library\PlatformBilling\SchedulePhase;
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

    /** The parameters each key was FIRST used with, so reuse can be policed. */
    public array $keyFingerprints = [];

    /** @var array<string, array<string, mixed>> Price id => provider Price facts. */
    public array $prices = [];

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

    /** null when the key is missing/invalid — never a fabricated "test". */
    public ?string $mode = 'test';

    /**
     * INTERLEAVING SEAMS. A callable registered under a method name is fired
     * INSIDE that provider call, at exactly the point a real request would be
     * suspended on the network.
     *
     * That is the only honest way to test §7's concurrency: the whole defect
     * is that a second request can act on the world WHILE the first one is
     * waiting for Stripe. A hook here lets a test drive that second request
     * deterministically, with no threads, no sleeps and no luck.
     *
     * Each hook receives the recorded arguments and this gateway.
     *
     * @var array<string, callable(array<string, mixed>, self): void>
     */
    public array $hooks = [];

    public function createSubscriptionCheckout(
        string $providerPriceId,
        string $clientReferenceId,
        string $idempotencyKey,
        string $successUrl,
        string $cancelUrl,
        string $customerEmail,
        ?int $trialDays,
        ?string $existingCustomerId = null,
        ?CheckoutOptions $options = null,
    ): CheckoutSessionResult {
        $this->record('createSubscriptionCheckout', [
            'options' => $options,
            'payment_method_collection' => 'always',
            'price' => $providerPriceId,
            'client_reference_id' => $clientReferenceId,
            'idempotency_key' => $idempotencyKey,
            'email' => $customerEmail,
            'trial_days' => $trialDays,
            'customer' => $existingCustomerId,
        ]);

        // Stripe's own idempotency, modelled faithfully enough to catch
        // misuse: the same key returns the ORIGINAL result, and the same key
        // carrying DIFFERENT parameters is an error — "the idempotency layer
        // compares incoming parameters to those of the original request and
        // errors if they're not the same to prevent accidental misuse".
        $fingerprint = md5(json_encode([
            $providerPriceId, $clientReferenceId, $successUrl, $cancelUrl, $customerEmail, $trialDays, $existingCustomerId,
            $options?->expiresAt, $options?->subscriptionMetadata, $options?->sessionMetadata,
        ]));

        if (isset($this->sessionsByKey[$idempotencyKey])) {
            if (($this->keyFingerprints[$idempotencyKey] ?? null) !== $fingerprint) {
                throw new RuntimeException(
                    "Stripe idempotency violated: key [{$idempotencyKey}] was reused with different parameters. "
                    . 'A deliberate new checkout attempt must carry a NEW durable attempt key.'
                );
            }

            return $this->sessionResult($this->sessionsByKey[$idempotencyKey]);
        }

        $sessionId = 'cs_fake' . str_pad((string) (++$this->sequence), 6, '0', STR_PAD_LEFT);

        $this->sessionsByKey[$idempotencyKey] = $sessionId;
        $this->keyFingerprints[$idempotencyKey] = $fingerprint;
        $this->sessions[$sessionId] = [
            'client_reference_id' => $clientReferenceId,
            'customer' => $existingCustomerId ?? ('cus_fake' . str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT)),
            'subscription' => null,
            'status' => 'open',
            'price' => $providerPriceId,
            'trial_days' => $trialDays,
            'options' => $options,
            'email' => $customerEmail,
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

        // WHOLE SECONDS, because that is what Stripe actually sends. Every
        // date on a Stripe subscription is an integer Unix timestamp, and
        // StripeApiPlatformGateway builds them with
        // `CarbonImmutable::createFromTimestampUTC((int) $value)`.
        //
        // This is not cosmetic. Our datetime columns hold no fractional
        // seconds and MySQL ROUNDS when storing one, so a fake value carrying
        // microseconds reads back up to a second away from the value it was
        // written from — which would make an exact "is this the same trial
        // end?" comparison disagree with itself at random, in tests, for a
        // reason that cannot happen in production.
        $now = CarbonImmutable::now()->startOfSecond();

        $this->subscriptions[$subscriptionId] = [
            'customer' => $session['customer'],
            'status' => $resolved,
            'price' => $session['price'],
            'period_start' => $now,
            'period_end' => $trialDays !== null ? $now->addDays($trialDays) : $this->addOnePeriod($now, $session['price']),
            'trial_end' => $trialDays !== null ? $now->addDays($trialDays) : null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'operation_id' => $session['client_reference_id'],
            'metadata' => $session['options']?->subscriptionMetadata ?? [],
            'schedule' => null,
        ];

        // What Stripe charges at Checkout: nothing during a trial, the Price's
        // amount otherwise. Recorded so a test can assert "today's charge".
        if ($trialDays === null && $resolved !== PlatformSubscriptionStatus::Incomplete) {
            $this->charges[] = [
                'subscription' => $subscriptionId,
                'price' => $session['price'],
                'amount' => $this->prices[$session['price']]['unit_amount'] ?? null,
                'reason' => 'checkout',
            ];
        }

        $this->sessions[$sessionId]['subscription'] = $subscriptionId;
        $this->sessions[$sessionId]['status'] = 'complete';

        return $subscriptionId;
    }

    /** Moves a provider-side subscription to a new status, as Stripe would. */
    public function setSubscriptionStatus(string $subscriptionId, PlatformSubscriptionStatus $status): void
    {
        $this->subscriptions[$subscriptionId]['status'] = $status;

        if ($status === PlatformSubscriptionStatus::Canceled) {
            $this->subscriptions[$subscriptionId]['canceled_at'] ??= CarbonImmutable::now()->startOfSecond();
            $this->subscriptions[$subscriptionId]['ended_at'] ??= CarbonImmutable::now()->startOfSecond();
        }
    }

    /** Rolls the billing period forward, as a renewal would. */
    public function advancePeriod(string $subscriptionId): void
    {
        $end = $this->subscriptions[$subscriptionId]['period_end'] ?? CarbonImmutable::now()->startOfSecond();
        $this->subscriptions[$subscriptionId]['period_start'] = $end;
        $this->subscriptions[$subscriptionId]['trial_end'] = null;

        // A subscription managed by a schedule takes the price of the phase the
        // new period starts in — exactly the transition Stripe performs.
        $schedule = $this->subscriptions[$subscriptionId]['schedule'] ?? null;

        if ($schedule !== null) {
            $this->subscriptions[$subscriptionId]['price'] = $this->schedulePriceAt($schedule, $end)
                ?? $this->subscriptions[$subscriptionId]['price'];

            if ($this->scheduleCompletedAt($schedule, $end)) {
                $this->subscriptions[$subscriptionId]['schedule'] = null;
            }
        }

        $price = $this->subscriptions[$subscriptionId]['price'];
        $this->subscriptions[$subscriptionId]['period_end'] = $this->addOnePeriod($end, $price);

        if (($this->subscriptions[$subscriptionId]['status'] ?? null) === PlatformSubscriptionStatus::Active
            || ($this->subscriptions[$subscriptionId]['status'] ?? null) === PlatformSubscriptionStatus::Trialing) {
            $this->charges[] = [
                'subscription' => $subscriptionId,
                'price' => $price,
                'amount' => $this->prices[$price]['unit_amount'] ?? null,
                'reason' => 'renewal',
            ];
        }
    }

    /** @var array<int, array{subscription: string, price: string, amount: int|null, reason: string}> */
    public array $charges = [];

    /** @var array<string, array<string, mixed>> Schedule id => phases */
    public array $schedules = [];

    /** @var array<string, string> Product id by key. */
    public array $products = [];

    /** The length of one period of a Price, as Stripe would advance it. */
    private function addOnePeriod(CarbonImmutable $from, string $priceId): CarbonImmutable
    {
        $price = $this->prices[$priceId] ?? [];
        $count = (int) ($price['interval_count'] ?? 1);

        return match ($price['interval'] ?? 'month') {
            'day' => $from->addDays($count),
            'year' => $from->addYears($count),
            default => $from->addMonths($count),
        };
    }

    private function schedulePriceAt(string $scheduleId, CarbonImmutable $at): ?string
    {
        foreach ($this->schedules[$scheduleId]['phases'] ?? [] as $phase) {
            if ($phase['ends_at'] === null || $at->lt($phase['ends_at'])) {
                return $phase['price'];
            }
        }

        return null;
    }

    private function scheduleCompletedAt(string $scheduleId, CarbonImmutable $at): bool
    {
        $phases = $this->schedules[$scheduleId]['phases'] ?? [];
        $penultimate = $phases[count($phases) - 2]['ends_at'] ?? null;

        return $penultimate !== null && $at->gte($penultimate);
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionResult
    {
        $this->record('retrieveCheckoutSession', ['session' => $sessionId]);

        return $this->sessionResult($sessionId);
    }

    public function expireCheckoutSession(string $sessionId): CheckoutSessionResult
    {
        $this->record('expireCheckoutSession', ['session' => $sessionId]);

        // Stripe allows expire only from `open`, and the session then becomes
        // unpayable.
        if (($this->sessions[$sessionId]['status'] ?? null) !== 'open') {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        $this->sessions[$sessionId]['status'] = 'expired';

        return $this->sessionResult($sessionId);
    }

    /** Registers a provider Price for the parity check to retrieve. */
    public function definePrice(string $id, array $facts = []): void
    {
        $this->prices[$id] = array_merge([
            'active' => true,
            'currency' => 'USD',
            'unit_amount' => 9900,
            'recurring' => true,
            // The platform's standard cadence: EXACTLY every 28 days.
            'interval' => 'day',
            'interval_count' => 28,
            'livemode' => false,
            'product' => 'prod_fake_platform',
        ], $facts);
    }

    public function retrievePrice(string $providerPriceId): \App\Library\PlatformBilling\ProviderPriceSnapshot
    {
        $this->record('retrievePrice', ['price' => $providerPriceId]);

        // An unknown Price — including one that lives on a CONNECTED account,
        // which the platform key cannot see — is simply not retrievable.
        if (! isset($this->prices[$providerPriceId])) {
            throw PlatformBillingException::because(PlatformBillingException::PRICE_NOT_RETRIEVABLE);
        }

        $price = $this->prices[$providerPriceId];

        return new \App\Library\PlatformBilling\ProviderPriceSnapshot(
            id: $providerPriceId,
            active: (bool) $price['active'],
            currency: (string) $price['currency'],
            unitAmount: $price['unit_amount'] === null ? null : (int) $price['unit_amount'],
            recurring: (bool) $price['recurring'],
            interval: $price['interval'],
            intervalCount: $price['interval_count'] === null ? null : (int) $price['interval_count'],
            livemode: (bool) $price['livemode'],
            productId: $price['product'] ?? null,
        );
    }

    public function ensureProduct(string $name, ?string $existingProductId, string $idempotencyKey): string
    {
        $this->record('ensureProduct', ['name' => $name, 'existing' => $existingProductId, 'key' => $idempotencyKey]);

        if ($existingProductId !== null && $existingProductId !== '') {
            return $existingProductId;
        }

        return $this->products[$idempotencyKey] ??= 'prod_fake' . str_pad((string) (++$this->sequence), 6, '0', STR_PAD_LEFT);
    }

    /** @var array<string, string> Price id by idempotency key. */
    public array $priceKeys = [];

    public function createRecurringPrice(
        string $productId,
        int $unitAmountMinor,
        string $currencyCode,
        string $interval,
        int $intervalCount,
        string $nickname,
        array $metadata,
        string $idempotencyKey,
    ): ProviderPriceSnapshot {
        $this->record('createRecurringPrice', [
            'product' => $productId, 'amount' => $unitAmountMinor, 'currency' => $currencyCode,
            'interval' => $interval, 'interval_count' => $intervalCount, 'key' => $idempotencyKey,
        ]);

        // Stripe's idempotency: the same key returns the ORIGINAL Price.
        $id = $this->priceKeys[$idempotencyKey] ??= 'price_fakeM' . str_pad((string) (++$this->sequence), 5, '0', STR_PAD_LEFT);

        if (! isset($this->prices[$id])) {
            $this->definePrice($id, [
                'currency' => mb_strtoupper($currencyCode),
                'unit_amount' => $unitAmountMinor,
                'interval' => $interval,
                'interval_count' => $intervalCount,
                'product' => $productId,
                'livemode' => $this->mode === 'live',
            ]);
        }

        return $this->retrievePriceQuietly($id);
    }

    private function retrievePriceQuietly(string $id): ProviderPriceSnapshot
    {
        $price = $this->prices[$id];

        return new ProviderPriceSnapshot(
            id: $id,
            active: (bool) $price['active'],
            currency: (string) $price['currency'],
            unitAmount: $price['unit_amount'] === null ? null : (int) $price['unit_amount'],
            recurring: (bool) $price['recurring'],
            interval: $price['interval'],
            intervalCount: $price['interval_count'] === null ? null : (int) $price['interval_count'],
            livemode: (bool) $price['livemode'],
            productId: $price['product'] ?? null,
        );
    }

    public function applyPriceSchedule(string $providerSubscriptionId, array $phases, string $idempotencyKey): PlatformSubscriptionSnapshot
    {
        $this->record('applyPriceSchedule', [
            'subscription' => $providerSubscriptionId,
            'phases' => array_map(static fn (SchedulePhase $p): array => ['price' => $p->priceId, 'ends_at' => $p->endsAt?->toIso8601String()], $phases),
            'key' => $idempotencyKey,
        ]);

        $subscription = $this->subscriptions[$providerSubscriptionId] ?? throw new RuntimeException('Unknown fake subscription.');

        $attached = $subscription['schedule'] ?? null;

        if ($attached !== null && ($this->scheduleKeys[$idempotencyKey . ':create'] ?? null) !== $attached) {
            // Stripe: a subscription already attached to a schedule cannot be given
            // another, and a create whose key is not a replay of the one that made
            // it is refused -- that schedule is not ours.
            throw PlatformBillingException::because(PlatformBillingException::SCHEDULE_NOT_OURS);
        }

        $scheduleId = $attached ?? 'sub_sched_fake' . str_pad((string) (++$this->sequence), 5, '0', STR_PAD_LEFT);
        $this->scheduleKeys[$idempotencyKey . ':create'] = $scheduleId;
        $this->schedules[$scheduleId] = [
            'subscription' => $providerSubscriptionId,
            'phases' => array_map(static fn (SchedulePhase $p): array => [
                'price' => $p->priceId,
                'ends_at' => $p->endsAt === null ? null : CarbonImmutable::instance($p->endsAt),
            ], $phases),
        ];
        $this->subscriptions[$providerSubscriptionId]['schedule'] = $scheduleId;

        // The first phase is the live one: the subscription is on its price.
        $this->subscriptions[$providerSubscriptionId]['price'] = $phases[0]->priceId;

        if ($this->loseNextScheduleResponse) {
            // The schedule WAS created at the provider; the caller never hears.
            $this->loseNextScheduleResponse = false;

            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return $this->subscriptionSnapshot($providerSubscriptionId);
    }

    /** @var array<string, string> Schedule id by `<key>:create` idempotency key. */
    public array $scheduleKeys = [];

    /** When true, the NEXT applyPriceSchedule creates the schedule and then loses its response. */
    public bool $loseNextScheduleResponse = false;

    /** When set, proveOwnSchedule fails inconclusively (a transient network/API error). */
    public ?\Throwable $proofFailure = null;

    public function proveOwnSchedule(string $providerSubscriptionId, string $idempotencyKey): ?string
    {
        $this->record('proveOwnSchedule', ['subscription' => $providerSubscriptionId, 'key' => $idempotencyKey]);

        if ($this->proofFailure !== null) {
            throw $this->proofFailure;
        }

        $attached = $this->subscriptions[$providerSubscriptionId]['schedule'] ?? null;
        $known = $this->scheduleKeys[$idempotencyKey . ':create'] ?? null;

        return ($attached !== null && $known === $attached) ? $attached : null;
    }

    public function releaseSchedule(string $providerScheduleId): void
    {
        $this->record('releaseSchedule', ['schedule' => $providerScheduleId]);

        foreach ($this->subscriptions as $id => $subscription) {
            if (($subscription['schedule'] ?? null) === $providerScheduleId) {
                $this->subscriptions[$id]['schedule'] = null;
            }
        }
    }

    /** Whether any session is still payable — the invariant §7 protects. */
    public function payableSessionIds(): array
    {
        return array_keys(array_filter($this->sessions, static fn (array $s): bool => ($s['status'] ?? null) === 'open'));
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

        // The same idempotency rule Stripe applies to every request, not just
        // to checkout: reusing one key with different parameters is an error.
        // Keying a plan change on the target CATALOG id broke exactly here,
        // because a repriced tier carries a different immutable Price.
        $fingerprint = md5(json_encode([$providerSubscriptionId, $providerPriceId, $prorate]));

        if (isset($this->keyFingerprints[$idempotencyKey])
            && $this->keyFingerprints[$idempotencyKey] !== $fingerprint) {
            throw new RuntimeException(
                "Stripe idempotency violated: key [{$idempotencyKey}] was reused with different parameters. "
                . 'A plan-change key must belong to the OPERATION, not to the target catalog id.'
            );
        }

        // Stripe refuses to edit the items of a subscription a schedule manages;
        // the caller must release the schedule first.
        if (($this->subscriptions[$providerSubscriptionId]['schedule'] ?? null) !== null) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        $this->keyFingerprints[$idempotencyKey] = $fingerprint;
        $this->subscriptions[$providerSubscriptionId]['price'] = $providerPriceId;

        return $this->subscriptionSnapshot($providerSubscriptionId);
    }

    public function setCancelAtPeriodEnd(string $providerSubscriptionId, bool $cancelAtPeriodEnd): PlatformSubscriptionSnapshot
    {
        $this->record('setCancelAtPeriodEnd', [
            'subscription' => $providerSubscriptionId,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
        ]);

        // Observed against real Stripe test mode: "The subscription is managed
        // by the subscription schedule `sub_sched_...`, and updating any
        // cancelation behavior directly is not allowed. Please update the
        // schedule instead." The caller must release the schedule first.
        if (($this->subscriptions[$providerSubscriptionId]['schedule'] ?? null) !== null) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        $this->subscriptions[$providerSubscriptionId]['cancel_at_period_end'] = $cancelAtPeriodEnd;

        return $this->subscriptionSnapshot($providerSubscriptionId);
    }

    public function createBillingPortalSession(string $providerCustomerId, string $returnUrl, ?string $flow = null): string
    {
        $this->record('createBillingPortalSession', [
            'customer' => $providerCustomerId,
            'return_url' => $returnUrl,
            'flow' => $flow,
        ]);

        return 'https://billing.stripe.test/p/' . $providerCustomerId . ($flow === null ? '' : '?flow=' . $flow);
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
            scheduleId: $subscription['schedule'] ?? null,
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

        // The suspension point: whatever a concurrent request would have done
        // while this call was on the wire, it does here.
        $hook = $this->hooks[$method] ?? null;

        if ($hook !== null) {
            $hook($args, $this);
        }

        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }

    /**
     * Registers an interleaving seam that fires exactly ONCE, which is what a
     * "one concurrent request arrives at this instant" test actually wants.
     */
    public function interleaveOnce(string $method, callable $hook): void
    {
        $this->hooks[$method] = function (array $args, self $gateway) use ($method, $hook): void {
            unset($gateway->hooks[$method]);

            $hook($args, $gateway);
        };
    }
}
