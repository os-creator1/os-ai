<?php

namespace App\Library\PlatformBilling;

use App\Exceptions\PlatformBilling\PlatformBillingException;
use Carbon\CarbonImmutable;
use Stripe\Exception\ApiErrorException;
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
            'subscription_data' => [
                // Read back by the finalizer to prove operation identity.
                'metadata' => ['app_operation_id' => $clientReferenceId],
            ],
        ];

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

        // Booleans and a mode word only. No value, no prefix, no length.
        return [
            'configured' => $secret !== '' && preg_match('/\Ask_(test|live)_/', $secret) === 1,
            'webhook_configured' => $webhookSecret !== '',
            'mode' => str_starts_with($secret, 'sk_live_') ? 'live' : 'test',
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
        );
    }

    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? CarbonImmutable::createFromTimestampUTC((int) $value)
            : null;
    }
}
