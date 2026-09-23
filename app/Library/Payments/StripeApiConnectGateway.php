<?php

namespace App\Library\Payments;

use App\Exceptions\Payments\StripeConnectException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

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
    private readonly StripeClient $client;

    public function __construct()
    {
        $secret = (string) config('services.stripe.secret');

        // Fail closed, and without echoing the key or any part of it.
        if ($secret === '' || ! preg_match('/\Ask_(test|live)_/', $secret)) {
            throw StripeConnectException::notConfigured();
        }

        $this->client = new StripeClient($secret);
    }

    public function createAccount(string $country, ?string $email, string $businessUid): ConnectedAccountSnapshot
    {
        try {
            $account = $this->client->accounts->create([
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
            $link = $this->client->accountLinks->create([
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
            $account = $this->client->accounts->retrieve($stripeAccountId, []);
        } catch (ApiErrorException) {
            throw StripeConnectException::providerFailed();
        }

        return $this->snapshot($account);
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
