<?php

namespace Tests\Feature\Auth;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * "Continue with Google" as a real registration AND login method. Google itself is never called: Socialite's driver is replaced by a
 * stub that returns (or refuses to return) a provider user, which is exactly the boundary the application trusts.
 */
class GoogleSignupLoginTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    private const GOOGLE_ID = 'google-uid-1001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        config(['account.can_register' => true, 'services.google.active' => true, 'services.google.client_id' => 'test-client', 'services.google.client_secret' => 'secret']);
    }

    /** @param array<string, mixed> $raw */
    private function googleUser(string $email = 'sam@gmail.example', bool $verified = true, string $id = self::GOOGLE_ID, array $raw = []): SocialiteUser
    {
        $user = new SocialiteUser();
        $user->setRaw(array_merge(['sub' => $id, 'email' => $email, 'email_verified' => $verified, 'given_name' => 'Sam', 'family_name' => 'Okafor'], $raw));

        return $user->map(['id' => $id, 'nickname' => null, 'name' => 'Sam Okafor', 'email' => $email, 'avatar' => 'https://lh3.example/avatar.png']);
    }

    /** The provider stub: returns $user from the callback, or throws when given an exception. */
    private function fakeGoogle(SocialiteUser|\Throwable $result): void
    {
        $provider = \Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.test/o/oauth2/auth'));
        $result instanceof \Throwable ? $provider->shouldReceive('user')->andThrow($result) : $provider->shouldReceive('user')->andReturn($result);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function oauthReturn()
    {
        return $this->get(route('social.callback', 'google'));
    }

    private function existingUser(array $attributes = [], bool $verified = true): User
    {
        $user = app(UserRepository::class)->store([
            'first_name' => 'Sam', 'last_name' => 'Okafor', 'email' => 'sam@gmail.example', 'password' => 'a-long-password-123',
            'status' => true, 'phone' => null, 'is_customer' => true,
        ], true);
        $user->forceFill(array_merge(['email_verified_at' => $verified ? now() : null], $attributes))->save();

        return $user->fresh();
    }

    // ------------------------------------------------------------------ Buttons

    public function test_google_is_offered_on_login_and_signup_only_when_configured_and_it_is_the_google_mark_not_an_envelope(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $login = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString(route('social.login', 'google'), $login);
        $this->assertStringContainsString('viewBox="0 0 48 48"', $login, 'The Google "G" mark.');

        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $signup = $this->get(route('register.account'))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="google-signup"', $signup);
        $this->assertStringContainsString('Continue with Google', $signup);
        $this->assertStringContainsString('name="password"', $signup, 'Manual signup stays available.');

        config(['services.google.client_id' => null]);
        $this->assertStringNotContainsString('data-role="google-signup"', $this->get(route('register.account'))->assertOk()->getContent());
        $this->assertStringNotContainsString(route('social.login', 'google'), $this->get(route('login'))->getContent());
    }

    // ------------------------------------------------------------------ New Google signup

    public function test_a_new_google_customer_signs_up_through_plan_business_and_checkout_with_no_password_and_no_early_account(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->fakeGoogle($this->googleUser());

        $this->post(route('register.plan.select'), ['tier' => 'growth'])->assertRedirect(route('register.account'));
        $this->get(route('register.google'))->assertRedirect('https://accounts.google.test/o/oauth2/auth');

        // Google authenticates: the identity is carried in the session draft. NOTHING durable exists yet.
        $this->oauthReturn()->assertRedirect(route('register.business'));
        $this->assertSame(0, User::query()->where('is_customer', true)->count(), 'No abandoned User before checkout.');
        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame('growth', session('v1_signup_draft.tier'), 'The chosen plan survived the OAuth redirect.');
        $this->assertArrayNotHasKey('password_encrypted', session('v1_signup_draft.account'), 'No password is chosen or stored.');

        // The account step is skipped for a verified Google identity; the Business and payment steps are the existing ones.
        $this->get(route('register.account'))->assertRedirect(route('register.business'));
        $this->post(route('register.business.store'), ['business_name' => 'Okafor Photo Booths', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'UTC'])
            ->assertRedirect(route('register.payment'));
        $response = $this->post(route('register.payment.start'));

        $subscription = PlatformSubscription::query()->sole();
        $response->assertRedirect('https://checkout.stripe.test/' . $subscription->provider_checkout_session_id);

        $user = User::query()->where('is_customer', true)->sole();
        $this->assertSame('sam@gmail.example', $user->email);
        $this->assertSame('google', $user->provider);
        $this->assertSame(self::GOOGLE_ID, $user->provider_id);
        $this->assertNotNull($user->email_verified_at, 'Google vouched for the address.');
        $this->assertSame('Sam', $user->first_name);
        $this->assertSame('Okafor', $user->last_name);
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, Customer::query()->where('user_id', $user->id)->count());
        $workspace = Workspace::query()->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Okafor Photo Booths', (string) $business->name);
        $this->assertSame('growth', \DB::table('workspace_plan_catalog')->where('id', $subscription->workspace_plan_catalog_id)->value('tier'));
        $this->assertNull(session('v1_signup_draft'), 'The draft is consumed at commit.');
    }

    public function test_a_google_account_cannot_be_used_to_log_in_with_a_guessable_password(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->fakeGoogle($this->googleUser());
        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->oauthReturn();
        $this->post(route('register.business.store'), ['business_name' => 'Okafor Photo Booths', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'UTC']);
        $this->post(route('register.payment.start'));
        $user = User::query()->where('is_customer', true)->sole();
        auth()->logout();

        foreach (['', 'password', 'a-long-password-123', 'sam@gmail.example'] as $guess) {
            $this->assertFalse(Hash::check($guess, (string) $user->password));
        }
    }

    public function test_a_new_google_identity_that_starts_from_login_is_routed_into_plan_selection_without_creating_anything(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->fakeGoogle($this->googleUser());

        $this->oauthReturn()->assertRedirect(route('register.plan'));

        $this->assertSame(0, User::query()->where('is_customer', true)->count());
        $this->assertGuest();
        // After choosing a plan the verified identity is still the account.
        $this->post(route('register.plan.select'), ['tier' => 'growth'])->assertRedirect(route('register.account'));
        $this->get(route('register.account'))->assertRedirect(route('register.business'));
    }

    public function test_the_customer_can_switch_from_google_to_a_manual_email_and_password(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->fakeGoogle($this->googleUser());
        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->oauthReturn();

        $this->get(route('register.account', ['manual' => 1]))->assertOk()->assertSee('name="password"', false);
        $this->assertNull(session('v1_signup_draft.account'));
        $this->assertSame('growth', session('v1_signup_draft.tier'));
    }

    public function test_an_interrupted_signup_resumes_with_the_plan_and_identity_intact(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->fakeGoogle($this->googleUser());
        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->oauthReturn();

        // The customer wanders off and comes back to the signup entry: it resumes at the furthest step, not from scratch.
        $this->get(route('register'))->assertRedirect(route('register.business'));
        $this->assertSame(0, Workspace::query()->count());
    }

    // ------------------------------------------------------------------ Security

    public function test_an_unverified_google_email_is_refused_everywhere(): void
    {
        $this->fakeGoogle($this->googleUser(verified: false));
        $this->oauthReturn()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(session('v1_signup_draft'));

        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        session(['social_intent' => 'signup']);
        $this->oauthReturn()->assertRedirect(route('register.account'));
        $this->assertNull(session('v1_signup_draft.account'), 'An unverified identity never becomes the signup account.');
    }

    public function test_an_invalid_or_expired_oauth_state_and_a_cancelled_consent_create_nothing(): void
    {
        $this->fakeGoogle(new InvalidStateException());
        $this->oauthReturn()->assertRedirect(route('login'));
        $this->assertGuest();

        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        session(['social_intent' => 'signup']);
        $this->oauthReturn()->assertRedirect(route('register.account'));
        $this->assertNull(session('v1_signup_draft.account'));
        $this->assertSame(0, User::query()->where('is_customer', true)->count());
    }

    public function test_a_closed_registration_setting_stops_a_new_google_identity(): void
    {
        config(['account.can_register' => false]);
        $this->fakeGoogle($this->googleUser());

        $this->oauthReturn()->assertRedirect(route('login'));

        $this->assertNull(session('v1_signup_draft'));
        $this->assertSame(0, User::query()->where('is_customer', true)->count());
    }

    public function test_the_signup_google_start_needs_a_plan_and_an_available_provider(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->fakeGoogle($this->googleUser());

        $this->get(route('register.google'))->assertRedirect(route('register.plan'));   // no plan pinned yet

        config(['services.google.client_id' => null]);
        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->get(route('register.google'))->assertRedirect(route('register.account'));
        $this->assertNull(session('social_intent'));
    }

    // ------------------------------------------------------------------ Existing users

    public function test_an_existing_google_linked_customer_signs_in_and_resumes_an_unfinished_signup(): void
    {
        $user = $this->existingUser(['provider' => 'google', 'provider_id' => self::GOOGLE_ID]);
        $this->fakeGoogle($this->googleUser());

        // This account never finished signup (no Workspace/plan): it resumes the legitimate plan process, not a second account.
        $this->oauthReturn()->assertRedirect(route('signup.plan'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->where('email', 'sam@gmail.example')->count());
    }

    public function test_a_verified_unlinked_account_is_linked_but_unverified_two_factor_or_other_identity_accounts_are_not(): void
    {
        $plain = $this->existingUser();
        $this->fakeGoogle($this->googleUser());
        $this->oauthReturn()->assertRedirect(route('signup.plan'));
        $this->assertSame(self::GOOGLE_ID, $plain->fresh()->provider_id, 'Verified, unlinked, no 2FA: linked.');
        auth()->logout();
        $plain->delete();

        foreach ([
            'unverified email' => [['email_verified_at' => null], false],
            'two-factor enabled' => [['two_factor' => 1], true],
            'linked to another Google identity' => [['provider' => 'google', 'provider_id' => 'someone-else'], true],
        ] as $label => [$attributes, $verified]) {
            config(['app.two_factor' => true]);
            $victim = $this->existingUser($attributes, $verified);
            $this->fakeGoogle($this->googleUser());

            $this->oauthReturn()->assertRedirect(route('login'));

            $this->assertGuest($label);
            $this->assertNotSame(self::GOOGLE_ID, $victim->fresh()->provider_id, $label);
            $victim->delete();
        }
    }

    public function test_disabled_and_administrator_accounts_never_sign_in_through_google(): void
    {
        $disabled = $this->existingUser(['provider' => 'google', 'provider_id' => self::GOOGLE_ID, 'status' => false]);
        $this->fakeGoogle($this->googleUser());
        $this->oauthReturn()->assertRedirect(route('login'));
        $this->assertGuest();
        $disabled->delete();

        $admin = $this->existingUser(['provider' => 'google', 'provider_id' => self::GOOGLE_ID, 'is_admin' => true]);
        $this->fakeGoogle($this->googleUser());
        $this->oauthReturn()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue((bool) $admin->fresh()->is_admin);
    }

    // ------------------------------------------------------------------ No duplicates

    public function test_an_email_registered_after_google_confirmed_it_is_never_duplicated_at_commit(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->fakeGoogle($this->googleUser());
        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->oauthReturn();
        $this->post(route('register.business.store'), ['business_name' => 'Okafor Photo Booths', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'UTC']);

        $this->existingUser(); // someone registers the same address in the meantime

        $this->post(route('register.payment.start'))->assertRedirect(route('login'));

        $this->assertSame(1, User::query()->where('email', 'sam@gmail.example')->count());
        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, PlatformSubscription::query()->count());
        $this->assertNull(session('v1_signup_draft'));
    }

    public function test_a_cancelled_checkout_keeps_one_account_and_never_adds_a_subscription(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->fakeGoogle($this->googleUser());
        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->oauthReturn();
        $this->post(route('register.business.store'), ['business_name' => 'Okafor Photo Booths', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'UTC']);
        $this->post(route('register.payment.start'));

        $this->get(route('signup.cancelled'))->assertRedirect();
        $this->get(route('signup.plan'))->assertOk();

        $this->assertSame(1, User::query()->where('provider_id', self::GOOGLE_ID)->count());
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
    }

    // ------------------------------------------------------------------ Manual signup unchanged

    public function test_manual_email_and_password_registration_is_unchanged(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);

        $response = $this->signUp([
            'tier' => 'growth', 'first_name' => 'Pat', 'last_name' => 'Rivera', 'email' => 'pat@example.test',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
            'business_name' => 'Harbor Lane Studios', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'UTC',
        ]);

        $subscription = PlatformSubscription::query()->sole();
        $response->assertRedirect('https://checkout.stripe.test/' . $subscription->provider_checkout_session_id);
        $user = User::query()->where('email', 'pat@example.test')->sole();
        $this->assertNull($user->provider);
        $this->assertTrue(Hash::check('correct-horse-battery', (string) $user->password));
    }

    // ------------------------------------------------------------------ No redirect loop (Google has no password)

    public function test_a_google_customer_moves_forward_through_every_step_without_bouncing_back_to_the_account_step(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->fakeGoogle($this->googleUser());
        $this->post(route('register.plan.select'), ['tier' => 'growth']);
        $this->oauthReturn()->assertRedirect(route('register.business'));

        // The Business step renders (it used to redirect to the account step, which redirected straight back).
        $this->get(route('register.business'))->assertOk();
        // The payment step is not reachable yet, and it sends the customer FORWARD to the Business step, not to the account step.
        $this->get(route('register.payment'))->assertRedirect(route('register.business'));
        // The signup entry resumes at the furthest completed step.
        $this->get(route('register'))->assertRedirect(route('register.business'));

        $this->post(route('register.business.store'), ['business_name' => 'Okafor Photo Booths', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'UTC'])
            ->assertRedirect(route('register.payment'));
        $this->get(route('register.payment'))->assertOk();
        $this->get(route('register'))->assertRedirect(route('register.payment'));
        $this->get(route('register.account'))->assertRedirect(route('register.business'));
    }

    public function test_a_manual_account_without_its_password_still_goes_back_to_the_account_step(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        // A draft whose account has neither a chosen password nor a provider identity is not a complete account.
        $this->withSession(['v1_signup_draft' => ['tier' => 'growth', 'account' => ['first_name' => 'Pat', 'last_name' => null, 'email' => 'pat@example.test']]]);

        $this->get(route('register.business'))->assertRedirect(route('register.account'));
        $this->get(route('register'))->assertRedirect(route('register.account'));
    }

    public function test_a_provider_identity_can_only_be_set_by_the_server_never_by_the_account_form(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth);
        $this->post(route('register.plan.select'), ['tier' => 'growth']);

        // Posting provider fields with the manual form does not make a Google account; the password rule still applies.
        $this->post(route('register.account.store'), [
            'first_name' => 'Eve', 'email' => 'eve@example.test', 'provider' => 'google', 'provider_id' => 'forged',
        ])->assertSessionHasErrors('password');

        $this->assertNull(session('v1_signup_draft.account'));
        $this->get(route('register.business'))->assertRedirect(route('register.account'));
    }
}
