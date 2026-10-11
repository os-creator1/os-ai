<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Security\Concerns\PreparesSecurityTenants;
use Tests\TestCase;

/**
 * S1: social login linked accounts by e-mail alone, logged the user in before any status check, ignored whether the
 * provider vouched for the address, never checked the provider was configured, and stamped email_verified_at.
 */
class SocialLoginSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use PreparesSecurityTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPlaceholderSuperAdmin();
        $this->ensureRequiredAppConfigRowsExist();
        config(['services.google.client_id' => 'x', 'services.github.client_id' => 'x', 'services.facebook.client_id' => 'x',
                'account.can_register' => true]);
    }

    private function provider(string $provider, string $id, ?string $email, bool $verified = true): void
    {
        $user = (new SocialiteUser())->setRaw(['email_verified' => $verified])->map(['id' => $id, 'name' => 'Sam Social', 'nickname' => 'sam', 'email' => $email, 'avatar' => null]);
        Socialite::shouldReceive('driver')->with($provider)->andReturnSelf();
        Socialite::shouldReceive('user')->andReturn($user);
    }

    private function victim(array $attrs = []): User
    {
        $u = $this->createCustomer()->user;
        $u->forceFill(array_merge(['email' => 'victim@example.test', 'email_verified_at' => now(), 'password' => bcrypt('secret-pass'), 'status' => true], $attrs))->save();

        return $u;
    }

    private function oauthReturn(string $provider = 'google')
    {
        return $this->get(route('social.callback', $provider));
    }

    public function test_provider_asserting_a_victims_email_does_not_log_in_an_unlinked_facebook_identity(): void
    {
        $this->victim();
        $this->provider('facebook', 'fb-1', 'victim@example.test', false);   // Facebook gives no verified flag

        $this->oauthReturn('facebook')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(User::where('email', 'victim@example.test')->value('provider'));
    }

    public function test_an_administrator_can_never_be_logged_in_via_social_login(): void
    {
        $admin = $this->victim(['email' => 'boss@example.test', 'is_admin' => true]);
        $this->provider('google', 'g-admin', 'boss@example.test');

        $this->oauthReturn()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($admin->fresh()->provider);
    }

    public function test_a_disabled_account_ends_up_logged_out(): void
    {
        $this->victim(['status' => false]);
        $this->provider('google', 'g-1', 'victim@example.test');

        $this->oauthReturn()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_an_unverified_preregistered_account_is_not_linked_or_marked_verified(): void
    {
        $squat = $this->victim(['email_verified_at' => null]);   // attacker registered the victim's address with their own password
        $this->provider('google', 'g-victim', 'victim@example.test');

        $this->oauthReturn()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($squat->fresh()->email_verified_at);
        $this->assertNull($squat->fresh()->provider);
    }

    public function test_a_different_identity_cannot_take_over_an_already_linked_account(): void
    {
        $this->victim(['provider' => 'google', 'provider_id' => 'g-original']);
        $this->provider('google', 'g-other', 'victim@example.test');

        $this->oauthReturn()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame('g-original', User::where('email', 'victim@example.test')->value('provider_id'));
    }

    public function test_a_returning_linked_identity_logs_in(): void
    {
        $victim = $this->victim(['provider' => 'google', 'provider_id' => 'g-1']);
        $this->provider('google', 'g-1', 'victim@example.test');

        $this->oauthReturn()->assertRedirect(route('user.home'));

        $this->assertAuthenticatedAs($victim);
    }

    public function test_a_verified_provider_email_links_an_existing_verified_account_once(): void
    {
        $victim = $this->victim();
        $this->provider('google', 'g-1', 'victim@example.test');

        $this->oauthReturn()->assertRedirect(route('user.home'));

        $this->assertAuthenticatedAs($victim);
        $this->assertSame('g-1', $victim->fresh()->provider_id);
    }

    public function test_an_account_with_two_factor_is_not_auto_linked(): void
    {
        config(['app.two_factor' => true]);
        $this->victim(['two_factor' => true]);
        $this->provider('google', 'g-1', 'victim@example.test');

        $this->oauthReturn()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_new_accounts_need_a_provider_verified_email_and_never_get_a_fabricated_address(): void
    {
        $this->provider('google', 'g-new', null);
        $this->oauthReturn()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, User::where('provider_id', 'g-new')->count());
    }

    public function test_unverified_new_identity_is_refused_and_verified_one_is_created(): void
    {
        $this->provider('facebook', 'fb-new', 'new@example.test', false);
        $this->oauthReturn('facebook')->assertRedirect(route('login'));
        $this->assertSame(0, User::where('email', 'new@example.test')->count());
    }

    public function test_a_verified_new_identity_creates_a_verified_customer(): void
    {
        $this->provider('google', 'g-new2', 'fresh@example.test');

        $this->oauthReturn()->assertRedirect(route('user.home'));

        $user = User::where('email', 'fresh@example.test')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_or_unconfigured_providers_are_refused_without_a_server_error(): void
    {
        $this->get(route('social.callback', 'myspace'))->assertRedirect(route('login'));
        $this->get(route('social.login', 'myspace'))->assertRedirect();

        config(['services.github.client_id' => null]);
        $this->get(route('social.callback', 'github'))->assertRedirect(route('login'));
        $this->get(route('social.login', 'github'))->assertRedirect(route('login'));
    }
}
