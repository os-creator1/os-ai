<?php

namespace App\Library\PlatformBilling;

use App\Exceptions\PlatformBilling\PlatformBillingException;
use Carbon\CarbonImmutable;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Implementation Contract 21 §5 — the ONLY class in lane A permitted to
 * reference a `Stripe\*` SDK class.
 *
 * DIRECT, FIRST-PARTY CHARGES ONLY. No `stripe_account` request option, no
 * `on_behalf_of`, no `transfer_data`, no `application_fee_amount`. Lane A's
 * money is the Platform Owner's own revenue landing in the Platform Owner's
 * own account, so there is no Connect account anywhere in these calls — which
 * is exactly what distinguishes lane A from lane B (§1).
 *
 * THE CLIENT IS BUILT LAZILY and fails closed at the point of use, never at
 * construction. Contract 17 §12.D learned this the hard way: a gateway that
 * throws from its constructor turns a missing configuration key into a 500 on
 * whatever page happens to depend on it, including unauthenticated ones. The
 * key is validated but never echoed — not the value, not a prefix, not a
 * length (§5.1).
 *
 * PROVIDER ERROR TEXT DIES HERE. Every `ApiErrorException` becomes
 * `PlatformBillingException::PROVIDER_FAILED` with our own copy, because the
 * provider's message can carry customer ids, request ids and request detail.
 *
 * PERIOD NORMALIZATION. In the current Stripe API `current_period_start` and
 * `current_period_end` live on the SUBSCRIPTION ITEM; older pinned API
 * versions expose them on the subscription object. This class reads the item
 * first and falls back to the subscription, so nothing downstream has to know
 * which shape the account's pinned version answered with.
 */
final class StripeApiPlatformGateway implements PlatformStripeGateway
{
    private ?StripeClient $client = null;

    private function client(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $secret = (string) config('services.stripe.secret');

        if ($secret === '' || ! preg_match('/\Ask_(test|live)_/', $secret)) {
            throw PlatformBillingException::because(PlatformBillingException::NOT_CONFIGURED);
        }

        return $this->client = new StripeClient($secret);
    }

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
        // Verified against Stripe's current Checkout Session reference:
        // mode=subscription is the documented way to set up a fixed-price
        // subscription, and client_reference_id (<=200 chars) is the
        // documented field for reconciling a session with internal systems.
        $params = [
            'mode' => 'subscription',
            'line_items' => [['price' => $providerPriceId, 'quantity' => 1]],
            'client_reference_id' => mb_substr($clientReferenceId, 0, 200),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            // A payment method is ALWAYS collected, including for a free trial,
            // so the first renewal after the trial can be charged
            // automatically. Stated explicitly rather than relying on the
            // provider default, because `if_required` would let a trial start
            // with nothing on file.
            'payment_method_collection' => 'always',
            'subscription_data' => [
                // Read back by the finalizer to prove operation identity.
                'metadata' => ['app_operation_id' => $clientReferenceId],
            ],
        ];

        if ($options !== null) {
            if ($options->expiresAt !== null) {
                $params['expires_at'] = $options->expiresAt;
            }

            if ($options->subscriptionMetadata !== []) {
                $params['subscription_data']['metadata'] = array_merge(
                    $options->subscriptionMetadata,
                    $params['subscription_data']['metadata'],
                );
            }

            if ($options->sessionMetadata !== []) {
                $params['metadata'] = $options->sessionMetadata;
            }
        }

        if ($existingCustomerId !== null) {
            $params['customer'] = $existingCustomerId;
        } else {
            $params['customer_email'] = $customerEmail;
        }

        if ($trialDays !== null && $trialDays >= 1) {
            // The Trial Offer API is public preview and is explicitly not
            // supported by Checkout; Stripe's own guidance there is to use the
            // classic free trial, which is what this is. Minimum 1 day,
            // maximum 730, per the current reference.
            $params['subscription_data']['trial_period_days'] = min($trialDays, 730);
            // We collect a payment method during signup (§8), so the default
            // create-an-invoice behaviour is the correct one. Stated
            // explicitly so a provider default change cannot silently turn a
            // paying customer into a paused one.
            $params['subscription_data']['trial_settings'] = [
                'end_behavior' => ['missing_payment_method' => 'create_invoice'],
            ];
        }

