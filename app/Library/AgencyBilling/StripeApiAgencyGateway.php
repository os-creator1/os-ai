<?php

namespace App\Library\AgencyBilling;

use App\Exceptions\AgencyBilling\AgencyBillingException;
use Carbon\CarbonImmutable;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Lane C §C4 — the ONLY class in lane C permitted to reference a `Stripe\*` SDK
 * class.
 *
 * EVERY REVENUE CALL CARRIES `stripe_account`. That single request option is
 * what makes the money land in the AGENCY's balance instead of ours, and what
 * makes another Agency's objects invisible. It is applied in one private helper
 * (`onAccount()`) so there is exactly one place to read to be sure of it, and
 * no method here can be written that forgets it.
 *
 * NO PLATFORM CUT. No `application_fee_amount`, no `transfer_data`, no
 * `on_behalf_of`. The Agency's resale revenue is the Agency's, exactly as
 * Contract 17 §11.2 keeps a Business's revenue the Business's.
 *
 * THE CLIENT IS BUILT LAZILY and fails closed at the point of use, never at
 * construction — a gateway that throws from its constructor turns a missing
 * configuration key into a 500 on whatever page happens to depend on it. The
 * key is validated but never echoed: not the value, not a prefix, not a length.
 *
 * PROVIDER ERROR TEXT DIES HERE. Every `ApiErrorException` becomes a lane-C
 * reason code with our own copy, because the provider's message can carry
 * customer ids, account ids, request ids and request detail.
 *
 * PERIOD NORMALIZATION. In the current Stripe API `current_period_start` and
 * `current_period_end` live on the SUBSCRIPTION ITEM; older pinned API versions
 * expose them on the subscription object. Both are read, so nothing downstream
 * has to know which shape the account's pinned version answered with.
 */
final class StripeApiAgencyGateway implements AgencyStripeGateway
{
    /**
     * PR #380 finding 3 (lock-lease duration) — the single hard cap
     * `AgencyStripeConnectManager::REFRESH_LOCK_TTL_SECONDS` is sized
     * against for `retrieveAccount()`'s own worst case.
     *
     * curl enforces `CURLOPT_TIMEOUT` as a ceiling on the WHOLE request,
     * connect phase included, not additive with `CURLOPT_CONNECTTIMEOUT`
     * (\Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT = 80,
     * vendor/stripe/stripe-php/lib/HttpClient/CurlClient.php:164). Neither
     * this gateway nor any other Stripe gateway in this codebase calls
     * `setTimeout()`/`setConnectTimeout()`, so that 80s default governs.
     *
     * Retries would multiply that bound — `Stripe::$maxNetworkRetries`
     * defaults to 0 (lib/Stripe.php:47) and nothing in this codebase raises
     * it today, but it is a process-wide mutable static, not a per-call
     * setting, so `retrieveAccount()` below pins it to 0 immediately before
     * its own call rather than trusting that ambient state stays put.
     * With that pin in effect, one call is the whole story: no retry
     * attempt, no backoff sleep, ever follows a first one for this method.
     *
     * This is a real, curl-enforced ceiling, not a typical-case estimate —
     * but only for what this class controls. It does not bound proxying,
     * DNS, or OS-level network stalls beneath curl's own connect handling.
     *
     * `public` so `AgencyStripeConnectManager::REFRESH_LOCK_TTL_SECONDS` can
     * be defined as a real arithmetic expression against this constant
     * instead of a second, independently-maintained magic number that could
     * silently drift out of sync with it.
     */
    public const RETRIEVE_ACCOUNT_WORST_CASE_SECONDS = 80;

    private ?StripeClient $client = null;

    private function client(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $secret = (string) config('services.stripe.secret');

        if ($secret === '' || ! preg_match('/\Ask_(test|live)_/', $secret)) {
            throw AgencyBillingException::because(AgencyBillingException::NOT_CONFIGURED);
        }

        return $this->client = new StripeClient($secret);
    }

