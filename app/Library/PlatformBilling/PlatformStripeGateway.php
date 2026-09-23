<?php

namespace App\Library\PlatformBilling;

use App\Exceptions\PlatformBilling\PlatformBillingException;

/**
 * Implementation Contract 21 §5 — THE lane-A provider boundary.
 *
 * WHY A THIRD GATEWAY EXISTS. `App\Library\Usage\StripePaymentProviderGateway`
 * is lane D's (usage funding, scoped to an EffectivePayer) and
 * `App\Library\Payments\StripeConnectGateway` is lane B's (a Business's OWN
 * connected account). Neither models "the Workspace owner pays the Platform
 * Owner for the software". Reusing either would mean a Workspace subscription
 * borrowing a customer identity that belongs to a different economic
 * relationship, which §2 forbids — so lane A owns its own boundary and may not
 * reference `App\Library\Usage\*` or `App\Library\Payments\*` in either
 * direction.
 *
 * DIRECT, FIRST-PARTY CHARGES. Nothing here sets `Stripe-Account`,
 * `on_behalf_of`, `transfer_data` or `application_fee_amount`. Lane A money
 * lands in the Platform Owner's own account; there is no Connect account on
 * either side of the transaction.
 *
 * WHAT CROSSES THIS BOUNDARY. Normalized values only —
 * PlatformSubscriptionSnapshot, CheckoutSessionResult and plain strings. No
 * `Stripe\*` object, no raw payload, no API key, and no provider error text
 * ever reaches a caller.
 *
 * NO CARD DATA, EVER. Card collection happens on Stripe's own hosted Checkout
 * page; this interface has no method that could accept a PAN, a CVC or an
 * expiry, which is the structural form of §6's "no card details".
 */
interface PlatformStripeGateway
{
    /**
     * §7 — open a hosted subscription checkout for one Workspace.
     *
     * `$clientReferenceId` is our own durable local identity (the
     * PlatformSubscription UID), echoed back on `checkout.session.completed`
     * so the event can be resolved without trusting anything the browser
     * supplied. `$idempotencyKey` is always
     * `platform-subscription:{subscription_uid}`, so repeating an uncertain
     * creation returns the original session rather than opening a second one.
     *
     * `$trialDays` null means no trial. When present it is the SNAPSHOTTED
     * duration (§8), not whatever the catalog says at the moment Stripe is
     * called.
     *
     * @throws PlatformBillingException
     */
    public function createSubscriptionCheckout(
        string $providerPriceId,
        string $clientReferenceId,
        string $idempotencyKey,
        string $successUrl,
        string $cancelUrl,
        string $customerEmail,
        ?int $trialDays,
        ?string $existingCustomerId = null,
    ): CheckoutSessionResult;

    /**
     * §12 — re-read a completed checkout session, so the subscription and
     * customer identities come from the provider rather than from a browser
     * redirect (§8.5's "a redirect is never payment truth" applied to lane A).
     *
     * @throws PlatformBillingException
     */
    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionResult;

    /**
     * The authoritative subscription state, normalized.
     *
     * @throws PlatformBillingException
     */
    public function retrieveSubscription(string $providerSubscriptionId): PlatformSubscriptionSnapshot;

    /**
     * §10.2 UPGRADE — move the subscription to a new Price and bill the
     * difference now. `$prorate` true asks the provider to charge/credit
     * immediately; false defers, which is what a downgrade-at-period-end uses
     * when it is applied at the boundary.
     *
     * @throws PlatformBillingException
     */
    public function changeSubscriptionPrice(
        string $providerSubscriptionId,
        string $providerPriceId,
        bool $prorate,
        string $idempotencyKey,
    ): PlatformSubscriptionSnapshot;

    /**
     * §10.3 — schedule cancellation at the end of the paid period, or undo
     * that scheduling. Lane A never destroys access the customer has already
     * paid for.
     *
     * @throws PlatformBillingException
     */
    public function setCancelAtPeriodEnd(string $providerSubscriptionId, bool $cancelAtPeriodEnd): PlatformSubscriptionSnapshot;

    /**
     * §4 — a Stripe-hosted Billing Portal session, which is how an existing
     * subscriber fixes or replaces a payment method.
     *
     * VERIFIED AGAINST THE CURRENT API REFERENCE: `POST
     * /v1/billing_portal/sessions` takes `customer` and `return_url` and
     * answers a `url`. `$flow` of `payment_method_update` is the documented
     * deep link — "Customer will be able to add a new payment method. The
     * payment method will be set as the customer's
     * invoice_settings.default_payment_method" — which is exactly the Grace
     * recovery action.
     *
     * WHY THE PORTAL RATHER THAN OUR OWN FORM. Card details must never reach
     * this application (§6). The portal is Stripe-hosted, so the customer
     * types their card on Stripe's page, and this method's return value is a
     * URL and nothing else.
     *
     * @param  string|null  $flow  a documented portal flow, or null for the portal home
     *
     * @throws PlatformBillingException
     */
    public function createBillingPortalSession(string $providerCustomerId, string $returnUrl, ?string $flow = null): string;

    /**
     * §12 step 1 — verify the lane-A webhook signature over the EXACT RAW
     * BODY, before anything is inserted or processed.
     *
     * @return array<string, mixed> the decoded event payload
     *
     * @throws PlatformBillingException invalid signature
     */
    public function verifyWebhookPayload(string $rawPayload, string $signatureHeader): array;

    /**
     * §11 — whether lane A is configured well enough to take money at all.
     * Answers a boolean and a mode; it never returns, logs or hints at a
     * secret's value, prefix or length (§5.1).
     *
     * @return array{configured: bool, webhook_configured: bool, mode: string}
     */
    public function configurationStatus(): array;
}