        try {
            $session = $this->client()->checkout->sessions->create($params, [
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return $this->sessionResult($session);
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionResult
    {
        try {
            $session = $this->client()->checkout->sessions->retrieve($sessionId, []);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return $this->sessionResult($session);
    }

    public function expireCheckoutSession(string $sessionId): CheckoutSessionResult
    {
        try {
            $session = $this->client()->checkout->sessions->expire($sessionId, []);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return $this->sessionResult($session);
    }

    /**
     * §11 — the PLATFORM account's own Price. No `stripe_account` request
     * option, deliberately: a Price on a connected account simply is not
     * retrievable here, which is how a lane-B or lane-C Price is structurally
     * prevented from being configured as a lane-A Price.
     */
    public function retrievePrice(string $providerPriceId): ProviderPriceSnapshot
    {
        try {
            $price = $this->client()->prices->retrieve($providerPriceId, []);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PRICE_NOT_RETRIEVABLE);
        }

        $recurring = $price->recurring ?? null;

        return new ProviderPriceSnapshot(
            id: (string) $price->id,
            active: (bool) ($price->active ?? false),
            currency: mb_strtoupper((string) ($price->currency ?? '')),
            unitAmount: isset($price->unit_amount) ? (int) $price->unit_amount : null,
            recurring: ((string) ($price->type ?? '')) === 'recurring',
            interval: $recurring->interval ?? null,
            intervalCount: isset($recurring->interval_count) ? (int) $recurring->interval_count : null,
            livemode: (bool) ($price->livemode ?? false),
            productId: $this->productIdOf($price),
        );
    }

    public function ensureProduct(string $name, ?string $existingProductId, string $idempotencyKey): string
    {
        try {
            if ($existingProductId !== null && $existingProductId !== '') {
                try {
                    $product = $this->client()->products->retrieve($existingProductId, []);

                    if ((bool) ($product->active ?? false)) {
                        return (string) $product->id;
                    }
                } catch (ApiErrorException) {
                    // Not retrievable on THIS account: fall through and create
                    // a fresh product rather than trusting a foreign id.
                }
            }

            $product = $this->client()->products->create(
                ['name' => mb_substr($name, 0, 250), 'metadata' => ['app_scope' => 'platform_plan']],
                ['idempotency_key' => $idempotencyKey],
            );
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return (string) $product->id;
    }

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
        try {
            $price = $this->client()->prices->create([
                'product' => $productId,
                'unit_amount' => $unitAmountMinor,
                'currency' => mb_strtolower($currencyCode),
                'recurring' => ['interval' => $interval, 'interval_count' => $intervalCount],
                'nickname' => mb_substr($nickname, 0, 250),
                'metadata' => $metadata,
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        $recurring = $price->recurring ?? null;

        return new ProviderPriceSnapshot(
            id: (string) $price->id,
            active: (bool) ($price->active ?? false),
            currency: mb_strtoupper((string) ($price->currency ?? '')),
            unitAmount: isset($price->unit_amount) ? (int) $price->unit_amount : null,
            recurring: ((string) ($price->type ?? '')) === 'recurring',
            interval: $recurring->interval ?? null,
            intervalCount: isset($recurring->interval_count) ? (int) $recurring->interval_count : null,
            livemode: (bool) ($price->livemode ?? false),
            productId: $this->productIdOf($price),
        );
    }

    public function applyPriceSchedule(string $providerSubscriptionId, array $phases, string $idempotencyKey): PlatformSubscriptionSnapshot
    {
        if (count($phases) < 2) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        try {
            $current = $this->client()->subscriptions->retrieve($providerSubscriptionId, []);

            // The schedule begins as an exact copy of the live subscription, so
            // its first phase carries the real start (and any running trial).
            //
            // A subscription can sit under only one schedule, and a schedule we
            // did not create is somebody's billing plan (a Dashboard change, a
            // Stripe automation) that this call must never overwrite. The only
            // attached schedule that is reused is OUR OWN, left by an earlier
            // interrupted attempt, and it is proven ours by the create call's
            // idempotency key: replaying the same key answers with the schedule
            // that attempt made. Anything else fails for operator attention.
            $existingScheduleId = self::scheduleIdOf($current);

            try {
                $created = $this->client()->subscriptionSchedules->create(
                    ['from_subscription' => $providerSubscriptionId],
                    ['idempotency_key' => $idempotencyKey . ':create'],
                );
            } catch (InvalidRequestException $e) {
                // The provider REFUSED a create while a schedule is attached and
                // the key is not a replay: that schedule is not ours. (Any other
                // failure -- network, rate limit, 5xx -- is inconclusive and falls
                // through to PROVIDER_FAILED below, so it is retried.)
                if ($existingScheduleId !== null) {
                    throw PlatformBillingException::because(PlatformBillingException::SCHEDULE_NOT_OURS);
                }

                throw $e;
            }

            if ($existingScheduleId !== null && (string) $created->id !== $existingScheduleId) {
                throw PlatformBillingException::because(PlatformBillingException::SCHEDULE_NOT_OURS);
            }

            $schedule = $existingScheduleId !== null
                ? $this->client()->subscriptionSchedules->retrieve($existingScheduleId, [])
                : $created;

            $trialEnd = $current->trial_end ?? null;
            $stillTrialing = is_int($trialEnd) && $trialEnd > time();
            $startDate = $schedule->phases[0]->start_date ?? ($current->start_date ?? null);

            $payload = [];

            foreach ($phases as $index => $phase) {
                $entry = ['items' => [['price' => $phase->priceId, 'quantity' => 1]]];

                if ($index === 0) {
                    if (is_int($startDate)) {
                        $entry['start_date'] = $startDate;
                    }

                    if ($stillTrialing) {
                        $entry['trial_end'] = $trialEnd;
                    }
                }

                // EVERY phase ends at an explicit instant. `iterations` was removed
                // from schedule create/update in API version 2025-09-30 (it now
                // errors), so it is never sent; the final phase therefore also
                // carries an end_date (one billing period after it starts), after
                // which `end_behavior=release` leaves the subscription in place on
                // that final Price.
                $entry['end_date'] = (int) $phase->endsAt->getTimestamp();

                // Moving between phases never creates a proration line or an
                // unplanned charge: the Prices are agreed in advance.
                $entry['proration_behavior'] = 'none';
                $payload[] = $entry;
            }

            $this->client()->subscriptionSchedules->update($schedule->id, [
                'end_behavior' => 'release',
                'phases' => $payload,
            ], ['idempotency_key' => $idempotencyKey . ':phases']);

            $subscription = $this->client()->subscriptions->retrieve($providerSubscriptionId, []);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return $this->subscriptionSnapshot($subscription);
    }

    public function proveOwnSchedule(string $providerSubscriptionId, string $idempotencyKey): ?string
    {
        try {
            $current = $this->client()->subscriptions->retrieve($providerSubscriptionId, []);
            $attached = self::scheduleIdOf($current);

            if ($attached === null) {
                return null;
            }

            // Replaying the push's create key answers with the schedule that push
            // made (inside Stripe's idempotency window).
            try {
                $created = $this->client()->subscriptionSchedules->create(
                    ['from_subscription' => $providerSubscriptionId],
                    ['idempotency_key' => $idempotencyKey . ':create'],
                );
            } catch (InvalidRequestException) {
                return null;
            }

            return (string) $created->id === $attached ? $attached : null;
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }
    }

    public function releaseSchedule(string $providerScheduleId): void
    {
        try {
            $schedule = $this->client()->subscriptionSchedules->retrieve($providerScheduleId, []);

            if (in_array((string) ($schedule->status ?? ''), ['released', 'completed', 'canceled'], true)) {
                return;
            }

            $this->client()->subscriptionSchedules->release($providerScheduleId, []);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }
    }

    /** The product a Price hangs off, whether the API answered an id or an expanded object. */
    private function productIdOf(object $price): ?string
    {
        $product = $price->product ?? null;

        return is_string($product) ? $product : (isset($product->id) ? (string) $product->id : null);
    }

    public function retrieveSubscription(string $providerSubscriptionId): PlatformSubscriptionSnapshot
    {
        try {
            $subscription = $this->client()->subscriptions->retrieve($providerSubscriptionId, []);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return $this->subscriptionSnapshot($subscription);
    }

    public function changeSubscriptionPrice(
        string $providerSubscriptionId,
        string $providerPriceId,
        bool $prorate,
        string $idempotencyKey,
    ): PlatformSubscriptionSnapshot {
        try {
            $current = $this->client()->subscriptions->retrieve($providerSubscriptionId, []);
            $itemId = $current->items->data[0]->id ?? null;

            if (! is_string($itemId)) {
                throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
            }

            $subscription = $this->client()->subscriptions->update($providerSubscriptionId, [
                'items' => [['id' => $itemId, 'price' => $providerPriceId]],
                // An upgrade bills the difference now (§10.2 "immediate"); a
                // deferred change does not create a proration line at all.
                'proration_behavior' => $prorate ? 'always_invoice' : 'none',
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return $this->subscriptionSnapshot($subscription);
    }

    public function setCancelAtPeriodEnd(string $providerSubscriptionId, bool $cancelAtPeriodEnd): PlatformSubscriptionSnapshot
    {
        try {
            $subscription = $this->client()->subscriptions->update($providerSubscriptionId, [
                'cancel_at_period_end' => $cancelAtPeriodEnd,
            ]);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return $this->subscriptionSnapshot($subscription);
    }

    public function createBillingPortalSession(string $providerCustomerId, string $returnUrl, ?string $flow = null): string
    {
        $params = ['customer' => $providerCustomerId, 'return_url' => $returnUrl];

        if ($flow !== null) {
            $params['flow_data'] = [
                'type' => $flow,
                // Come straight back to us rather than dropping the customer on
                // the portal home page after they have fixed their card.
                'after_completion' => ['type' => 'redirect', 'redirect' => ['return_url' => $returnUrl]],
            ];
        }

        try {
            $session = $this->client()->billingPortal->sessions->create($params);
        } catch (ApiErrorException) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        return (string) $session->url;
    }

    public function verifyWebhookPayload(string $rawPayload, string $signatureHeader): array
    {
        $secret = (string) config('services.stripe.platform_subscription_webhook.secret');

        if ($secret === '') {
            throw PlatformBillingException::because(PlatformBillingException::NOT_CONFIGURED);
        }

        try {
            $event = Webhook::constructEvent(
                $rawPayload,
                $signatureHeader,
                $secret,
                (int) config('services.stripe.platform_subscription_webhook.tolerance', 300),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            // The provider's own message echoes header and payload detail
            // straight into whatever logs the catch, so it is never
            // propagated.
            throw PlatformBillingException::because(PlatformBillingException::INVALID_SIGNATURE);
        }

        return $event->toArray();
    }

    public function configurationStatus(): array
    {
        $secret = (string) config('services.stripe.secret');
        $webhookSecret = (string) config('services.stripe.platform_subscription_webhook.secret');

        $configured = $secret !== '' && preg_match('/\Ask_(test|live)_/', $secret) === 1;

        // Booleans and a mode word only. No value, no prefix, no length.
        //
        // `mode` is NULL when the key is missing or malformed. Reporting
        // "test" merely because a key does not start `sk_live_` would tell an
        // operator their integration is safely in test mode when in truth it
        // is not configured at all — the one thing this panel exists to say.
        return [
            'configured' => $configured,
            'webhook_configured' => $webhookSecret !== '',
            'mode' => $configured ? (str_starts_with($secret, 'sk_live_') ? 'live' : 'test') : null,
        ];
    }

    private function sessionResult(object $session): CheckoutSessionResult
    {
        $customer = $session->customer ?? null;
        $subscription = $session->subscription ?? null;

        return new CheckoutSessionResult(
            sessionId: (string) $session->id,
            url: isset($session->url) ? (string) $session->url : null,
            customerId: is_string($customer) ? $customer : ($customer->id ?? null),
            subscriptionId: is_string($subscription) ? $subscription : ($subscription->id ?? null),
            status: isset($session->status) ? (string) $session->status : null,
            clientReferenceId: isset($session->client_reference_id) ? (string) $session->client_reference_id : null,
        );
    }

    /**
     * §11.8's rule in lane-A form — the provider's status string dies here.
     */
    private function subscriptionSnapshot(object $subscription): PlatformSubscriptionSnapshot
    {
        $item = $subscription->items->data[0] ?? null;
        $customer = $subscription->customer ?? null;

        // Current API: on the item. Older pinned versions: on the
        // subscription. Read both rather than assuming either.
        $periodStart = $item->current_period_start ?? $subscription->current_period_start ?? null;
        $periodEnd = $item->current_period_end ?? $subscription->current_period_end ?? null;

        return new PlatformSubscriptionSnapshot(
            providerSubscriptionId: (string) $subscription->id,
            providerCustomerId: is_string($customer) ? $customer : (string) ($customer->id ?? ''),
            status: PlatformProviderStatusMap::forSubscriptionStatus((string) $subscription->status),
            providerPriceId: $item->price->id ?? null,
            periodStart: self::timestamp($periodStart),
            periodEnd: self::timestamp($periodEnd),
            trialEndsAt: self::timestamp($subscription->trial_end ?? null),
            cancelAtPeriodEnd: (bool) ($subscription->cancel_at_period_end ?? false),
            canceledAt: self::timestamp($subscription->canceled_at ?? null),
            endedAt: self::timestamp($subscription->ended_at ?? null),
            operationId: $subscription->metadata->app_operation_id ?? null,
            scheduleId: self::scheduleIdOf($subscription),
        );
    }

    private static function scheduleIdOf(object $subscription): ?string
    {
        $schedule = $subscription->schedule ?? null;

        if (is_string($schedule) && $schedule !== '') {
            return $schedule;
        }

        return isset($schedule->id) ? (string) $schedule->id : null;
    }

    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? CarbonImmutable::createFromTimestampUTC((int) $value)
            : null;
    }
}
