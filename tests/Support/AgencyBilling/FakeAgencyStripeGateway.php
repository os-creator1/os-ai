<?php

namespace Tests\Support\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Library\AgencyBilling\AgencyAccountSnapshot;
use App\Library\AgencyBilling\AgencyCheckoutSessionResult;
use App\Library\AgencyBilling\AgencyPriceSnapshot;
use App\Library\AgencyBilling\AgencyStripeGateway;
use App\Library\AgencyBilling\AgencySubscriptionSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TEST-ONLY lane-C gateway. No network anywhere in this lane's suite.
 *
 * IT POLICES THE THINGS THAT MATTER, so a defect fails a test rather than a
 * customer:
 *
 *  1. §C4 — every provider call asserts `DB::transactionLevel()` is at the
 *     test's own baseline, so a call made inside a transaction or while a row
 *     lock is held fails loudly instead of silently holding a lock across a
 *     network round trip.
 *
 *  2. §C1 — EVERY OBJECT BELONGS TO EXACTLY ONE CONNECTED ACCOUNT, and a call
 *     that reaches for another account's object simply cannot see it, exactly
 *     as Stripe behaves. This is what turns "cross-account substitution is
 *     impossible" from a claim into something the suite proves.
 *
 *  3. Stripe's idempotency: the same key returns the ORIGINAL result, and the
 *     same key carrying DIFFERENT parameters is an error.
 *
 * `hooks` are interleaving seams: a callable registered under a method name
 * fires INSIDE that call, at exactly the point a real request would be
 * suspended on the network. That is the only honest way to test concurrency —
 * no threads, no sleeps, no luck.
 */
class FakeAgencyStripeGateway implements AgencyStripeGateway
{
    /** @var array<int, array{method: string, args: array<string, mixed>, transaction_level: int}> */
    public array $calls = [];

    /** Transaction depth the test itself runs at (RefreshDatabase opens one). */
    public int $baselineTransactionLevel = 0;

    public int $sequence = 0;

    /** @var array<string, array<string, mixed>> account id => account facts. */
    public array $accounts = [];

    /** @var array<string, string> OAuth authorization code => connected account id. */
    public array $oauthCodes = [];

    /** @var array<string, array<string, mixed>> price id => price facts (incl. its account). */
    public array $prices = [];

    /** @var array<string, array<string, mixed>> session id => session facts. */
    public array $sessions = [];

    /** @var array<string, array<string, mixed>> subscription id => subscription facts. */
    public array $subscriptions = [];

    /** Session id keyed by idempotency key, mimicking Stripe's own behaviour. */
    public array $sessionsByKey = [];

    /** The parameters each key was FIRST used with, so reuse can be policed. */
    public array $keyFingerprints = [];

    /** The status a newly completed subscription reports when there is no trial. */
    public AgencyClientSubscriptionStatus $subscriptionStatus = AgencyClientSubscriptionStatus::Active;

    public ?AgencyBillingException $failWith = null;

    public string $validSignature = 'v1=fake-valid-agency-signature';

    public bool $configured = true;

    public bool $webhookConfigured = true;

    /** null when the key is missing/invalid — never a fabricated "test". */
    public ?string $mode = 'test';

    /** @var array<string, callable(array<string, mixed>, self): void> */
    public array $hooks = [];

    // =====================================================================
    // Accounts
    // =====================================================================

    public function createAccount(string $country, ?string $email, string $agencyWorkspaceUid): AgencyAccountSnapshot
    {
        $this->record('createAccount', ['country' => $country, 'email' => $email, 'agency' => $agencyWorkspaceUid]);

        $id = 'acct_fake' . str_pad((string) (++$this->sequence), 6, '0', STR_PAD_LEFT);

        $this->accounts[$id] = [
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'requirements_disabled_reason' => null,
            'default_currency' => 'USD',
            'agency_workspace_uid' => $agencyWorkspaceUid,
            // Mirrors StripeApiAgencyGateway::accountCreateParams() exactly —
            // every account THIS gateway creates is compatible by
            // construction, same as the real one.
            'fees_payer' => 'account',
            'losses_payer' => 'stripe',
            'requirement_collection' => 'stripe',
            'dashboard_type' => 'full',
        ];

        return $this->accountSnapshot($id);
    }

