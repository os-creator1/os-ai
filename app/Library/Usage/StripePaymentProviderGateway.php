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

        return new WebhookVerificationResult(
            $event->id,
            $event->type,
            $object->id,
            $rawBody,
            $metadata,
            $object->amount ?? null,
            isset($object->currency) ? strtoupper($object->currency) : null,
            isset($object->customer) ? (string) $object->customer : null,
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
            throw new ProviderCardDeclinedException($e->getDeclineCode());
        } catch (InvalidRequestException $e) {
            throw new ProviderInvalidRequestException($e->getMessage());
        } catch (ApiConnectionException) {
            throw new ProviderApiUnavailableException();
        } catch (ApiErrorException) {
            throw new ProviderApiUnavailableException();
        }
    }
}
