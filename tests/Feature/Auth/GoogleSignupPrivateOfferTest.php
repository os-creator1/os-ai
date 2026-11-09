<?php

namespace Tests\Feature\Auth;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * A private offer bound to one email: the signup keeps its plan across the Google redirect, and only THAT address (as Google verified it)
 * can become the account.
 */
class GoogleSignupPrivateOfferTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    private const BUYER = 'buyer@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'account.can_register' => true, 'account.verify_account' => true, 'platform_billing.private_offers_enabled' => true,
            'services.google.active' => true, 'services.google.client_id' => 'test-client',
        ]);
    }

    private function fakeGoogle(string $email): void
    {
        $user = (new SocialiteUser())->setRaw(['sub' => 'g-offer-1', 'email' => $email, 'email_verified' => true, 'given_name' => 'Bea', 'family_name' => 'Buyer'])
            ->map(['id' => 'g-offer-1', 'nickname' => null, 'name' => 'Bea Buyer', 'email' => $email, 'avatar' => null]);
        $provider = \Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.test/o/oauth2/auth'));
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function startOfferSignup(): array
    {
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        $created = $this->makeOffer(['customer_email' => self::BUYER]);
        $this->get(route('offers.signup', ['token' => $created['token']]))->assertRedirect(route('register.account'));

        return $created;
    }

    public function test_the_offered_email_proceeds_through_google_with_the_plan_and_offer_kept_and_no_checkout_opened(): void
    {
        $created = $this->startOfferSignup();
        $this->fakeGoogle('Buyer@Example.test');   // case differences are the same address

        $this->get(route('social.callback', 'google'))->assertRedirect(route('register.business'));
        $this->assertSame('growth', session('v1_signup_draft.tier'), 'The plan survived the redirect.');
        $this->assertSame($created['token'], session('v1_signup_draft.offer_token'), 'So did the offer.');
        $this->assertSame(0, User::query()->where('is_customer', true)->count());

        $this->post(route('register.business.store'), ['business_name' => 'Buyer Booths', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'UTC'])
            ->assertRedirect(route('register.payment'));
        $this->post(route('register.payment.start'))->assertRedirect(route('offers.show', ['token' => $created['token']]));

        $user = User::query()->where('is_customer', true)->sole();
        $this->assertSame('google', $user->provider);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(0, PlatformSubscription::query()->count(), 'Nothing is bought until the customer accepts the offer terms.');
    }

    public function test_a_different_google_email_cannot_become_the_account_for_an_email_bound_offer(): void
    {
        $created = $this->startOfferSignup();
        $this->fakeGoogle('someone.else@example.test');

        $this->get(route('social.callback', 'google'))->assertRedirect(route('register.account'));

        $this->assertNull(session('v1_signup_draft.account'), 'The wrong address never becomes the account.');
        $this->assertSame('growth', session('v1_signup_draft.tier'));
        $this->assertSame($created['token'], session('v1_signup_draft.offer_token'));
        $this->assertSame(0, User::query()->where('is_customer', true)->count());
        $this->get(route('register.business'))->assertRedirect(route('register.account'));
    }

    public function test_a_draft_that_somehow_holds_the_wrong_identity_is_refused_again_at_commit(): void
    {
        $created = $this->startOfferSignup();
        $this->withSession(['v1_signup_draft' => [
            'tier' => 'growth', 'offer_token' => $created['token'],
            'account' => ['first_name' => 'Mal', 'last_name' => null, 'email' => 'mallory@example.test', 'provider' => 'google', 'provider_id' => 'g-mal', 'avatar' => null],
            'business' => ['business_name' => 'Mal Booths', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'UTC', 'currency_code' => 'USD'],
        ]]);

        $this->post(route('register.payment.start'))->assertRedirect(route('register.account'));

        $this->assertSame(0, User::query()->where('email', 'mallory@example.test')->count());
        $this->assertSame(0, Workspace::query()->count());
    }
}