    /**
     * THE one place the connected account is applied. Every revenue call routes
     * its request options through here, so "did we remember the account?" has a
     * single answer rather than one per method.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function onAccount(string $connectedAccountId, array $extra = []): array
    {
        if (trim($connectedAccountId) === '') {
            // Refusing beats defaulting: an empty account would silently send
            // an Agency's charge to the PLATFORM's balance.
            throw AgencyBillingException::because(AgencyBillingException::NO_CONNECTION);
        }

        return array_merge(['stripe_account' => $connectedAccountId], $extra);
    }

    // =====================================================================
    // §C5.1 — connection
    // =====================================================================

    public function createAccount(string $country, ?string $email, string $agencyWorkspaceUid): AgencyAccountSnapshot
    {
        try {
            $account = $this->client()->accounts->create(self::accountCreateParams($country, $email, $agencyWorkspaceUid));
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return $this->accountSnapshot($account);
    }

    /**
     * @return array<string, mixed>
     */
    public static function accountCreateParams(string $country, ?string $email, string $agencyWorkspaceUid): array
    {
        // The commercial posture is fixed here and cannot be passed in, and
        // mirrors Lane B's own account-creation shape exactly
        // (StripeApiConnectGateway::accountCreateParams()) — the same
        // `controller` parameters that already work for real direct charges
        // in this environment. The deprecated `type` parameter is never
        // set: Stripe refuses direct-charge Checkout Sessions
        // (self::onAccount()'s `stripe_account` header) against a
        // type=express/custom account created on a platform created after
        // Stripe's 2024 policy cutoff ("Creating direct charges with
        // type=express or type=custom is not supported for new
        // platforms."), and `controller` is Stripe's documented
        // replacement — it is not merely a dashboard-styling choice.
        //
        //   controller[fees][payer]            = account  -> the connected
        //                                        Agency pays Stripe's fees
        //   controller[losses][payments]       = stripe   -> the platform
        //                                        assumes no liability
        //   controller[requirement_collection] = stripe   -> Stripe-hosted
        //                                        onboarding and identity
        //                                        collection, same as before
        //   controller[stripe_dashboard][type] = full     -> full
        //                                        Stripe-hosted dashboard;
        //                                        the Agency operates its
        //                                        own payments and refunds
        //
        // Together with onAccount()'s `stripe_account` header and the
        // absence of `application_fee_amount`/`transfer_data`/
        // `on_behalf_of` anywhere in this class, the Agency's revenue stays
        // the Agency's and the platform is never a party to the charge —
        // unchanged from before this correction.
        $params = [
            'country' => $country,
            'controller' => [
                'fees' => ['payer' => 'account'],
                'losses' => ['payments' => 'stripe'],
                'requirement_collection' => 'stripe',
                'stripe_dashboard' => ['type' => 'full'],
            ],
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'business_type' => 'company',
            'metadata' => ['app_agency_workspace_uid' => $agencyWorkspaceUid],
        ];

        // Stripe rejects a present-but-empty email ("Invalid email address: ")
        // rather than treating it as absent. Passing 'email' => null here
        // serializes to that same empty string over the wire, so the key is
        // omitted entirely instead when there is no email to send.
        if ($email !== null) {
            $params['email'] = $email;
        }

        return $params;
    }

