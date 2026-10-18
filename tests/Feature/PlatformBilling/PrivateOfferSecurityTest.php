<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Models\PlatformPrivateOffer;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\PlatformTrialClaim;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * Who may use a private link, when, and how often — and that a URL alone is
 * never enough to activate, entitle or pay for anything.
 */
class PrivateOfferSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
    }

    private function reason(PlatformBillingException $e): string
    {
        return $e->reason;
    }

    private function accept(array $customer, PlatformPrivateOffer $offer): ?PlatformBillingException
    {
        try {
            $this->acceptAs($customer['user'], $offer);

            return null;
        } catch (PlatformBillingException $e) {
            return $e;
        }
    }

    // =================================================================
    // Tokens
    // =================================================================

    public function test_unknown_and_malformed_tokens_find_nothing(): void
    {
        $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $this->get(route('offers.show', ['token' => Str::random(43)]))->assertNotFound();
        $this->get('/offers/short')->assertNotFound();
        $this->get('/offers/' . str_repeat('a', 200))->assertNotFound();
        $this->get('/offers/../../etc/passwd')->assertNotFound();
        $this->assertNull($this->redemptions()->findByToken("abc' OR '1'='1"));
    }

    public function test_the_token_is_looked_up_by_hash_only(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $this->assertSame($created['offer']->id, $this->redemptions()->findByToken($created['token'])->id);
        // The stored hash is not itself a working link.
        $this->assertNull($this->redemptions()->findByToken($created['offer']->token_hash));
    }

    // =================================================================
    // Expiry and revocation
    // =================================================================

    public function test_an_expired_link_shows_nothing_and_cannot_be_used(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        PlatformPrivateOffer::query()->update(['expires_at' => now()->subMinute()]);
        $this->signInAs($customer['user']);

        $this->get(route('offers.show', ['token' => $created['token']]))
            ->assertOk()
            ->assertSee('no longer available')
            ->assertDontSee('I accept these terms');

        $error = $this->accept($customer, $created['offer']->refresh());
        $this->assertNull($error?->reason === PlatformBillingException::OFFER_UNAVAILABLE ? null : 'wrong');
        $this->assertSame([], $this->stripe->callsOf('createSubscriptionCheckout'));
        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
    }

    public function test_an_offer_that_expires_while_the_customer_is_deciding_cannot_be_accepted(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $terms = $this->termsFor($created['offer'], $customer['user']);

        PlatformPrivateOffer::query()->update(['expires_at' => now()->subSecond()]);

        $this->expectException(PlatformBillingException::class);
        $this->redemptions()->accept($created['offer']->refresh(), $customer['user'], $terms->hash(), fn ($r) => ['a', 'b']);
    }

    public function test_revoking_retires_an_unpaid_checkout_and_the_link_stops_working(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $session = $this->acceptAs($customer['user'], $created['offer']);

        $this->offers()->revoke($created['offer'], $this->platformAdminId(), 'Customer withdrew.');

        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->assertSame('released', $redemption->state);
        $this->assertSame('revoked', $redemption->release_reason);
        $this->assertSame('expired', $this->stripe->sessions[$session->sessionId]['status'], 'the unpaid Checkout can no longer be paid');
        $this->assertSame([], $this->stripe->payableSessionIds());
        $this->assertSame(0, PlatformTrialClaim::query()->count());

        $this->assertSame(PlatformBillingException::OFFER_UNAVAILABLE, $this->accept($customer, $created['offer']->refresh())?->reason);
    }

    public function test_revoking_never_changes_a_customer_who_has_already_redeemed(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'custom_price' => '80.00']);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $this->payOnStripe($session->sessionId);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->redemptions()->handleReturn($redemption, $customer['user']);
        $subscription = PlatformSubscription::query()->sole();
        $before = $subscription->only(['price_snapshot', 'provider_price_id', 'status', 'offer_redemption_id']);

        $this->offers()->revoke($created['offer'], $this->platformAdminId(), 'Housekeeping.');

        $this->assertSame($before, $subscription->refresh()->only(array_keys($before)), 'an offer change never alters an accepted agreement');
        $this->assertSame('redeemed', $redemption->refresh()->state);
        $this->assertSame([], $this->stripe->callsOf('releaseSchedule'));
        $this->assertSame([], $this->stripe->callsOf('changeSubscriptionPrice'));
    }

    public function test_a_payment_that_lands_after_revocation_is_honoured_not_lost(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        // The customer pays at the very moment the owner revokes.
        $this->payOnStripe($session->sessionId);

        $this->offers()->revoke($created['offer'], $this->platformAdminId(), 'Too late.');

        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->assertSame('reserved', $redemption->refresh()->state, 'a PAID Checkout is not released out from under the customer');

        $this->redemptions()->handleReturn($redemption, $customer['user']);
        $this->assertSame('redeemed', $redemption->refresh()->state);
    }

    // =================================================================
    // Email-bound
    // =================================================================

    public function test_an_email_bound_offer_needs_that_exact_verified_email(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $stranger = $this->unpaidCustomer('stranger@example.test');
        $alias = $this->unpaidCustomer('buyer+alias@example.test');
        $unverified = $this->unpaidCustomer('buyer@example.test', verified: false);

        $this->assertSame('not_for_you', $this->redemptions()->present($created['offer'], $stranger['user'])['state']);
        $this->assertSame('not_for_you', $this->redemptions()->present($created['offer'], $alias['user'])['state'], 'an alias is a different email');
        $this->assertSame('needs_verification', $this->redemptions()->present($created['offer'], $unverified['user'])['state']);
        $this->assertNull($this->redemptions()->present($created['offer'], $stranger['user'])['terms'], 'a stranger is not even shown the price');

        $this->assertSame(PlatformBillingException::OFFER_NOT_FOR_YOU, $this->accept($stranger, $created['offer'])?->reason);
        $this->assertSame(PlatformBillingException::OFFER_NOT_FOR_YOU, $this->accept($alias, $created['offer'])?->reason);
        $this->assertSame(PlatformBillingException::OFFER_EMAIL_UNVERIFIED, $this->accept($unverified, $created['offer'])?->reason);
        $this->assertSame([], $this->stripe->callsOf('createSubscriptionCheckout'));
    }

    public function test_the_matching_is_case_insensitive_but_the_email_must_be_verified(): void
    {
        $created = $this->makeOffer(['customer_email' => 'Buyer@Example.TEST']);
        $buyer = $this->unpaidCustomer('BUYER@example.test');

        $this->assertSame('ready', $this->redemptions()->present($created['offer'], $buyer['user'])['state']);
        $this->assertNull($this->accept($buyer, $created['offer']));
    }

    public function test_the_unverified_page_asks_for_verification_instead_of_showing_a_button(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $unverified = $this->unpaidCustomer('buyer@example.test', verified: false);
        $this->signInAs($unverified['user']);

        $this->get(route('offers.show', ['token' => $created['token']]))
            ->assertOk()
            ->assertSee('verify it first')
            ->assertDontSee('I accept these terms');

        $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => str_repeat('a', 64), 'accept_terms' => '1'])
            ->assertSessionHas('status', 'error');
        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
    }

    // =================================================================
    // Workspace-bound and cross-tenant
    // =================================================================

    public function test_a_workspace_bound_offer_is_for_that_workspace_alone(): void
    {
        $owner = $this->unpaidCustomer('owner@example.test');
        $other = $this->unpaidCustomer('other@example.test');
        $created = $this->makeOffer(['customer_email' => null, 'workspace_uid' => $owner['workspace']->uid]);

        $this->assertSame('ready', $this->redemptions()->present($created['offer'], $owner['user'])['state']);
        $this->assertSame('not_for_you', $this->redemptions()->present($created['offer'], $other['user'])['state']);
        $this->assertSame(PlatformBillingException::OFFER_NOT_FOR_YOU, $this->accept($other, $created['offer'])?->reason);

        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
        $this->assertSame(0, PlatformSubscription::query()->where('workspace_id', $other['workspace']->id)->count(), 'nothing was started on the other tenant either');
    }

    public function test_a_workspace_admin_may_accept_but_staff_may_not(): void
    {
        $owner = $this->unpaidCustomer('owner@example.test');
        $admin = $this->unpaidCustomer('admin@example.test');
        $staff = $this->unpaidCustomer('staff@example.test');
        $this->createMembership($owner['workspace'], $admin['user'], ['role' => WorkspaceMembershipRole::Admin]);
        $this->createMembership($owner['workspace'], $staff['user'], ['role' => WorkspaceMembershipRole::Staff]);
        $created = $this->makeOffer(['customer_email' => null, 'workspace_uid' => $owner['workspace']->uid]);

        $this->assertSame(PlatformBillingException::OFFER_NOT_FOR_YOU, $this->accept($staff, $created['offer'])?->reason);
        $this->assertNull($this->accept($admin, $created['offer']));

        // The plan goes onto the OFFER'S Workspace, not the admin's own.
        $this->assertSame($owner['workspace']->id, (int) PlatformPrivateOfferRedemption::query()->sole()->workspace_id);
    }

    public function test_one_tenant_cannot_return_to_or_cancel_anothers_redemption(): void
    {
        $owner = $this->unpaidCustomer('owner@example.test');
        $intruder = $this->unpaidCustomer('intruder@example.test');
        $created = $this->makeOffer(['customer_email' => 'owner@example.test']);
        $session = $this->acceptAs($owner['user'], $created['offer']);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();

        $this->signInAs($intruder['user']);
        $this->get(route('offers.return', ['redemption' => $redemption->uid]))->assertNotFound();
        $this->get(route('offers.cancelled', ['redemption' => $redemption->uid]))->assertNotFound();

        $this->assertSame('reserved', $redemption->refresh()->state, 'the intruder released nothing');
        $this->assertSame('open', $this->stripe->sessions[$session->sessionId]['status']);
    }

    public function test_guests_cannot_accept_and_nothing_is_recorded(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => str_repeat('a', 64), 'accept_terms' => '1'])
            ->assertUnauthorized();

        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
    }

    // =================================================================
    // A URL alone grants nothing
    // =================================================================

    public function test_a_valid_link_never_activates_entitles_or_pays_anything_by_itself(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $this->signInAs($customer['user']);

        // Visit the link, the return URL and the cancel-less variants repeatedly.
        $this->get(route('offers.show', ['token' => $created['token']]));
        $this->get(route('offers.show', ['token' => $created['token']]) . '?paid=1&status=succeeded&redeemed=true');
        $this->acceptAs($customer['user'], $created['offer']);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->get(route('offers.return', ['redemption' => $redemption->uid]) . '?session_id=cs_forged&payment_status=paid');

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame(PlatformSubscriptionStatus::Pending, $subscription->status);
        $this->assertNull($subscription->provider_subscription_id);
        $this->assertNull($subscription->trial_ends_at);
        $this->assertNull(app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $customer['workspace']->id));
        $this->assertSame('reserved', $redemption->refresh()->state);
        $this->assertSame(0, PlatformTrialClaim::query()->where('state', 'granted')->count());
        $this->assertSame([], $this->stripe->charges);
    }

    // =================================================================
    // Limits and duplicate claims
    // =================================================================

    private function unboundOffer(int $limit): PlatformPrivateOffer
    {
        // Bypasses the manager's "who is it for" rule on purpose: the redemption
        // layer's capacity control must hold on its own.
        $created = $this->makeOffer(['customer_email' => 'seed@example.test', 'redemption_limit' => $limit]);
        $created['offer']->forceFill(['customer_email' => null])->save();

        return $created['offer']->refresh();
    }

    public function test_the_redemption_limit_is_enforced_and_a_reservation_counts(): void
    {
        $offer = $this->unboundOffer(1);
        $one = $this->unpaidCustomer('one@example.test');
        $two = $this->unpaidCustomer('two@example.test');

        $this->assertNull($this->accept($one, $offer));
        // The first customer has not even paid, but holds the only slot.
        $this->assertSame(PlatformBillingException::OFFER_FULLY_REDEEMED, $this->accept($two, $offer)?->reason);
        $this->assertSame('unavailable', $this->redemptions()->present($offer, $two['user'])['state']);
        $this->assertSame(1, PlatformPrivateOfferRedemption::query()->count());
    }

    public function test_a_released_reservation_frees_the_slot_for_somebody_else(): void
    {
        $offer = $this->unboundOffer(1);
        $one = $this->unpaidCustomer('one@example.test');
        $two = $this->unpaidCustomer('two@example.test');

        $this->acceptAs($one['user'], $offer);
        $this->redemptions()->handleCancel(PlatformPrivateOfferRedemption::query()->sole(), $one['user']);

        $this->assertNull($this->accept($two, $offer));
    }

    public function test_a_limit_of_two_allows_two_and_no_more(): void
    {
        $offer = $this->unboundOffer(2);
        $people = [$this->unpaidCustomer('a@example.test'), $this->unpaidCustomer('b@example.test'), $this->unpaidCustomer('c@example.test')];

        $this->assertNull($this->accept($people[0], $offer));
        $this->assertNull($this->accept($people[1], $offer));
        $this->assertSame(PlatformBillingException::OFFER_FULLY_REDEEMED, $this->accept($people[2], $offer)?->reason);
    }

    public function test_two_customers_racing_for_the_last_slot_cannot_both_win(): void
    {
        $offer = $this->unboundOffer(1);
        $one = $this->unpaidCustomer('one@example.test');
        $two = $this->unpaidCustomer('two@example.test');
        $loser = null;

        // While customer one is waiting on Stripe, customer two arrives.
        $this->stripe->interleaveOnce('createSubscriptionCheckout', function () use ($offer, $two, &$loser): void {
            $loser = $this->accept($two, $offer);
        });

        $this->acceptAs($one['user'], $offer);

        $this->assertSame(PlatformBillingException::OFFER_FULLY_REDEEMED, $loser?->reason);
        $this->assertSame(1, PlatformPrivateOfferRedemption::query()->whereNotNull('active_slot')->count());
        $this->assertCount(1, $this->stripe->payableSessionIds());
    }

    public function test_one_claim_per_workspace_per_offer_is_a_schema_guarantee(): void
    {
        $offer = $this->unboundOffer(5);
        $one = $this->unpaidCustomer('one@example.test');
        $this->acceptAs($one['user'], $offer);
        $first = PlatformPrivateOfferRedemption::query()->sole();

        $this->expectException(QueryException::class);

        // Even a buggy writer cannot create a second live claim.
        (new PlatformPrivateOfferRedemption())->forceFill([
            'platform_private_offer_id' => $offer->id, 'workspace_id' => $one['workspace']->id, 'actor_user_id' => $one['user']->id,
            'state' => 'reserved', 'active_slot' => $first->active_slot, 'terms_snapshot' => [], 'terms_hash' => str_repeat('a', 64),
            'accepted_at' => now(), 'accepted_email' => 'x@example.test', 'reserved_until' => now()->addHour(),
        ])->save();
    }

    public function test_a_redeemed_workspace_cannot_claim_the_same_offer_again(): void
    {
        $offer = $this->unboundOffer(5);
        $one = $this->unpaidCustomer('one@example.test');
        $session = $this->acceptAs($one['user'], $offer);
        $this->payOnStripe($session->sessionId);
        $this->redemptions()->handleReturn(PlatformPrivateOfferRedemption::query()->sole(), $one['user']);

        $this->assertSame(PlatformBillingException::ALREADY_SUBSCRIBED, $this->accept($one, $offer)?->reason);
        $this->assertSame(1, PlatformPrivateOfferRedemption::query()->count());
    }

    // =================================================================
    // No secrets
    // =================================================================

    public function test_no_stripe_secret_or_link_secret_reaches_any_page_or_the_audit_log(): void
    {
        config(['services.stripe.secret' => 'sk_test_SUPERSECRETVALUE0123456789', 'services.stripe.platform_subscription_webhook.secret' => 'whsec_SUPERSECRET']);

        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'custom_price' => '90.00']);
        $this->signInAs($customer['user']);

        $pages = [$this->get(route('offers.show', ['token' => $created['token']]))->getContent()];

        $this->signInAsOwner();
        $pages[] = $this->get(route('admin.platform-billing.offers.index'))->getContent();
        $pages[] = $this->get(route('admin.platform-billing.offers.show', [$created['offer']->uid]))->getContent();
        $pages[] = $this->get(route('admin.platform-billing.index'))->getContent();
        $pages[] = json_encode(\App\Models\PlatformAdminAction::query()->get()->toArray());

        foreach ($pages as $page) {
            $this->assertStringNotContainsString('SUPERSECRET', $page);
            $this->assertStringNotContainsString('sk_test_', $page);
            $this->assertStringNotContainsString('whsec_', $page);
        }

        // The link secret is never rendered back by the owner screens or logged.
        foreach (array_slice($pages, 1) as $page) {
            $this->assertStringNotContainsString($created['token'], $page);
        }
    }
}