    /**
     * "Connect existing Stripe account" — registers a fake pre-existing
     * account an OAuth authorization code will resolve to, with the same
     * facts shape as `$accounts` above. Defaults to a COMPATIBLE, fully
     * onboarded account; pass `$facts` (e.g. `['fees_payer' => 'application']`)
     * to simulate one this platform must fail closed on.
     */
    public function registerExistingAccount(string $authorizationCode, string $connectedAccountId, array $facts = []): void
    {
        $this->oauthCodes[$authorizationCode] = $connectedAccountId;

        $this->accounts[$connectedAccountId] = array_merge([
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements_disabled_reason' => null,
            'default_currency' => 'USD',
            'fees_payer' => 'account',
            'losses_payer' => 'stripe',
            'requirement_collection' => 'stripe',
            'dashboard_type' => 'full',
        ], $facts);
    }

    public function oauthAuthorizeUrl(string $state, string $redirectUri): string
    {
        $this->record('oauthAuthorizeUrl', ['state' => $state, 'redirect_uri' => $redirectUri]);

        return 'https://connect.stripe.test/oauth/authorize?state=' . urlencode($state)
            . '&redirect_uri=' . urlencode($redirectUri);
    }

    public function exchangeOAuthCode(string $authorizationCode): string
    {
        $this->record('exchangeOAuthCode', ['code' => $authorizationCode]);

        return $this->oauthCodes[$authorizationCode]
            ?? throw AgencyBillingException::because(AgencyBillingException::OAUTH_FAILED);
    }

    public function createOnboardingLink(string $connectedAccountId, string $refreshUrl, string $returnUrl): string
    {
        $this->record('createOnboardingLink', ['account' => $connectedAccountId]);
        $this->assertAccountExists($connectedAccountId);

        return 'https://connect.stripe.test/onboard/' . $connectedAccountId . '?s=' . (++$this->sequence);
    }

    public function retrieveAccount(string $connectedAccountId): AgencyAccountSnapshot
    {
        $this->record('retrieveAccount', ['account' => $connectedAccountId]);
        $this->assertAccountExists($connectedAccountId);

        return $this->accountSnapshot($connectedAccountId);
    }

    /** The Agency finishing Stripe's hosted onboarding. */
    public function completeOnboarding(string $connectedAccountId): void
    {
        $this->accounts[$connectedAccountId] = array_merge($this->accounts[$connectedAccountId] ?? [], [
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements_disabled_reason' => null,
        ]);
    }

    /** The provider restricting an account, as it does when a requirement lapses. */
    public function restrictAccount(string $connectedAccountId, string $reason = 'requirements.past_due'): void
    {
        $this->accounts[$connectedAccountId] = array_merge($this->accounts[$connectedAccountId] ?? [], [
            'charges_enabled' => false,
            'details_submitted' => true,
            'requirements_disabled_reason' => $reason,
        ]);
    }

    // =====================================================================
    // Prices
    // =====================================================================

    /**
     * Registers a Price ON ONE ACCOUNT. The account binding is the point: a
     * Price defined for Agency A must be invisible to Agency B.
     */
    public function definePrice(string $connectedAccountId, string $id, array $facts = []): void
    {
        $this->prices[$id] = array_merge([
            'account' => $connectedAccountId,
            'active' => true,
            'currency' => 'USD',
            'unit_amount' => 34900,
            'recurring' => true,
            'interval' => 'month',
            'interval_count' => 1,
            'livemode' => false,
        ], $facts, ['account' => $connectedAccountId]);
    }

    public function retrievePrice(string $connectedAccountId, string $providerPriceId): AgencyPriceSnapshot
    {
        $this->record('retrievePrice', ['account' => $connectedAccountId, 'price' => $providerPriceId]);

        $price = $this->prices[$providerPriceId] ?? null;

        // THE CROSS-ACCOUNT RULE, modelled as Stripe behaves: an object that
        // lives on another account is not "forbidden", it simply does not
        // exist as far as this account's key is concerned.
        if ($price === null || (string) $price['account'] !== $connectedAccountId) {
            throw AgencyBillingException::because(AgencyBillingException::PRICE_NOT_RETRIEVABLE);
        }

        return new AgencyPriceSnapshot(
            id: $providerPriceId,
            active: (bool) $price['active'],
            currency: (string) $price['currency'],
            unitAmount: $price['unit_amount'] === null ? null : (int) $price['unit_amount'],
            recurring: (bool) $price['recurring'],
            interval: $price['interval'],
            intervalCount: $price['interval_count'] === null ? null : (int) $price['interval_count'],
            livemode: (bool) $price['livemode'],
        );
    }

