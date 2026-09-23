<?php

namespace App\Library\Payments;

use App\Exceptions\Payments\StripeConnectException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Implementation Contract 17 §12.D / §4.2 — the ONLY class in lane B that
 * touches the Stripe SDK.
 *
 * §11.2'S LOCKED POSTURE, EXPRESSED ONCE, IN CODE. Verified against the
 * official Stripe API reference (docs.stripe.com/api/accounts/create) at
 * implementation time:
 *
 *   controller[fees][payer]            = account  -> the connected Business
 *                                        pays Stripe's fees
 *   controller[losses][payments]       = stripe   -> the platform assumes no
 *                                        negative-balance liability
 *   controller[requirement_collection] = stripe   -> Stripe-hosted onboarding
 *                                        collects and re-collects KYC
 *   controller[stripe_dashboard][type] = full     -> full Stripe-hosted
 *                                        Dashboard access
 *
 * Those four are exactly the documented enum values, and they are set
 * EXPLICITLY even though each is currently Stripe's default: the commercial
 * posture must be auditable in our source and must not silently change if a
 * provider default ever does.
 *
 * The deprecated `type` parameter (standard / express / custom) is
 * deliberately NOT sent. Stripe's own reference states: "The `type` parameter
 * is deprecated. Use `controller` instead to configure dashboard access, fee
 * payer, loss liability, and requirement collection." §11.2 forbids falling
 * back to that legacy architecture.
 *
 * DIRECT CHARGES, NO INTERMEDIATION. Nothing here sets
 * `application_fee_amount` or `on_behalf_of`, and nothing here moves money at
 * all — money movement is Sub-slice E.
 *
 * NO SECRET EVER LEAVES THIS CLASS. The API key is read from config once, its
 * mode prefix is asserted, and it is never returned, logged or interpolated
 * into an exception. Provider error text is swallowed for the same reason:
 * every failure becomes StripeConnectException::providerFailed().
 */
final class StripeApiConnectGateway implements StripeConnectGateway
{
    private ?StripeClient $client = null;

    /**
     * Built LAZILY, and fail-closed at the point of use rather than at
     * construction.
     *
     * The distinction is load-bearing: this gateway is a constructor
     * dependency of PaymentManager, which is itself a dependency of the
     * PUBLIC document controller. Throwing here would mean a platform with no
     * Stripe key configured could not even render a document — or its uniform
     * refusal page — turning a configuration gap into a 500 on an
     * unauthenticated surface. Refusing when a provider call is actually
     * attempted is equally closed and far better behaved.
     *
     * The key is validated but never echoed: not the value, not a prefix, not
     * a length.
     */
    private function client(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $secret = (string) config('services.stripe.secret');

        if ($secret === '' || ! preg_match('/\Ask_(test|live)_/', $secret)) {
            throw StripeConnectException::notConfigured();
        }

        return $this->client = new StripeClient($secret);
    }

    public function createAccount(string $country, ?string $email, string $businessUid): ConnectedAccountSnapshot
    {
        try {
            $account = $this->client()->accounts->create([
                'country' => $country,
                'email' => $email,
                'controller' => [
                    'fees' => ['payer' => 'account'],
                    'losses' => ['payments' => 'stripe'],
                    'requirement_collection' => 'stripe',
                    'stripe_dashboard' => ['type' => 'full'],
                ],
                // §11.2 — merchant / card-payments configuration required.
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                // Our own opaque handle, so an account can be traced back to a
                // Business without us ever trusting it as an authorization
                // input. Nothing reads this back to decide access.
                'metadata' => ['business_uid' => $businessUid],
            ]);
        } catch (ApiErrorException) {
            throw StripeConnectException::providerFailed();
        }

        return $this->snapshot($account);
    }

    public function createOnboardingLink(string $stripeAccountId, string $refreshUrl, string $returnUrl): string
    {
        try {
            $link = $this->client()->accountLinks->create([
                'account' => $stripeAccountId,
                'refresh_url' => $refreshUrl,
                'return_url' => $returnUrl,
                'type' => 'account_onboarding',
            ]);
        } catch (ApiErrorException) {
            throw StripeConnectException::providerFailed();
        }

        return (string) $link->url;
    }

    public function retrieveAccount(string $stripeAccountId): ConnectedAccountSnapshot
    {
        try {
            $account = $this->client()->accounts->retrieve($stripeAccountId, []);
        } catch (ApiErrorException) {
            throw StripeConnectException::providerFailed();
        }

        return $this->snapshot($account);
    }

