<?php

namespace App\Library\Payments;

use App\Exceptions\Payments\StripeConnectException;

/**
 * Implementation Contract 17 §12.D / §4.2 — THE lane-B provider boundary.
 *
 * WHY A SECOND GATEWAY EXISTS. app/Library/Usage/StripePaymentProviderGateway
 * calls itself "the sole class in this repository permitted to reference a
 * Stripe\* SDK class". §4.2 records that this is a LANE-D SCOPING STATEMENT,
 * not a platform-wide monopoly: obeying it literally would force lane-B
 * charges through the PLATFORM's own Stripe account, which is exactly the
 * Addendum §12 violation this slice exists to avoid. Lane B therefore owns
 * its own gateway, and nothing in this namespace may reference
 * App\Library\Usage\* in either direction.
 *
 * WHAT CROSSES THIS BOUNDARY. Normalized values only —
 * ConnectedAccountSnapshot and plain strings. No Stripe\* object, no raw
 * payload, no secret key and no provider error text ever reaches a caller.
 *
 * WHAT DOES NOT LIVE HERE. No PaymentIntent, no charge, no refund, no
 * webhook handling: those are Sub-slices E and F. This interface is
 * deliberately three onboarding methods wide.
 */
interface StripeConnectGateway
{
    /**
     * Creates a connected account under §11.2's LOCKED commercial posture and
     * returns its normalized state.
     *
     * The posture is the implementation's responsibility, not the caller's:
     * a caller cannot pass account type, fee payer, loss liability or
     * dashboard access, so no call site can weaken it.
     *
     * @param  string  $country  ISO 3166-1 alpha-2
     *
     * @throws StripeConnectException
     */
    public function createAccount(string $country, ?string $email, string $businessUid): ConnectedAccountSnapshot;

    /**
     * A single-use Stripe-hosted onboarding URL for an account we already own
     * the record of.
     *
     * @throws StripeConnectException
     */
    public function createOnboardingLink(string $stripeAccountId, string $refreshUrl, string $returnUrl): string;

    /**
     * Re-reads the account's current capability state from the provider.
     * §11.4 — readiness is rechecked, never assumed.
     *
     * @throws StripeConnectException
     */
    public function retrieveAccount(string $stripeAccountId): ConnectedAccountSnapshot;
}