    public function createPrice(
        string $connectedAccountId,
        string $productName,
        int $unitAmountMinor,
        string $currencyCode,
        string $interval,
        string $idempotencyKey,
    ): AgencyPriceSnapshot {
        $this->record('createPrice', [
            'account' => $connectedAccountId,
            'product' => $productName,
            'unit_amount' => $unitAmountMinor,
            'currency' => $currencyCode,
            'interval' => $interval,
            'idempotency_key' => $idempotencyKey,
        ]);
        $this->assertAccountExists($connectedAccountId);

        $fingerprint = md5(json_encode([$connectedAccountId, $unitAmountMinor, $currencyCode, $interval]));

        if (isset($this->sessionsByKey[$idempotencyKey])) {
            if (($this->keyFingerprints[$idempotencyKey] ?? null) !== $fingerprint) {
                throw new RuntimeException(
                    "Stripe idempotency violated: key [{$idempotencyKey}] was reused with different parameters."
                );
            }

            return $this->retrievePrice($connectedAccountId, $this->sessionsByKey[$idempotencyKey]);
        }

        $id = 'price_fake' . str_pad((string) (++$this->sequence), 6, '0', STR_PAD_LEFT);

        $this->definePrice($connectedAccountId, $id, [
            'unit_amount' => $unitAmountMinor,
            'currency' => mb_strtoupper($currencyCode),
            'interval' => $interval,
            'interval_count' => 1,
            'livemode' => $this->mode === 'live',
        ]);

        $this->sessionsByKey[$idempotencyKey] = $id;
        $this->keyFingerprints[$idempotencyKey] = $fingerprint;

        return $this->retrievePrice($connectedAccountId, $id);
    }

    // =====================================================================
    // Checkout and subscriptions
    // =====================================================================