    /**
     * §7.2 / §11.2 — a DIRECT CHARGE on the connected account.
     *
     * Verified against the official Stripe direct-charges guide at
     * implementation time: the connected account is named by the
     * `Stripe-Account` header (the SDK's `stripe_account` request option),
     * and `application_fee_amount` is optional — so it is omitted, because
     * §11.2 forbids the platform taking a cut in V1. No `on_behalf_of` and no
     * `transfer_data` either: the Business is the merchant of record and the
     * platform does not intermediate its revenue.
     *
     * The Stripe idempotency key is the caller's
     * `document-payment:{payment_uid}`, so repeating an uncertain creation
     * returns Stripe's ORIGINAL intent instead of charging twice (§7.2.1
     * Case B).
     */
    public function createPaymentIntent(
        string $connectedAccountId,
        int $amountMinor,
        string $currencyCode,
        string $idempotencyKey,
        string $operationId,
        string $description,
    ): PaymentIntentSnapshot {
        try {
            $intent = $this->client()->paymentIntents->create([
                'amount' => $amountMinor,
                'currency' => strtolower($currencyCode),
                'automatic_payment_methods' => ['enabled' => true],
                'description' => mb_substr($description, 0, 350),
                // Read back by the finalizer to prove operation identity (§8.3).
                'metadata' => ['app_operation_id' => $operationId],
            ], [
                'stripe_account' => $connectedAccountId,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (ApiErrorException) {
            throw StripeConnectException::providerFailed();
        }

        return $this->intentSnapshot($intent, $connectedAccountId, withClientSecret: true);
    }

    public function retrievePaymentIntent(string $connectedAccountId, string $providerPaymentIntentId): PaymentIntentSnapshot
    {
        try {
            $intent = $this->client()->paymentIntents->retrieve($providerPaymentIntentId, [], [
                'stripe_account' => $connectedAccountId,
            ]);
        } catch (ApiErrorException) {
            throw StripeConnectException::providerFailed();
        }

        return $this->intentSnapshot($intent, $connectedAccountId, withClientSecret: true);
    }

    /**
     * §8.2 step 1 — signature verification over the raw body, with the
     * DEDICATED Connect webhook secret. One platform-level Connect endpoint
     * receives events for every connected account, and each event carries its
     * own `account` field; that field, never a per-Business secret, is what
     * routes an event (§5.8).
     */
    public function verifyWebhookPayload(string $rawPayload, string $signatureHeader): array
    {
        $secret = (string) config('services.stripe.connect_webhook.secret');

        if ($secret === '') {
            throw StripeConnectException::notConfigured();
        }

        try {
            $event = Webhook::constructEvent(
                $rawPayload,
                $signatureHeader,
                $secret,
                (int) config('services.stripe.connect_webhook.tolerance', 300),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            // The provider's own message is never propagated: it echoes header
            // and payload detail straight into whatever logs the catch.
            throw StripeConnectException::invalidSignature();
        }

        return $event->toArray();
    }

    /**
     * §7.4 — a refund on the payment's own historical connected account.
     *
     * Refunding by PaymentIntent (rather than by charge id) lets Stripe pick
     * the right charge, which keeps us from having to store and trust a
     * second provider identifier. `refund_application_fee` is deliberately
     * absent: §11.2 takes no application fee, so there is none to refund.
     */
    public function createRefund(
        string $connectedAccountId,
        string $providerPaymentIntentId,
        int $amountMinor,
        string $idempotencyKey,
        string $operationId,
    ): RefundSnapshot {
        try {
            $refund = $this->client()->refunds->create([
                'payment_intent' => $providerPaymentIntentId,
                'amount' => $amountMinor,
                // Recorded for our own tracing. It is NOT relied on when the
                // event comes back: §4.5/§8.3 note that Stripe refund
                // metadata is independent and never inherited, so resolution
                // is by provider reference.
                'metadata' => ['app_operation_id' => $operationId],
            ], [
                'stripe_account' => $connectedAccountId,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (ApiErrorException) {
            throw StripeConnectException::providerFailed();
        }

        return $this->refundSnapshot($refund, $connectedAccountId);
    }

    public function retrieveRefund(string $connectedAccountId, string $providerRefundId): RefundSnapshot
    {
        try {
            $refund = $this->client()->refunds->retrieve($providerRefundId, [], [
                'stripe_account' => $connectedAccountId,
            ]);
        } catch (ApiErrorException) {
            throw StripeConnectException::providerFailed();
        }

        return $this->refundSnapshot($refund, $connectedAccountId);
    }

    private function refundSnapshot(object $refund, string $connectedAccountId): RefundSnapshot
    {
        $charge = $refund->charge ?? null;

        return new RefundSnapshot(
            providerRefundId: (string) $refund->id,
            status: ProviderStatusMap::forRefundStatus((string) $refund->status),
            amountMinor: (int) $refund->amount,
            currencyCode: mb_strtoupper((string) $refund->currency),
            connectedAccountId: $connectedAccountId,
            operationId: $refund->metadata->app_operation_id ?? null,
            providerChargeId: is_string($charge) ? $charge : ($charge->id ?? null),
        );
    }

    /**
     * §11.8 — the provider's status string dies here. Everything past this
     * point speaks only our six local values.
     */
    private function intentSnapshot(object $intent, string $connectedAccountId, bool $withClientSecret): PaymentIntentSnapshot
    {
        $charge = $intent->latest_charge ?? null;

        return new PaymentIntentSnapshot(
            providerPaymentIntentId: (string) $intent->id,
            status: ProviderStatusMap::forIntentStatus((string) $intent->status),
            amountMinor: (int) $intent->amount,
            currencyCode: mb_strtoupper((string) $intent->currency),
            connectedAccountId: $connectedAccountId,
            operationId: $intent->metadata->app_operation_id ?? null,
            providerChargeId: is_string($charge) ? $charge : ($charge->id ?? null),
            failureCode: $intent->last_payment_error->code ?? null,
            clientSecret: $withClientSecret ? ($intent->client_secret ?? null) : null,
        );
    }

    /**
     * Normalizes the provider object down to the §5.7 material and nothing
     * else. Requirements detail, persons, external accounts and every other
     * field are deliberately dropped rather than carried inward.
     */
    private function snapshot(object $account): ConnectedAccountSnapshot
    {
        $disabledReason = $account->requirements->disabled_reason ?? null;

        return new ConnectedAccountSnapshot(
            stripeAccountId: (string) $account->id,
            chargesEnabled: (bool) ($account->charges_enabled ?? false),
            payoutsEnabled: (bool) ($account->payouts_enabled ?? false),
            detailsSubmitted: (bool) ($account->details_submitted ?? false),
            requirementsDisabledReason: $disabledReason === null ? null : mb_substr((string) $disabledReason, 0, 120),
            defaultCurrency: isset($account->default_currency) ? mb_strtoupper((string) $account->default_currency) : null,
        );
    }
}
