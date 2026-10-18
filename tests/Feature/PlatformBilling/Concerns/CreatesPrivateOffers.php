<?php

namespace Tests\Feature\PlatformBilling\Concerns;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\PlatformBilling\Offers\OfferTerms;
use App\Library\PlatformBilling\Offers\PrivateOfferManager;
use App\Library\PlatformBilling\Offers\PrivateOfferRedemptionManager;
use App\Models\Customer;
use App\Models\PlatformPrivateOffer;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;

/**
 * Fixtures for Private Offers on top of the lane-A subscription fixtures.
 *
 * Everything runs against the fake Stripe gateway (no network, no real key).
 */
trait CreatesPrivateOffers
{
    use CreatesPlatformSubscriptions;

    protected function offers(): PrivateOfferManager
    {
        return app(PrivateOfferManager::class);
    }

    protected function redemptions(): PrivateOfferRedemptionManager
    {
        return app(PrivateOfferRedemptionManager::class);
    }

    /**
     * Creates an offer as the platform administrator.
     *
     * @param  array<string, mixed>  $data
     * @return array{offer: PlatformPrivateOffer, token: string}
     */
    protected function makeOffer(array $data = []): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        return $this->offers()->create(array_merge([
            'tier' => 'growth',
            'customer_email' => null,
            'expires_in_days' => 14,
            'reason' => 'Negotiated with the customer.',
        ], $data), $this->platformAdminId());
    }

    /**
     * A customer with a VERIFIED email and an unassigned Workspace — the state
     * of someone who signed up but has not paid.
     *
     * @return array{customer: Customer, user: User, workspace: Workspace}
     */
    protected function unpaidCustomer(?string $email = null, bool $verified = true): array
    {
        $fixture = $this->unassignedWorkspace();
        $user = $fixture['customer']->user;

        if ($email !== null) {
            $user->email = $email;
        }

        $user->email_verified_at = $verified ? now() : null;
        $user->save();

        return ['customer' => $fixture['customer'], 'user' => $user->refresh(), 'workspace' => $fixture['workspace']];
    }

    /** The Platform Owner, holding the plan-management permissions the offer screens check. */
    protected function signInAsOwner(): User
    {
        $admin = User::query()->findOrFail($this->platformAdminId());
        $admin->email_verified_at = now();
        $admin->save();

        $this->withSession(['permissions' => collect(['access backend', 'view workspace', 'view workspace plans', 'manage workspace plans'])]);
        $this->actingAs($admin);

        return $admin;
    }

    protected function signInAs(User $user): void
    {
        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($user);
    }

    /** The terms the customer would be shown (and must accept) right now. */
    protected function termsFor(PlatformPrivateOffer $offer, ?User $viewer): OfferTerms
    {
        $view = $this->redemptions()->present($offer->refresh(), $viewer);

        $this->assertNotNull($view['terms'], 'The offer produced no terms (state: ' . $view['state'] . ').');

        return $view['terms'];
    }

    /**
     * The customer accepts the terms they were shown and is sent to Stripe.
     *
     * @return \App\Library\PlatformBilling\CheckoutSessionResult
     */
    protected function acceptAs(User $user, PlatformPrivateOffer $offer, ?OfferTerms $terms = null)
    {
        // A viewer who cannot see terms (expired, not theirs) still submits
        // SOMETHING; the manager must refuse before it ever looks at the hash.
        $hash = ($terms ?? $this->redemptions()->present($offer->refresh(), $user)['terms'])?->hash() ?? str_repeat('0', 64);

        return $this->redemptions()->accept(
            $offer->refresh(),
            $user,
            $hash,
            fn (PlatformPrivateOfferRedemption $r): array => ['https://app.test/offers/return/' . $r->uid, 'https://app.test/offers/cancelled/' . $r->uid],
        );
    }

    /** The customer pays on Stripe's hosted page and the provider confirms it. */
    protected function payOnStripe(string $sessionId): string
    {
        return $this->stripe->completeCheckout($sessionId);
    }

    /** The webhook consumer's path: provider truth applied without any browser. */
    protected function confirmByWebhook(string $providerSubscriptionId, ?string $eventId = null): void
    {
        $subscription = \App\Models\PlatformSubscription::query()
            ->where('provider_subscription_id', $providerSubscriptionId)
            ->orWhere('provider_checkout_session_id', array_key_last($this->stripe->sessions))
            ->firstOrFail();

        app(\App\Library\PlatformBilling\PlatformSubscriptionManager::class)
            ->applyProviderSubscription($subscription, $providerSubscriptionId, $eventId, now());
    }

    protected function catalog(WorkspacePlanTier $tier): WorkspacePlanCatalog
    {
        return WorkspacePlanCatalog::query()->where('tier', $tier->value)->firstOrFail();
    }
}
