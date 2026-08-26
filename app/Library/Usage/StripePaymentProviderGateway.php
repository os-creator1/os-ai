<?php

namespace App\Library\Usage;

use App\Exceptions\Usage\ProviderApiUnavailableException;
use App\Exceptions\Usage\ProviderAuthenticationException;
use App\Exceptions\Usage\ProviderCardDeclinedException;
use App\Exceptions\Usage\ProviderInvalidRequestException;
use App\Exceptions\Usage\ProviderRateLimitException;
use App\Exceptions\Usage\WebhookSignatureVerificationException;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException as StripeAuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * M3 contract §8 — the sole class in this repository permitted to
 * reference a Stripe\* SDK class. Every method here returns a normalized
 * DTO (App\Library\Usage\*Result), never a raw Stripe object. Every
 * outbound call must be invoked with no open database transaction/lock
 * held by the caller.
 */
class StripePaymentProviderGateway implements PaymentProviderGateway
{
    private StripeClient $client;

    /**
     * M3 contract §19 — fails closed before any request is served: an
     * unset/invalid mode, an empty secret/webhook secret/api_version, or a
     * secret-key prefix (sk_test_/sk_live_) disagreeing with the
     * configured mode, all throw here rather than silently proceeding
     * with a live key while mode is test or vice versa.
     */
    public function __construct()
    {
        $mode = config('services.stripe.mode');
        $secret = (string) config('services.stripe.secret');
        $webhookSecret = config('services.stripe.webhook.secret');
        $apiVersion = config('services.stripe.api_version');

        if (! in_array($mode, ['test', 'live'], true)) {
            throw new \RuntimeException('services.stripe.mode must be "test" or "live".');
        }

        if ($secret === '') {
            throw new \RuntimeException('services.stripe.secret must not be empty.');
        }

        if (blank($webhookSecret)) {
            throw new \RuntimeException('services.stripe.webhook.secret must not be empty.');
        }

        if (blank($apiVersion)) {
            throw new \RuntimeException('services.stripe.api_version must not be empty.');
        }

        $expectedPrefix = 'sk_'.$mode.'_';

        if (! str_starts_with($secret, $expectedPrefix)) {
            throw new \RuntimeException('services.stripe.secret does not match the configured services.stripe.mode.');
        }

        $this->client = new StripeClient([
            'api_key' => $secret,
            'stripe_version' => StripeApiVersion::current(),
        ]);
    }

    public function createOrRetrieveCustomer(?string $existingProviderCustomerId, string $idempotencyKey): ProviderCustomerResult
    {
        if ($existingProviderCustomerId !== null) {
            $customer = $this->call(fn () => $this->client->customers->retrieve($existingProviderCustomerId));

            return new ProviderCustomerResult($customer->id, false);
        }

        $customer = $this->call(
            fn () => $this->client->customers->create([], ['idempotency_key' => $idempotencyKey]),
        );

        return new ProviderCustomerResult($customer->id, true);
    }

    public function createSetupIntent(string $providerCustomerId, string $idempotencyKey): SetupIntentResult
    {
        $setupIntent = $this->call(
            fn () => $this->client->setupIntents->create([
                'customer' => $providerCustomerId,
                'usage' => 'off_session',
            ], ['idempotency_key' => $idempotencyKey]),
        );

        return new SetupIntentResult($setupIntent->id, $setupIntent->client_secret, $setupIntent->status, $setupIntent->payment_method ?? null);
    }

    public function retrieveSetupIntent(string $providerSetupIntentId): SetupIntentResult
    {
        $setupIntent = $this->call(fn () => $this->client->setupIntents->retrieve($providerSetupIntentId));

        return new SetupIntentResult($setupIntent->id, null, $setupIntent->status, $setupIntent->payment_method ?? null);
    }

    public function retrievePaymentMethod(string $providerPaymentMethodId): PaymentMethodResult
    {
        $paymentMethod = $this->call(fn () => $this->client->paymentMethods->retrieve($providerPaymentMethodId));
        $card = $paymentMethod->card;

        return new PaymentMethodResult(
            $paymentMethod->id,
            (string) $paymentMethod->customer,
            $paymentMethod->type,
            $card->brand ?? null,
            $card->last4 ?? null,
            $card->exp_month ?? null,
            $card->exp_year ?? null,
        );
    }

    public function detachPaymentMethod(string $providerPaymentMethodId): void
    {
        $this->call(fn () => $this->client->paymentMethods->detach($providerPaymentMethodId));
    }

    public function createOffSessionPaymentIntent(
        string $providerCustomerId,
        string $providerPaymentMethodId,
        int $amountMinorUnits,
        string $currencyCode,
        string $idempotencyKey,
        array $metadata,
    ): PaymentIntentResult {
        $paymentIntent = $this->call(
            fn () => $this->client->paymentIntents->create([
                'amount' => $amountMinorUnits,
                'currency' => strtolower($currencyCode),
                'customer' => $providerCustomerId,
                'payment_method' => $providerPaymentMethodId,
                'off_session' => true,
                'confirm' => true,
                'metadata' => $metadata,
            ], ['idempotency_key' => $idempotencyKey]),
        );

        return new PaymentIntentResult(
            $paymentIntent->id,
            $paymentIntent->status,
            $paymentIntent->client_secret,
            $paymentIntent->amount,
            strtoupper($paymentIntent->currency),
        );
    }

