<?php

namespace App\Library\AgencyBilling;

use App\Exceptions\AgencyBilling\AgencyBillingException;

/**
 * Lane C §C4 — THE lane-C provider boundary.
 *
 * WHY A FOURTH GATEWAY EXISTS. `App\Library\Usage\StripePaymentProviderGateway`
 * is lane D's (usage funding on the platform account),
 * `App\Library\Payments\StripeConnectGateway` is lane B's (a BUSINESS's own
 * connected account, for document payments), and
 * `App\Library\PlatformBilling\PlatformStripeGateway` is lane A's (the platform
 * account, subscriptions). None of them models "a client pays the AGENCY for
 * software the agency resells". Borrowing lane B's gateway would mean an
 * Agency's subscription revenue travelling through a Business's payment
 * identity, which Addendum §12 forbids — so lane C owns its own boundary, and
 * nothing in this namespace may reference `App\Library\Payments\*`,
 * `App\Library\PlatformBilling\*` or `App\Library\Usage\*` in either direction.
 *
 * EVERY REVENUE METHOD TAKES THE CONNECTED ACCOUNT FIRST. There is deliberately
 * no overload that omits it and no ambient "current account" state, so no call
 * site can accidentally operate on the platform's own Stripe account or on a
 * different Agency's. The account is an argument, which means the compiler and
 * the reader both see it.
 *
 * DIRECT CHARGES, NO PLATFORM CUT. Lane C sets `Stripe-Account` and sets no
 * `application_fee_amount` and no `transfer_data`. The Agency's resale revenue
 * is the Agency's, exactly as Contract 17 §11.2 keeps a Business's revenue the
 * Business's.
 *
 * WHAT CROSSES THIS BOUNDARY. Normalized values only. No `Stripe\*` object, no
 * raw payload, no API key, and no provider error text ever reaches a caller.
 *
 * NO CARD DATA, EVER. Cards are typed on Stripe's own hosted pages; this
 * interface has no method that could accept a PAN, a CVC or an expiry.
 */
interface AgencyStripeGateway
{
    // =====================================================================
    // §C5.1 — connecting the account that receives the Agency's revenue
    // =====================================================================

    /**
     * Creates a connected account for an Agency's SaaS revenue and returns its
     * normalized state.
     *
     * The commercial posture is the implementation's responsibility, not the
     * caller's: a caller cannot pass account type, fee payer, loss liability or
     * dashboard access, so no call site can weaken it.
     *
     * @param  string  $country  ISO 3166-1 alpha-2
     *
     * @throws AgencyBillingException
     */
    public function createAccount(string $country, ?string $email, string $agencyWorkspaceUid): AgencyAccountSnapshot;

    /**
     * A single-use Stripe-hosted onboarding URL for an account we already hold
     * the record of.
     *
     * @throws AgencyBillingException
     */
    public function createOnboardingLink(string $connectedAccountId, string $refreshUrl, string $returnUrl): string;

    /**
     * Re-reads the account's current capability state. §C5.1 — readiness is
     * rechecked against the provider, never assumed from a stored flag.
     *
     * @throws AgencyBillingException
     */
    public function retrieveAccount(string $connectedAccountId): AgencyAccountSnapshot;

    /**
     * "Connect existing Stripe account" — the Stripe-hosted OAuth
     * authorization URL for an Agency that already has a Stripe account and
     * wants to connect it, rather than create a new one. Pure URL
     * construction; no provider call.
     *
     * @throws AgencyBillingException when OAuth is not configured
     */
    public function oauthAuthorizeUrl(string $state, string $redirectUri): string;

    /**
     * Exchanges the one-time OAuth authorization code for the connected
     * account's real id. Callers must independently `retrieveAccount()` the
     * returned id to get its normalized, verified state — this method never
     * fabricates readiness from the token-exchange response alone.
     *
     * @return string the connected account id
     *
     * @throws AgencyBillingException
     */
    public function exchangeOAuthCode(string $authorizationCode): string;

    // =====================================================================
    // §C5.2 — the Agency's own Prices
    // =====================================================================

    /**
     * Retrieve one Price FROM THIS AGENCY'S connected account, so the terms in
     * the Agency's plan can be proved to match what Stripe will charge.
     *
     * A Price on the platform account, or on another Agency's account, is
     * simply not retrievable here — which is what makes cross-account
     * substitution structurally impossible rather than merely checked for.
     *
     * @throws AgencyBillingException when the Price cannot be retrieved
     */
    public function retrievePrice(string $connectedAccountId, string $providerPriceId): AgencyPriceSnapshot;