    public function createSubscriptionCheckout(
        string $connectedAccountId,
        string $providerPriceId,
        string $clientReferenceId,
        string $idempotencyKey,
        string $successUrl,
        string $cancelUrl,
        string $customerEmail,
        ?int $trialDays,
        ?string $existingCustomerId = null,
    ): AgencyCheckoutSessionResult {
        $this->record('createSubscriptionCheckout', [
            'account' => $connectedAccountId,
            'price' => $providerPriceId,
            'client_reference_id' => $clientReferenceId,
            'idempotency_key' => $idempotencyKey,
            'email' => $customerEmail,
            'trial_days' => $trialDays,
            'customer' => $existingCustomerId,
        ]);

        // The Price must belong to THIS account, or Stripe would refuse it.
        $this->retrievePrice($connectedAccountId, $providerPriceId);

        $fingerprint = md5(json_encode([
            $connectedAccountId, $providerPriceId, $clientReferenceId, $successUrl, $cancelUrl,
            $customerEmail, $trialDays, $existingCustomerId,
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
            'account' => $connectedAccountId,
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
     * The client finishing checkout on Stripe's hosted page. Creates the
     * provider subscription exactly as Stripe would, on the same account.
     */
    public function completeCheckout(string $sessionId, ?AgencyClientSubscriptionStatus $status = null): string
    {
        $session = $this->sessions[$sessionId] ?? throw new RuntimeException("Unknown fake session [{$sessionId}].");
        $subscriptionId = 'sub_fake' . str_pad((string) (++$this->sequence), 6, '0', STR_PAD_LEFT);
        $trialDays = $session['trial_days'];
        $resolved = $status ?? ($trialDays !== null ? AgencyClientSubscriptionStatus::Trialing : $this->subscriptionStatus);

        // WHOLE SECONDS, because that is what Stripe sends: every date on a
        // subscription is an integer Unix timestamp. Our datetime columns carry
        // no fractional seconds and MySQL rounds when storing one, so a
        // microsecond here would read back up to a second away from what was
        // written — and an exact "same trial end?" comparison would then
        // disagree with itself at random, for a reason impossible in
        // production.
        $now = CarbonImmutable::now()->startOfSecond();

        $this->subscriptions[$subscriptionId] = [
            'account' => $session['account'],
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

    public function setSubscriptionStatus(string $subscriptionId, AgencyClientSubscriptionStatus $status): void
    {
        $this->subscriptions[$subscriptionId]['status'] = $status;

        if ($status === AgencyClientSubscriptionStatus::Canceled) {
            $this->subscriptions[$subscriptionId]['canceled_at'] ??= CarbonImmutable::now()->startOfSecond();
            $this->subscriptions[$subscriptionId]['ended_at'] ??= CarbonImmutable::now()->startOfSecond();
        }
    }

    /** Rolls the billing period forward, as a renewal would. */
    public function advancePeriod(string $subscriptionId): void
    {
        $end = $this->subscriptions[$subscriptionId]['period_end'] ?? CarbonImmutable::now()->startOfSecond();
        $this->subscriptions[$subscriptionId]['period_start'] = $end;
        $this->subscriptions[$subscriptionId]['period_end'] = $end->addMonth();
        $this->subscriptions[$subscriptionId]['trial_end'] = null;
    }

    public function retrieveCheckoutSession(string $connectedAccountId, string $sessionId): AgencyCheckoutSessionResult
    {
        $this->record('retrieveCheckoutSession', ['account' => $connectedAccountId, 'session' => $sessionId]);
        $this->assertSessionOnAccount($sessionId, $connectedAccountId);

        return $this->sessionResult($sessionId);
    }

    public function expireCheckoutSession(string $connectedAccountId, string $sessionId): AgencyCheckoutSessionResult
    {
        $this->record('expireCheckoutSession', ['account' => $connectedAccountId, 'session' => $sessionId]);
        $this->assertSessionOnAccount($sessionId, $connectedAccountId);

        // Stripe allows expire only from `open`.
        if (($this->sessions[$sessionId]['status'] ?? null) !== 'open') {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        $this->sessions[$sessionId]['status'] = 'expired';

        return $this->sessionResult($sessionId);
    }

    /** Whether any session is still payable — the invariant §C7 protects. */
    public function payableSessionIds(): array
    {
        return array_keys(array_filter($this->sessions, static fn (array $s): bool => ($s['status'] ?? null) === 'open'));
    }

    public function retrieveSubscription(string $connectedAccountId, string $providerSubscriptionId): AgencySubscriptionSnapshot
    {
        $this->record('retrieveSubscription', ['account' => $connectedAccountId, 'subscription' => $providerSubscriptionId]);
        $this->assertSubscriptionOnAccount($providerSubscriptionId, $connectedAccountId);

        return $this->subscriptionSnapshot($providerSubscriptionId);
    }

    public function changeSubscriptionPrice(
        string $connectedAccountId,
        string $providerSubscriptionId,
        string $providerPriceId,
        bool $prorate,
        string $idempotencyKey,
    ): AgencySubscriptionSnapshot {
        $this->record('changeSubscriptionPrice', [
            'account' => $connectedAccountId,
            'subscription' => $providerSubscriptionId,
            'price' => $providerPriceId,
            'prorate' => $prorate,
            'idempotency_key' => $idempotencyKey,
        ]);
        $this->assertSubscriptionOnAccount($providerSubscriptionId, $connectedAccountId);
        $this->retrievePrice($connectedAccountId, $providerPriceId);

        // The same idempotency rule Stripe applies to every request: one key,
        // one set of parameters. Keying a plan change on the target PLAN id
        // would break exactly here once an Agency repriced that plan.
        $fingerprint = md5(json_encode([$connectedAccountId, $providerSubscriptionId, $providerPriceId, $prorate]));

        if (isset($this->keyFingerprints[$idempotencyKey])
            && $this->keyFingerprints[$idempotencyKey] !== $fingerprint) {
            throw new RuntimeException(
                "Stripe idempotency violated: key [{$idempotencyKey}] was reused with different parameters. "
                . 'A plan-change key must belong to the OPERATION, not to the target plan id.'
            );
        }

        $this->keyFingerprints[$idempotencyKey] = $fingerprint;
        $this->subscriptions[$providerSubscriptionId]['price'] = $providerPriceId;

        return $this->subscriptionSnapshot($providerSubscriptionId);
    }

    public function setCancelAtPeriodEnd(
        string $connectedAccountId,
        string $providerSubscriptionId,
        bool $cancelAtPeriodEnd,
    ): AgencySubscriptionSnapshot {
        $this->record('setCancelAtPeriodEnd', [
            'account' => $connectedAccountId,
            'subscription' => $providerSubscriptionId,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
        ]);
        $this->assertSubscriptionOnAccount($providerSubscriptionId, $connectedAccountId);

        $this->subscriptions[$providerSubscriptionId]['cancel_at_period_end'] = $cancelAtPeriodEnd;

        return $this->subscriptionSnapshot($providerSubscriptionId);
    }

    public function createBillingPortalSession(
        string $connectedAccountId,
        string $providerCustomerId,
        string $returnUrl,
        ?string $flow = null,
    ): string {
        $this->record('createBillingPortalSession', [
            'account' => $connectedAccountId,
            'customer' => $providerCustomerId,
            'return_url' => $returnUrl,
            'flow' => $flow,
        ]);
        $this->assertAccountExists($connectedAccountId);

        return 'https://billing.stripe.test/' . $connectedAccountId . '/' . $providerCustomerId
            . ($flow === null ? '' : '?flow=' . $flow);
    }

    public function verifyWebhookPayload(string $rawPayload, string $signatureHeader): array
    {
        $this->record('verifyWebhookPayload', []);

        if ($signatureHeader !== $this->validSignature) {
            throw AgencyBillingException::because(AgencyBillingException::INVALID_SIGNATURE);
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

    // =====================================================================
    // Test helpers
    // =====================================================================

    /** Every option recorded for provider calls of one kind. */
    public function callsOf(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $call['method'] === $method));
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

    public function subscriptionSnapshot(string $subscriptionId): AgencySubscriptionSnapshot
    {
        $subscription = $this->subscriptions[$subscriptionId] ?? [];

        return new AgencySubscriptionSnapshot(
            providerSubscriptionId: $subscriptionId,
            providerCustomerId: (string) ($subscription['customer'] ?? ''),
            connectedAccountId: (string) ($subscription['account'] ?? ''),
            status: $subscription['status'] ?? AgencyClientSubscriptionStatus::Active,
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

    private function accountSnapshot(string $id): AgencyAccountSnapshot
    {
        $account = $this->accounts[$id] ?? [];

        return new AgencyAccountSnapshot(
            stripeAccountId: $id,
            chargesEnabled: (bool) ($account['charges_enabled'] ?? false),
            payoutsEnabled: (bool) ($account['payouts_enabled'] ?? false),
            detailsSubmitted: (bool) ($account['details_submitted'] ?? false),
            requirementsDisabledReason: $account['requirements_disabled_reason'] ?? null,
            defaultCurrency: $account['default_currency'] ?? null,
            controllerFeesPayer: $account['fees_payer'] ?? null,
            controllerLossesPayer: $account['losses_payer'] ?? null,
            controllerRequirementCollection: $account['requirement_collection'] ?? null,
            controllerDashboardType: $account['dashboard_type'] ?? null,
        );
    }

    private function sessionResult(string $sessionId): AgencyCheckoutSessionResult
    {
        $session = $this->sessions[$sessionId] ?? [];

        return new AgencyCheckoutSessionResult(
            sessionId: $sessionId,
            url: 'https://checkout.stripe.test/' . $sessionId,
            customerId: $session['customer'] ?? null,
            subscriptionId: $session['subscription'] ?? null,
            status: $session['status'] ?? null,
            clientReferenceId: $session['client_reference_id'] ?? null,
        );
    }

    private function assertAccountExists(string $connectedAccountId): void
    {
        if (! isset($this->accounts[$connectedAccountId])) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }
    }

    private function assertSessionOnAccount(string $sessionId, string $connectedAccountId): void
    {
        if ((string) ($this->sessions[$sessionId]['account'] ?? '') !== $connectedAccountId) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }
    }

    private function assertSubscriptionOnAccount(string $subscriptionId, string $connectedAccountId): void
    {
        if ((string) ($this->subscriptions[$subscriptionId]['account'] ?? '') !== $connectedAccountId) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        $level = DB::transactionLevel();

        if ($level > $this->baselineTransactionLevel) {
            throw new RuntimeException(
                "Lane C §C4 violated: {$method}() was called at DB::transactionLevel() {$level}, "
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
}