    public function retrievePaymentIntent(string $providerPaymentIntentId): PaymentIntentResult
    {
        $paymentIntent = $this->call(fn () => $this->client->paymentIntents->retrieve($providerPaymentIntentId));

        return new PaymentIntentResult(
            $paymentIntent->id,
            $paymentIntent->status,
            $paymentIntent->client_secret,
            $paymentIntent->amount,
            strtoupper($paymentIntent->currency),
        );
    }

    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret): WebhookVerificationResult
    {
        try {
            $event = Webhook::constructEvent($rawBody, $signatureHeader, $webhookSecret);
        } catch (UnexpectedValueException|SignatureVerificationException) {
            throw new WebhookSignatureVerificationException();
        }

        $object = $event->data->object;
        $metadata = isset($object->metadata) ? $object->metadata->toArray() : [];

        // M4 contract §15b — a Checkout Session event carries amount_total,
        // never amount; every existing PaymentIntent/Charge-shaped event
        // still normalizes via amount first, unchanged.
        $amountMinorUnits = $object->amount ?? $object->amount_total ?? null;

        return new WebhookVerificationResult(
            $event->id,
            $event->type,
            $object->id,
            $rawBody,
            $metadata,
            $amountMinorUnits,
            isset($object->currency) ? strtoupper($object->currency) : null,
            isset($object->customer) ? (string) $object->customer : null,
        );
    }

    /**
     * M4 contract §15a — mode: 'payment' with exactly one non-adjustable
     * line item built from inline price_data (never a separately-created
     * Stripe Price/Product); unit_amount derives only from the caller's
     * own frozen amount.
     *
     * RFC-005 Funding Provider-Flow Correction Contract §8.A/§8.B —
     * payment_intent_data.setup_future_usage: 'off_session' is included
     * only when $setupFutureUsageOffSession is true (the additional-slot
     * agreement's own initial charge, establishing the card for later
     * off-session renewal); omitted entirely for a one-time Checkout
     * Session (ManualTopUp/AddonPurchase), which must never automatically
     * establish future off-session authority.
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
        $params = [
            'mode' => 'payment',
            'customer' => $providerCustomerId,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currencyCode),
                    'unit_amount' => $amountMinorUnits,
                    'product_data' => [
                        'name' => $lineItemName,
                    ],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
        ];

        if ($setupFutureUsageOffSession) {
            $params['payment_intent_data'] = ['setup_future_usage' => 'off_session'];
        }

        $session = $this->call(
            fn () => $this->client->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey]),
        );

        if (blank($session->url)) {
            throw new ProviderInvalidRequestException('Checkout Session was created with no redirect url.');
        }

        return $this->mapCheckoutSession($session, null);
    }

    public function retrieveCheckoutSession(string $providerCheckoutSessionId): CheckoutSessionResult
    {
        $session = $this->call(
            fn () => $this->client->checkout->sessions->retrieve($providerCheckoutSessionId, [
                'expand' => ['payment_intent.payment_method'],
            ]),
        );

        $providerPaymentMethodId = null;

        if ($session->status === 'complete' && isset($session->payment_intent->payment_method)) {
            $paymentMethod = $session->payment_intent->payment_method;
            $providerPaymentMethodId = is_string($paymentMethod) ? $paymentMethod : ($paymentMethod->id ?? null);
        }

        return $this->mapCheckoutSession($session, $providerPaymentMethodId);
    }

    /**
     * M4 contract §23 — re-confirms an existing PaymentIntent against a
     * (possibly new) payment method; a genuine second provider-side
     * attempt, never a bare re-retrieval.
     */
    public function confirmPaymentIntent(
        string $providerPaymentIntentId,
        string $providerPaymentMethodId,
        string $idempotencyKey,
    ): PaymentIntentResult {
        $paymentIntent = $this->call(
            fn () => $this->client->paymentIntents->confirm($providerPaymentIntentId, [
                'payment_method' => $providerPaymentMethodId,
            ], ['idempotency_key' => $idempotencyKey]),
        );

        return new PaymentIntentResult(
            $paymentIntent->id,
            $paymentIntent->status,
            $paymentIntent->client_secret,
            $paymentIntent->amount,
            strtoupper($paymentIntent->currency),
        );
    }

    private function mapCheckoutSession($session, ?string $providerPaymentMethodId): CheckoutSessionResult
    {
        $paymentIntentId = $session->payment_intent ?? null;

        if (! is_string($paymentIntentId)) {
            $paymentIntentId = $paymentIntentId->id ?? null;
        }

        return new CheckoutSessionResult(
            $session->id,
            $session->status,
            $session->payment_status,
            $session->url ?? null,
            (int) $session->amount_total,
            strtoupper($session->currency),
            (string) $session->customer,
            $paymentIntentId,
            $providerPaymentMethodId,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(callable $call): mixed
    {
        try {
            return $call();
        } catch (StripeAuthenticationException) {
            throw new ProviderAuthenticationException();
        } catch (RateLimitException) {
            throw new ProviderRateLimitException();
        } catch (CardException $e) {
            // M4 contract §21 (Correction Round 2 §B) — the SDK documents
            // payment_intent as populated on the ErrorObject for a
            // PaymentIntent-involving error (exactly what create-with-
            // confirm and confirm both are), but does not guarantee it;
            // extracted opportunistically here for every card decline
            // regardless of which method triggered it — harmless for
            // callers (e.g. confirmPaymentIntent()'s own retries) that
            // never read the new field, and this is the only reference a
            // hard decline on attempt 1 (createOffSessionPaymentIntent())
            // will ever have.
            throw new ProviderCardDeclinedException($e->getDeclineCode(), $e->getError()?->payment_intent?->id ?? null);
        } catch (InvalidRequestException $e) {
            throw new ProviderInvalidRequestException($e->getMessage());
        } catch (ApiConnectionException) {
            throw new ProviderApiUnavailableException();
        } catch (ApiErrorException) {
            throw new ProviderApiUnavailableException();
        }
    }
}