    public function createOnboardingLink(string $connectedAccountId, string $refreshUrl, string $returnUrl): string
    {
        try {
            $link = $this->client()->accountLinks->create([
                'account' => $connectedAccountId,
                'refresh_url' => $refreshUrl,
                'return_url' => $returnUrl,
                'type' => 'account_onboarding',
            ]);
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return (string) $link->url;
    }

    public function retrieveAccount(string $connectedAccountId): AgencyAccountSnapshot
    {
        // Deterministically pin retries to 0 for THIS call, immediately
        // before making it, rather than trusting the SDK's already-0
        // default to stay unset for the rest of the request — see
        // RETRIEVE_ACCOUNT_WORST_CASE_SECONDS above. `Stripe::$maxNetworkRetries`
        // is a process-wide static; setting it to its own default here costs
        // nothing and turns "no other code path touches this" from an
        // assumption into an enforced fact for the one call this codebase's
        // status-poll lock lease is sized against.
        \Stripe\Stripe::setMaxNetworkRetries(0);

        try {
            $account = $this->client()->accounts->retrieve($connectedAccountId, []);
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return $this->accountSnapshot($account);
    }

    /**
     * "Connect existing Stripe account" — Stripe's own documented OAuth
     * connection flow (https://docs.stripe.com/connect/oauth-standard-accounts),
     * which is explicitly still the supported path for "an application that
     * needs access to an EXISTING account" (as opposed to onboarding a brand
     * new one, which stays createAccount() above). No account id is ever
     * typed into this application: Stripe authenticates the Agency owner on
     * its own hosted page and hands back a one-time authorization code.
     */
    public function oauthAuthorizeUrl(string $state, string $redirectUri): string
    {
        $clientId = (string) config('services.stripe.agency_connect_client_id');

        if ($clientId === '') {
            throw AgencyBillingException::because(AgencyBillingException::NOT_CONFIGURED);
        }

        // Pure URL construction — no network call, so no ApiErrorException
        // path exists here.
        return \Stripe\OAuth::authorizeUrl([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'read_write',
        ]);
    }

    /**
     * Exchanges the one-time authorization code Stripe returned to the OAuth
     * `redirect_uri` for the connected account's real id. Never returns or
     * stores the `access_token`/`refresh_token` Stripe also returns in this
     * response — lane C authenticates every subsequent call the same way it
     * always has, with the platform's own secret key plus the `Stripe-Account`
     * header (`onAccount()`), never a per-Agency OAuth token, so there is no
     * additional credential for this class to protect or leak.
     *
     * @throws AgencyBillingException
     */
    public function exchangeOAuthCode(string $authorizationCode): string
    {
        // \Stripe\OAuth::token() is the one legacy call in this class with no
        // per-request api-key parameter (unlike every other call here, scoped
        // through $this->client()) — it authenticates through the SDK's own
        // global key, so client() is still called first, purely to fail
        // closed identically to every other method when unconfigured, and
        // the global key is set immediately before the one call that needs it.
        $this->client();
        \Stripe\Stripe::setApiKey((string) config('services.stripe.secret'));

        try {
            $response = \Stripe\OAuth::token([
                'grant_type' => 'authorization_code',
                'code' => $authorizationCode,
            ]);
        } catch (\Throwable) {
            // Covers Stripe\Exception\OAuth\OAuthErrorException (an invalid,
            // expired or already-used code) and any other transport failure
            // identically — the provider's own error text still never
            // reaches a caller.
            throw AgencyBillingException::because(AgencyBillingException::OAUTH_FAILED);
        }

        $accountId = isset($response->stripe_user_id) ? (string) $response->stripe_user_id : '';

        if ($accountId === '') {
            throw AgencyBillingException::because(AgencyBillingException::OAUTH_FAILED);
        }

        return $accountId;
    }

    // =====================================================================
    // §C5.2 — Prices on the Agency's own account
    // =====================================================================

    public function retrievePrice(string $connectedAccountId, string $providerPriceId): AgencyPriceSnapshot
    {
        try {
            $price = $this->client()->prices->retrieve(
                $providerPriceId,
                [],
                self::onAccount($connectedAccountId),
            );
        } catch (ApiErrorException) {
            // Includes the important case: a Price that exists on the PLATFORM
            // account or on a DIFFERENT Agency's account is simply not visible
            // through this account's header.
            throw AgencyBillingException::because(AgencyBillingException::PRICE_NOT_RETRIEVABLE);
        }

        return $this->priceSnapshot($price);
    }

    public function createPrice(
        string $connectedAccountId,
        string $productName,
        int $unitAmountMinor,
        string $currencyCode,
        string $interval,
        string $idempotencyKey,
    ): AgencyPriceSnapshot {
        try {
            $price = $this->client()->prices->create([
                'currency' => mb_strtolower($currencyCode),
                'unit_amount' => $unitAmountMinor,
                'recurring' => ['interval' => $interval, 'interval_count' => 1],
                // An inline product keeps the Agency out of Stripe's dashboard
                // entirely for the common case.
                'product_data' => ['name' => mb_substr($productName, 0, 250)],
            ], self::onAccount($connectedAccountId, ['idempotency_key' => $idempotencyKey]));
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return $this->priceSnapshot($price);
    }

    // =====================================================================
    // §C7 — the client's subscription
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
            // The classic free trial, which is what Checkout supports. Minimum
            // 1 day, maximum 730, per the current reference.
            $params['subscription_data']['trial_period_days'] = min($trialDays, 730);
            $params['subscription_data']['trial_settings'] = [
                'end_behavior' => ['missing_payment_method' => 'create_invoice'],
            ];
        }

        try {
            $session = $this->client()->checkout->sessions->create(
                $params,
                self::onAccount($connectedAccountId, ['idempotency_key' => $idempotencyKey]),
            );
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return $this->sessionResult($session);
    }

    public function retrieveCheckoutSession(string $connectedAccountId, string $sessionId): AgencyCheckoutSessionResult
    {
        try {
            $session = $this->client()->checkout->sessions->retrieve(
                $sessionId,
                [],
                self::onAccount($connectedAccountId),
            );
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return $this->sessionResult($session);
    }

    public function expireCheckoutSession(string $connectedAccountId, string $sessionId): AgencyCheckoutSessionResult
    {
        try {
            $session = $this->client()->checkout->sessions->expire(
                $sessionId,
                [],
                self::onAccount($connectedAccountId),
            );
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return $this->sessionResult($session);
    }

    public function retrieveSubscription(string $connectedAccountId, string $providerSubscriptionId): AgencySubscriptionSnapshot
    {
        try {
            $subscription = $this->client()->subscriptions->retrieve(
                $providerSubscriptionId,
                [],
                self::onAccount($connectedAccountId),
            );
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return $this->subscriptionSnapshot($subscription, $connectedAccountId);
    }

    public function changeSubscriptionPrice(
        string $connectedAccountId,
        string $providerSubscriptionId,
        string $providerPriceId,
        bool $prorate,
        string $idempotencyKey,
    ): AgencySubscriptionSnapshot {
        $options = self::onAccount($connectedAccountId);

        try {
            $current = $this->client()->subscriptions->retrieve($providerSubscriptionId, [], $options);
            $itemId = $current->items->data[0]->id ?? null;

            if ($itemId === null) {
                throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
            }

            $subscription = $this->client()->subscriptions->update($providerSubscriptionId, [
                'items' => [['id' => $itemId, 'price' => $providerPriceId]],
                // `always_invoice` bills the difference now for an upgrade;
                // `none` defers it, which is what a downgrade applied at the
                // boundary wants.
                'proration_behavior' => $prorate ? 'always_invoice' : 'none',
            ], self::onAccount($connectedAccountId, ['idempotency_key' => $idempotencyKey]));
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return $this->subscriptionSnapshot($subscription, $connectedAccountId);
    }

    public function setCancelAtPeriodEnd(
        string $connectedAccountId,
        string $providerSubscriptionId,
        bool $cancelAtPeriodEnd,
    ): AgencySubscriptionSnapshot {
        try {
            $subscription = $this->client()->subscriptions->update(
                $providerSubscriptionId,
                ['cancel_at_period_end' => $cancelAtPeriodEnd],
                self::onAccount($connectedAccountId),
            );
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return $this->subscriptionSnapshot($subscription, $connectedAccountId);
    }

    public function createBillingPortalSession(
        string $connectedAccountId,
        string $providerCustomerId,
        string $returnUrl,
        ?string $flow = null,
    ): string {
        $params = ['customer' => $providerCustomerId, 'return_url' => $returnUrl];

        if ($flow !== null) {
            $params['flow_data'] = ['type' => $flow];
        }

        try {
            $session = $this->client()->billingPortal->sessions->create(
                $params,
                self::onAccount($connectedAccountId),
            );
        } catch (ApiErrorException) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        return (string) $session->url;
    }

    // =====================================================================
    // §C4.1 — webhooks and configuration
    // =====================================================================

    public function verifyWebhookPayload(string $rawPayload, string $signatureHeader): array
    {
        $secret = (string) config('services.stripe.agency_subscription_webhook.secret');

        if ($secret === '') {
            throw AgencyBillingException::because(AgencyBillingException::NOT_CONFIGURED);
        }

        try {
            $event = Webhook::constructEvent(
                $rawPayload,
                $signatureHeader,
                $secret,
                (int) config('services.stripe.agency_subscription_webhook.tolerance', 300),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw AgencyBillingException::because(AgencyBillingException::INVALID_SIGNATURE);
        }

        return json_decode(json_encode($event->toArray()), true) ?: [];
    }

    public function configurationStatus(): array
    {
        $secret = (string) config('services.stripe.secret');
        $webhookSecret = (string) config('services.stripe.agency_subscription_webhook.secret');
        $configured = $secret !== '' && preg_match('/\Ask_(test|live)_/', $secret) === 1;

        return [
            'configured' => $configured,
            'webhook_configured' => $webhookSecret !== '',
            // Never a fabricated "test" for a key that is simply missing:
            // telling an operator they are safely in test mode when lane C is
            // not configured at all is worse than saying nothing.
            'mode' => $configured ? (str_starts_with($secret, 'sk_live_') ? 'live' : 'test') : null,
        ];
    }

    // =====================================================================
    // normalization
    // =====================================================================

    private function accountSnapshot(object $account): AgencyAccountSnapshot
    {
        return new AgencyAccountSnapshot(
            stripeAccountId: (string) $account->id,
            chargesEnabled: (bool) ($account->charges_enabled ?? false),
            payoutsEnabled: (bool) ($account->payouts_enabled ?? false),
            detailsSubmitted: (bool) ($account->details_submitted ?? false),
            // The provider's machine CODE, never its prose.
            requirementsDisabledReason: isset($account->requirements->disabled_reason)
                ? (string) $account->requirements->disabled_reason
                : null,
            defaultCurrency: isset($account->default_currency)
                ? mb_strtoupper((string) $account->default_currency)
                : null,
            // Fail-closed readiness — read straight from the provider's own
            // `controller` object (never inferred from the deprecated `type`
            // field), so an account connected any way at all (new onboarding
            // here, or an existing account brought in through OAuth) is
            // judged by the exact same real Stripe state.
            controllerFeesPayer: isset($account->controller->fees->payer)
                ? (string) $account->controller->fees->payer
                : null,
            controllerLossesPayer: isset($account->controller->losses->payments)
                ? (string) $account->controller->losses->payments
                : null,
            controllerRequirementCollection: isset($account->controller->requirement_collection)
                ? (string) $account->controller->requirement_collection
                : null,
            controllerDashboardType: isset($account->controller->stripe_dashboard->type)
                ? (string) $account->controller->stripe_dashboard->type
                : null,
        );
    }

    private function priceSnapshot(object $price): AgencyPriceSnapshot
    {
        return new AgencyPriceSnapshot(
            id: (string) $price->id,
            active: (bool) ($price->active ?? false),
            currency: mb_strtoupper((string) ($price->currency ?? '')),
            unitAmount: isset($price->unit_amount) ? (int) $price->unit_amount : null,
            recurring: isset($price->recurring),
            interval: $price->recurring->interval ?? null,
            intervalCount: isset($price->recurring->interval_count) ? (int) $price->recurring->interval_count : null,
            livemode: (bool) ($price->livemode ?? false),
        );
    }

    private function sessionResult(object $session): AgencyCheckoutSessionResult
    {
        $customer = $session->customer ?? null;
        $subscription = $session->subscription ?? null;

        return new AgencyCheckoutSessionResult(
            sessionId: (string) $session->id,
            url: isset($session->url) ? (string) $session->url : null,
            customerId: is_string($customer) ? $customer : ($customer->id ?? null),
            subscriptionId: is_string($subscription) ? $subscription : ($subscription->id ?? null),
            status: isset($session->status) ? (string) $session->status : null,
            clientReferenceId: isset($session->client_reference_id) ? (string) $session->client_reference_id : null,
        );
    }

    private function subscriptionSnapshot(object $subscription, string $connectedAccountId): AgencySubscriptionSnapshot
    {
        $item = $subscription->items->data[0] ?? null;
        $customer = $subscription->customer ?? null;

        $periodStart = $item->current_period_start ?? $subscription->current_period_start ?? null;
        $periodEnd = $item->current_period_end ?? $subscription->current_period_end ?? null;

        return new AgencySubscriptionSnapshot(
            providerSubscriptionId: (string) $subscription->id,
            providerCustomerId: is_string($customer) ? $customer : (string) ($customer->id ?? ''),
            connectedAccountId: $connectedAccountId,
            status: AgencyProviderStatusMap::forSubscriptionStatus((string) $subscription->status),
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