    /**
     * Create a recurring Price on the Agency's connected account from the terms
     * the Agency typed, so operating lane C needs no visit to the Stripe
     * dashboard and no database edit.
     *
     * `$idempotencyKey` is derived from the plan's durable uid and its terms,
     * so a repeated submission cannot leave two Prices behind.
     *
     * @param  int  $unitAmountMinor  already converted by StripeMinorUnits
     * @param  string  $interval  `month` or `year`
     *
     * @throws AgencyBillingException
     */
    public function createPrice(
        string $connectedAccountId,
        string $productName,
        int $unitAmountMinor,
        string $currencyCode,
        string $interval,
        string $idempotencyKey,
    ): AgencyPriceSnapshot;

    // =====================================================================
    // §C7 — the client's subscription, on the Agency's account
    // =====================================================================

    /**
     * Open hosted subscription checkout for ONE client on ONE Agency plan.
     *
     * `$clientReferenceId` is our own durable lane-C subscription UID, echoed
     * back on `checkout.session.completed` so the event resolves without
     * trusting anything a browser supplied. `$idempotencyKey` is the ATTEMPT's
     * key (§C7), so repeating an uncertain creation returns the original
     * session rather than opening a second payable one.
     *
     * `$trialDays` null means no trial; when present it is the SNAPSHOTTED
     * duration, not whatever the Agency's plan says at this instant.
     *
     * @throws AgencyBillingException
     */
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
    ): AgencyCheckoutSessionResult;

    /**
     * Re-read a checkout session, so the subscription and customer identities
     * come from the provider rather than from a browser redirect.
     *
     * @throws AgencyBillingException
     */
    public function retrieveCheckoutSession(string $connectedAccountId, string $sessionId): AgencyCheckoutSessionResult;

    /**
     * Retire an OPEN Checkout Session so it can never be paid. Valid only while
     * the session is `open`; afterwards a customer cannot complete it. This is
     * what makes "at most one payable session per client subscription" true
     * rather than merely hoped for.
     *
     * @throws AgencyBillingException
     */
    public function expireCheckoutSession(string $connectedAccountId, string $sessionId): AgencyCheckoutSessionResult;

    /**
     * The authoritative subscription state, normalized.
     *
     * @throws AgencyBillingException
     */
    public function retrieveSubscription(string $connectedAccountId, string $providerSubscriptionId): AgencySubscriptionSnapshot;

    /**
     * §C7 — move the subscription to a new Price. `$prorate` true charges the
     * difference now (an upgrade); false defers it (a downgrade applied at the
     * period boundary).
     *
     * @throws AgencyBillingException
     */
    public function changeSubscriptionPrice(
        string $connectedAccountId,
        string $providerSubscriptionId,
        string $providerPriceId,
        bool $prorate,
        string $idempotencyKey,
    ): AgencySubscriptionSnapshot;

    /**
     * §C7 — schedule cancellation at the end of the paid period, or undo it.
     * Lane C never destroys access a client has already paid the Agency for.
     *
     * @throws AgencyBillingException
     */
    public function setCancelAtPeriodEnd(
        string $connectedAccountId,
        string $providerSubscriptionId,
        bool $cancelAtPeriodEnd,
    ): AgencySubscriptionSnapshot;

    /**
     * A Stripe-hosted Billing Portal session ON THE AGENCY'S ACCOUNT, which is
     * how a client fixes or replaces the card the Agency charges.
     *
     * CARD DETAILS NEVER REACH THIS APPLICATION: this returns a URL and takes
     * no card-shaped argument.
     *
     * @param  string|null  $flow  a documented portal flow, or null for the portal home
     *
     * @throws AgencyBillingException
     */
    public function createBillingPortalSession(
        string $connectedAccountId,
        string $providerCustomerId,
        string $returnUrl,
        ?string $flow = null,
    ): string;

    // =====================================================================
    // §C4.1 — webhooks and configuration
    // =====================================================================

    /**
     * Verify the lane-C webhook signature over the EXACT RAW BODY, before
     * anything is inserted or processed.
     *
     * @return array<string, mixed> the decoded event payload
     *
     * @throws AgencyBillingException invalid signature
     */
    public function verifyWebhookPayload(string $rawPayload, string $signatureHeader): array;

    /**
     * Whether lane C is configured well enough to take money at all. Answers a
     * boolean and a mode; it never returns, logs or hints at a secret's value,
     * prefix or length.
     *
     * `mode` is `test` or `live` ONLY when it can actually be derived from a
     * valid configured platform secret, and `null` otherwise.
     *
     * @return array{configured: bool, webhook_configured: bool, mode: 'test'|'live'|null}
     */
    public function configurationStatus(): array;
}
