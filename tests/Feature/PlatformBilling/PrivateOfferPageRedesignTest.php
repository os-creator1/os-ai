<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\PlatformBilling\Offers\OfferReturn;
use App\Models\PlatformPrivateOffer;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * The redesigned Private Offer page: ONE shell (animated gift on brand red,
 * the offer on white) for every state of the link.
 *
 * These tests lock in what the redesign must never trade away: the figures come
 * from OfferTerms, nothing is promised that the terms do not say, a wrong
 * account learns nothing about the offer or its recipient, switching account is
 * a CSRF-protected POST that returns to the SAME offer, and opening the page
 * never starts a payment.
 */
class PrivateOfferPageRedesignTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        config(['account.can_register' => true, 'account.verify_account' => true]);
    }

    private function show(array $created, array $query = [])
    {
        return $this->get(route('offers.show', ['token' => $created['token']] + $query));
    }

    // =================================================================
    // The shell, in every state
    // =================================================================

    public function test_signed_out_visitor_sees_the_redesigned_offer_with_real_terms(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $terms = $this->termsFor($created['offer'], null);

        $page = $this->show($created);

        $page->assertOk()
            ->assertSee('data-role="offer-gift-panel"', false)
            ->assertSee('data-role="offer-gift"', false)
            ->assertSee('Private offer')
            ->assertSee('Something special, just for you.')
            ->assertSee('Sign in to claim')
            ->assertSee('data-role="offer-sign-up"', false)
            ->assertSee('Create an account')
            ->assertSee('14 days free')                                      // eligible trial, from the terms
            ->assertSee('0.00 USD')                                          // charged today
            ->assertSee($terms->firstPaymentAt()->toFormattedDateString())   // first payment
            ->assertSee('every 28 days')                                     // billing frequency
            ->assertSee('data-role="terms-features"', false)
            ->assertDontSee('data-role="offer-accept-form"', false)
            ->assertDontSee('data-locked="true"', false)                     // the lock is for the wrong account only
            ->assertDontSee('/_blob/')                                       // the mockup's missing asset
            ->assertDontSee('131.99');                                       // the mockup's example figures

        $this->assertSame($created['token'], OfferReturn::token(), 'the offer survives sign-in and sign-up');
    }

    public function test_the_gift_animation_is_css_only_and_respects_reduced_motion(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $html = $this->show($created)->assertOk()->getContent();

        $this->assertStringContainsString('animation: pof-float 5s ease-in-out infinite', $html);
        $this->assertStringContainsString('translateY(-10px) rotate(-4deg)', $html);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion:\s*reduce\)\s*\{\s*\.pof-gift\s*\{\s*animation:\s*none/', $html);
        $this->assertStringNotContainsString('<script', $html, 'no JavaScript is needed or shipped');
    }

    public function test_the_page_is_never_cached_or_leaked_through_the_referer(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $response = $this->show($created)->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    // =================================================================
    // Pricing honesty
    // =================================================================

    public function test_no_trial_no_discount_and_no_fabricated_comparison(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $this->show($created)
            ->assertOk()
            ->assertDontSee('days free')
            ->assertDontSee('data-role="terms-standard-price"', false)  // nothing to compare against
            ->assertDontSee('data-role="terms-promo-badge"', false)
            ->assertDontSee('data-role="terms-negotiated-badge"', false)
            ->assertSee('data-role="terms-trial"', false)
            ->assertSee('None');
    }

    public function test_a_permanent_negotiated_price_and_a_temporary_promotion_are_labelled_differently(): void
    {
        $negotiated = $this->makeOffer(['customer_email' => 'buyer@example.test', 'custom_price' => '80.00']);
        $temporary = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 3,
        ]);

        $this->show($negotiated)->assertOk()
            ->assertSee('data-role="terms-negotiated-badge"', false)
            ->assertSee('Permanent agreed price')
            ->assertDontSee('Temporary promotional price');

        $this->show($temporary)->assertOk()
            ->assertSee('data-role="terms-promo-badge"', false)
            ->assertSee('Temporary promotional price')
            ->assertSee('data-role="terms-standard-price"', false)     // 100.00 is a real comparison here
            ->assertSee('Then the standard plan price')
            ->assertDontSee('Permanent agreed price');
    }

    public function test_a_forever_discount_shows_the_real_standard_price_and_no_end_date(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'discount_type' => 'percent', 'discount_value' => '20', 'discount_duration' => 'forever']);

        $this->show($created)->assertOk()
            ->assertSee('80.00 USD')
            ->assertSee('data-role="terms-standard-price"', false)
            ->assertSee('data-role="terms-discount"', false)
            ->assertDontSee('data-role="terms-promo-end"', false)
            ->assertDontSee('Temporary promotional price');
    }

    // =================================================================
    // Wrong account: privacy and secure switching
    // =================================================================

    public function test_a_wrong_account_sees_the_locked_gift_and_learns_nothing_about_the_offer(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14, 'custom_price' => '71.00']);
        $stranger = $this->unpaidCustomer('stranger@example.test');
        $this->signInAs($stranger['user']);

        $page = $this->show($created);

        $page->assertOk()
            ->assertSee('This gift is for another account.')
            ->assertSee('Your private offer was sent to a different email. Switch to that account to open it.')
            ->assertSee('data-locked="true"', false)
            ->assertSee('data-role="offer-current-account"', false)
            ->assertSee('stranger@example.test')                              // their OWN email
            ->assertSee('Switch account')
            ->assertSee('Go to my dashboard')
            ->assertSee("Can't find the right email? Ask the person who sent you the offer.")
            ->assertDontSee('buyer@example.test')                             // never the intended recipient
            ->assertDontSee('buyer@example')
            ->assertDontSee('data-role="offer-terms"', false)                 // no secret terms
            ->assertDontSee('71.00')
            ->assertDontSee('14 days')
            ->assertDontSee('data-role="offer-accept-form"', false)
            ->assertDontSee('data-role="offer-sign-in"', false)
            ->assertDontSee('data-role="offer-sign-up"', false);

        $this->assertStringNotContainsString($created['token'] . '/accept', $page->getContent());
    }

    public function test_switching_account_is_a_csrf_protected_post_and_never_a_get_logout(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $stranger = $this->unpaidCustomer('stranger@example.test');
        $this->signInAs($stranger['user']);

        $html = $this->show($created)->assertOk()->getContent();

        // A form with a CSRF token posting to the offer's own route...
        $this->assertMatchesRegularExpression(
            '#<form method="POST" action="' . preg_quote(route('offers.switch-account', ['token' => $created['token']]), '#') . '"[^>]*>\s*<input type="hidden" name="_token"#',
            $html,
        );
        // ...and no GET link to sign out anywhere on the page.
        $this->assertStringNotContainsString('href="' . route('logout') . '"', $html);
        $this->assertStringNotContainsString('href="' . route('logout') . '?', $html);

        $route = app('router')->getRoutes()->getByName('offers.switch-account');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('web', $route->gatherMiddleware());

        // A GET cannot sign anybody out (the app answers a method mismatch with 404 or 405).
        $this->assertContains($this->get(route('offers.switch-account', ['token' => $created['token']]))->getStatusCode(), [404, 405]);
        $this->assertAuthenticatedAs($stranger['user']);
    }

    public function test_switching_account_signs_out_and_returns_to_the_same_offer_after_login(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $stranger = $this->unpaidCustomer('stranger@example.test');
        $this->signInAs($stranger['user']);

        // Anything the request carries as a "destination" is ignored: no open redirect.
        $response = $this->post(route('offers.switch-account', ['token' => $created['token']]), [
            'redirect' => 'https://evil.example/', 'next' => 'https://evil.example/', 'return_to' => 'https://evil.example/',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame($created['token'], OfferReturn::token());
        $this->assertSame(route('offers.show', ['token' => $created['token']]), OfferReturn::url(), 'login returns to this very offer');
        $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));

        // Signed out, the same link now offers sign-in again.
        $this->show($created)->assertOk()->assertSee('Sign in to claim');
    }

    public function test_switching_account_requires_a_session_and_a_real_offer(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        // A guest is refused by the auth middleware (this app answers 401 or redirects to login).
        $this->assertContains($this->post(route('offers.switch-account', ['token' => $created['token']]))->getStatusCode(), [302, 401]);
        $this->assertGuest();

        $this->signInAs($this->unpaidCustomer('stranger@example.test')['user']);
        $this->post(route('offers.switch-account', ['token' => str_repeat('a', 43)]))->assertNotFound();
        $this->assertAuthenticated();
    }

    public function test_switching_account_never_ends_an_administrators_impersonation_session(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $stranger = $this->unpaidCustomer('stranger@example.test');
        $this->signInAs($stranger['user']);
        $this->withSession(['admin_user_id' => $this->platformAdminId(), 'admin_user_name' => 'Admin']);

        $page = $this->show($created)->assertOk();
        $page->assertSee('data-role="offer-switch-blocked"', false)
            ->assertDontSee('data-role="offer-switch-account-form"', false);

        $this->post(route('offers.switch-account', ['token' => $created['token']]))
            ->assertRedirect(route('offers.show', ['token' => $created['token']]))
            ->assertSessionHas('status', 'error');

        $this->assertAuthenticatedAs($stranger['user']);
        $this->assertSame($this->platformAdminId(), session('admin_user_id'));
    }

    // =================================================================
    // Correct account
    // =================================================================

    public function test_the_correct_account_sees_the_ready_offer_and_no_sign_in_actions(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $this->signInAs($customer['user']);
        $terms = $this->termsFor($created['offer'], $customer['user']);

        $before = [PlatformSubscription::query()->count(), PlatformPrivateOfferRedemption::query()->count()];

        $this->show($created)->assertOk()
            ->assertSee('Your special offer is ready.')
            ->assertSee('data-role="offer-terms"', false)
            ->assertSee('data-role="offer-accept-form"', false)
            ->assertSee('name="terms_hash" value="' . $terms->hash() . '"', false)
            ->assertSee('name="accept_terms"', false)
            ->assertSee('required', false)
            ->assertSee('Accept offer &amp; continue to payment', false)
            ->assertDontSee('Sign in to claim')
            ->assertDontSee('Create an account')
            ->assertDontSee('data-role="offer-sign-in"', false)
            ->assertDontSee('data-locked="true"', false);

        $this->assertSame($before, [PlatformSubscription::query()->count(), PlatformPrivateOfferRedemption::query()->count()]);
        $this->assertSame([], $this->stripe->calls, 'opening the page never charges, provisions or calls Stripe');
    }

    public function test_acceptance_still_demands_the_tick_and_the_terms_hash(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $this->signInAs($customer['user']);
        $hash = $this->termsFor($created['offer'], $customer['user'])->hash();

        $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => $hash])->assertSessionHasErrors('accept_terms');
        $this->post(route('offers.accept', ['token' => $created['token']]), ['accept_terms' => '1'])->assertSessionHasErrors('terms_hash');
        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());

        $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => $hash, 'accept_terms' => '1'])
            ->assertRedirect();
        $this->assertSame(1, PlatformPrivateOfferRedemption::query()->count());
    }

    // =================================================================
    // The other states
    // =================================================================

    public function test_an_unverified_email_is_asked_to_verify_and_cannot_accept(): void
    {
        $unverified = $this->unpaidCustomer('buyer@example.test', verified: false);
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $this->signInAs($unverified['user']);

        $this->show($created)->assertOk()
            ->assertSee('data-role="offer-needs-verification"', false)
            ->assertSee('verify it first')
            ->assertDontSee('data-role="offer-accept-form"', false)
            ->assertDontSee('data-role="offer-terms"', false);
    }

    public function test_several_workspaces_must_be_chosen_explicitly(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $second = Workspace::query()->create(['name' => 'Second Co', 'owner_user_id' => $customer['user']->id, 'is_active' => true]);
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $this->signInAs($customer['user']);

        $this->show($created)->assertOk()
            ->assertSee('Choose the workspace for this offer.')
            ->assertSee('data-role="offer-needs-workspace"', false)
            ->assertSee('Second Co')
            ->assertSee(route('offers.show', ['token' => $created['token'], 'workspace' => $second->uid]), false)
            ->assertDontSee('data-role="offer-accept-form"', false);

        $this->show($created, ['workspace' => $second->uid])->assertOk()
            ->assertSee('data-role="offer-accept-form"', false)
            ->assertSee('name="workspace_uid" value="' . $second->uid . '"', false);
    }

    public function test_an_unavailable_workspace_choice_is_not_treated_as_a_wrong_account(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        Workspace::query()->create(['name' => 'Second Co', 'owner_user_id' => $customer['user']->id, 'is_active' => true]);
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $this->signInAs($customer['user']);

        $this->show($created, ['workspace' => 'not-one-of-mine'])->assertOk()
            ->assertSee('data-role="offer-not-for-you"', false)
            ->assertSee('data-role="offer-choose-workspace"', false)
            ->assertDontSee('data-locked="true"', false)
            ->assertDontSee('data-role="offer-accept-form"', false);
    }

    public function test_a_confirmed_redemption_shows_the_agreed_terms_and_no_accept_action(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'custom_price' => '80.00']);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $this->payOnStripe($session->sessionId);
        $this->redemptions()->handleReturn(PlatformPrivateOfferRedemption::query()->latest('id')->firstOrFail(), $customer['user']);
        $this->signInAs($customer['user']);

        $this->show($created)->assertOk()
            ->assertSee('Your offer is confirmed.')
            ->assertSee('data-role="offer-redeemed"', false)
            ->assertSee('80.00 USD')
            ->assertSee('data-role="offer-go-dashboard"', false)
            ->assertDontSee('data-role="offer-accept-form"', false);
    }

    public function test_an_in_progress_payment_offers_to_continue_and_nothing_else(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $this->acceptAs($customer['user'], $created['offer']);
        $this->signInAs($customer['user']);

        $this->show($created)->assertOk()
            ->assertSee('data-role="offer-in-progress"', false)
            ->assertSee('data-role="offer-accept-form"', false)
            ->assertDontSee('Sign in to claim');
    }

    public function test_expired_and_revoked_offers_are_friendly_dead_ends_that_cannot_be_redeemed(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $expired = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $revoked = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        PlatformPrivateOffer::query()->whereKey($expired['offer']->id)->update(['expires_at' => now()->subMinute()]);
        $this->offers()->revoke($revoked['offer'], $this->platformAdminId(), 'Customer withdrew.');

        foreach ([$expired, $revoked] as $dead) {
            // Signed out and signed in alike.
            $this->show($dead)->assertOk()
                ->assertSee('no longer available')
                ->assertDontSee('data-role="offer-sign-in"', false)
                ->assertDontSee('data-role="offer-accept-form"', false)
                ->assertDontSee('data-role="offer-terms"', false);
        }

        $this->signInAs($customer['user']);
        $hash = str_repeat('0', 64);

        foreach ([$expired, $revoked] as $dead) {
            $this->show($dead)->assertOk()->assertSee('no longer available')->assertDontSee('data-role="offer-accept-form"', false);
            $this->post(route('offers.accept', ['token' => $dead['token']]), ['terms_hash' => $hash, 'accept_terms' => '1'])
                ->assertSessionHas('status', 'error');
        }

        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
        $this->assertSame([], $this->stripe->callsOf('createSubscriptionCheckout'));
    }
}
