<?php

namespace Tests\Feature\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailAccountState as State;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\BusinessEmail\BusinessEmailConcurrencyException;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\BusinessEmailAccountManager;
use App\Library\BusinessEmail\BusinessEmailOAuthStateSigner;
use App\Models\BusinessEmailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * Connect / callback / disconnect / reconnect / refresh: the OAuth security
 * properties and the encrypted credential lifecycle. Providers are in-memory
 * fakes; no network is reachable.
 */
class BusinessEmailConnectionTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();
    }

    private function connectUrl(object $workspace, object $business, string $provider = 'google'): string
    {
        return route('customer.workspaces.businesses.email.connect', [$workspace->uid, $business->uid, $provider]);
    }

    private function callbackUrl(string $provider, array $query): string
    {
        return route('customer.email.oauth.callback', ['provider' => $provider]) . '?' . http_build_query($query);
    }

    /** Starts a connect and returns the signed state the provider would echo back. */
    private function beginAndGetState(object $workspace, object $business, string $provider = 'google'): string
    {
        $response = $this->post($this->connectUrl($workspace, $business, $provider));
        $response->assertRedirect();

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function account(object $business): ?BusinessEmailAccount
    {
        return BusinessEmailAccount::query()->where('business_id', $business->id)->first();
    }

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace} */
    private function owner(): array
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->authenticateAs($customer);

        return [$customer, $business, $workspace];
    }

    // ---- connect ---------------------------------------------------------

    public function test_connect_creates_a_pending_account_and_redirects_to_the_provider_with_an_opaque_signed_state(): void
    {
        [, $business, $workspace] = $this->owner();

        $state = $this->beginAndGetState($workspace, $business);

        $account = $this->account($business);
        $this->assertSame(State::Pending, $account->state);
        $this->assertSame(BusinessEmailProviderType::Google, $account->provider);
        $this->assertNull($account->refresh_token_encrypted);
        $this->assertNotNull($account->oauth_state_nonce);

        $payload = app(BusinessEmailOAuthStateSigner::class)->verify($state);
        $this->assertSame($business->id, $payload['b']);
        $this->assertSame('google', $payload['p']);
        $this->assertSame($account->oauth_state_nonce, $payload['n']);
        // Opaque: no redirect target, no user id, nothing but the minimum.
        $this->assertSame(['b', 'p', 'n', 'e'], array_keys($payload));
        $this->assertSame(1, $this->fakeGoogle->callCount('authorizationUrl'));
    }

    public function test_connect_is_not_reachable_by_get(): void
    {
        [, $business, $workspace] = $this->owner();

        $this->assertContains($this->get($this->connectUrl($workspace, $business))->getStatusCode(), [404, 405]);
        $this->assertNull($this->account($business));
    }

    public function test_an_unknown_provider_is_a_404_and_creates_nothing(): void
    {
        [, $business, $workspace] = $this->owner();

        $this->post(route('customer.workspaces.businesses.email.connect', [$workspace->uid, $business->uid, 'yahoo']))->assertNotFound();
        $this->assertNull($this->account($business));
    }

    public function test_a_misconfigured_provider_fails_before_any_state_exists(): void
    {
        [, $business, $workspace] = $this->owner();
        config(['business_email.google.client_secret' => '']);

        $this->post($this->connectUrl($workspace, $business))->assertRedirect(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]));

        $this->assertNull($this->account($business));
        $this->assertSame(0, $this->fakeGoogle->callCount('authorizationUrl'));
    }

    public function test_a_redirect_that_is_not_the_fixed_tenant_free_callback_is_refused(): void
    {
        [, $business, $workspace] = $this->owner();
        config(['business_email.google.redirect' => 'https://app.example.test/some/other/path']);

        $this->post($this->connectUrl($workspace, $business));

        $this->assertNull($this->account($business));
    }

    public function test_connect_requires_the_manage_permission_and_creates_nothing_without_it(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->authenticateAs($customer, ['chat_box', 'view_contact']);

        $this->post($this->connectUrl($workspace, $business))->assertStatus(401);

        $this->assertNull($this->account($business));
    }

    public function test_the_default_permission_set_does_not_include_manage_business_email(): void
    {
        $this->assertFalse(config('customer-permissions.manage_business_email.default'));
    }

    public function test_connecting_while_active_is_refused_and_changes_nothing(): void
    {
        [, $business, $workspace] = $this->owner();
        $active = $this->activeAccount($business);

        $this->post($this->connectUrl($workspace, $business, 'microsoft'));

        $account = $this->account($business);
        $this->assertSame(State::Active, $account->state);
        $this->assertSame(BusinessEmailProviderType::Google, $account->provider);
        $this->assertSame((string) $active->getRawOriginal('refresh_token_encrypted'), (string) $account->getRawOriginal('refresh_token_encrypted'));
        $this->assertSame(0, $this->fakeMicrosoft->callCount('authorizationUrl'));
    }

    public function test_a_second_initiation_kills_the_first_state(): void
    {
        [, $business, $workspace] = $this->owner();

        $first = $this->beginAndGetState($workspace, $business);
        $second = $this->beginAndGetState($workspace, $business);

        $this->get($this->callbackUrl('google', ['state' => $first, 'code' => 'c']))->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));

        $this->get($this->callbackUrl('google', ['state' => $second, 'code' => 'c']))->assertRedirect();
        $this->assertSame(1, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    // ---- callback --------------------------------------------------------

    public function test_the_callback_activates_the_account_and_stores_the_credential_encrypted_at_rest(): void
    {
        [$customer, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);

        $response = $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'auth-code']));

        $response->assertRedirect(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]));
        $account = $this->account($business);
        $this->assertSame(State::Active, $account->state);
        $this->assertSame('owner@business.test', $account->mailbox_email);
        $this->assertSame('Owner Name', $account->display_name);
        $this->assertNotNull($account->connected_at);
        $this->assertSame((int) $customer->user_id, (int) $account->connected_by_user_id);

        $raw = (string) DB::table('business_email_accounts')->where('id', $account->id)->value('refresh_token_encrypted');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('fake-refresh-token', $raw);
        $this->assertSame('fake-refresh-token', Crypt::decryptString($raw));
        $this->assertNull($account->oauth_state_nonce, 'The nonce is consumed on use.');
    }

    public function test_a_replayed_callback_is_refused_and_exchanges_nothing_the_second_time(): void
    {
        [, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);
        $url = $this->callbackUrl('google', ['state' => $state, 'code' => 'auth-code']);

        $this->get($url)->assertRedirect();
        $this->get($url)->assertNotFound();

        $this->assertSame(1, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    #[DataProvider('badStates')]
    public function test_a_forged_missing_or_malformed_state_is_a_404_with_zero_exchange(?string $state): void
    {
        [, $business, $workspace] = $this->owner();
        $this->beginAndGetState($workspace, $business);

        $query = ['code' => 'auth-code'];

        if ($state !== null) {
            $query['state'] = $state;
        }

        $this->get($this->callbackUrl('google', $query))->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
        $this->assertSame(State::Pending, $this->account($business)->state);
    }

    /** @return array<string, array{0: ?string}> */
    public static function badStates(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'garbage' => ['not.a.state'],
            'unsigned payload' => [rtrim(strtr(base64_encode('{"b":1,"p":"google","n":"x","e":9999999999}'), '+/', '-_'), '=') . '.deadbeef'],
        ];
    }

    public function test_a_tampered_state_pointing_at_another_business_is_rejected(): void
    {
        [, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);

        [$encoded, $signature] = explode('.', $state, 2);
        $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
        $payload['b'] = $business->id + 1;
        $forged = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=') . '.' . $signature;

        $this->get($this->callbackUrl('google', ['state' => $forged, 'code' => 'c']))->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    public function test_an_expired_state_is_refused_with_zero_exchange(): void
    {
        [, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);

        $this->travel(11)->minutes();

        $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'c']))->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    public function test_a_state_for_one_provider_cannot_complete_the_other_providers_callback(): void
    {
        [, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business, 'google');

        $this->get($this->callbackUrl('microsoft', ['state' => $state, 'code' => 'c']))->assertNotFound();
        $this->assertSame(0, $this->fakeMicrosoft->callCount('exchangeAuthorizationCode'));
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    public function test_the_callback_cannot_bind_a_mailbox_to_another_business(): void
    {
        [, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);

        // A different tenant's owner follows the (leaked) state link.
        [$intruder, $otherBusiness] = $this->emailTenant('Other Business');
        $this->authenticateAs($intruder);

        $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'c']))->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
        $this->assertNull($this->account($otherBusiness));
        $this->assertSame(State::Pending, $this->account($business)->state);
    }

    public function test_a_different_member_cannot_complete_another_actors_attempt_and_cannot_burn_the_nonce(): void
    {
        [$owner, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);

        $colleague = $this->createCustomer();
        $this->member($workspace, $colleague->user, WorkspaceMembershipRole::Admin);
        $this->authenticateAs($colleague);

        $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'c']))->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));

        // The rightful actor can still finish: the nonce was not burned.
        $this->authenticateAs($owner);
        $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'c']))->assertRedirect();
        $this->assertSame(State::Active, $this->account($business)->state);
    }

    public function test_a_callback_without_the_manage_permission_is_a_404_with_zero_exchange(): void
    {
        [$customer, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);

        $this->authenticateAs($customer, ['chat_box', 'view_contact']);

        $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'c']))->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    public function test_the_callback_never_authenticates_or_creates_a_user(): void
    {
        [, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);
        $users = User::query()->count();

        // Unauthenticated: the route sits inside the authenticated group.
        auth()->logout();
        $this->flushSession();
        $response = $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'c']));

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertGuest();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
        $this->assertSame($users, User::query()->count());
    }

    public function test_a_successful_callback_does_not_change_the_logged_in_identity_or_create_users(): void
    {
        [$customer, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);
        $users = User::query()->count();

        // The mailbox the provider reports is NOT the logged-in user's email.
        $this->fakeGoogle->mailbox = 'someone.else@elsewhere.test';
        $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'c']));

        $this->assertSame((int) $customer->user_id, (int) auth()->id());
        $this->assertSame($users, User::query()->count());
        $this->assertSame('someone.else@elsewhere.test', $this->account($business)->mailbox_email);
    }

    public function test_a_grant_without_a_refresh_token_fails_closed_and_stays_pending(): void
    {
        [, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);
        $this->fakeGoogle->codeExchangeRefreshToken = null;

        $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'c']))->assertRedirect();

        $account = $this->account($business);
        $this->assertSame(State::Pending, $account->state);
        $this->assertNull($account->refresh_token_encrypted);
        $this->assertNull($account->mailbox_email);
    }

    public function test_a_provider_refusal_such_as_missing_send_permission_leaves_the_account_pending(): void
    {
        [, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);
        $this->fakeGoogle->codeExchangeFailure = new BusinessEmailProviderException(Category::PermissionDenied, 'send_scope_not_granted');

        $this->get($this->callbackUrl('google', ['state' => $state, 'code' => 'c']))->assertRedirect();

        $this->assertSame(State::Pending, $this->account($business)->state);
        $this->assertNull($this->account($business)->refresh_token_encrypted);
    }

    public function test_a_provider_error_redirect_exchanges_nothing(): void
    {
        [, $business, $workspace] = $this->owner();
        $state = $this->beginAndGetState($workspace, $business);

        $this->get($this->callbackUrl('google', ['state' => $state, 'error' => 'access_denied']))->assertRedirect();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
        $this->assertSame(State::Pending, $this->account($business)->state);
    }

    // ---- disconnect / reconnect -----------------------------------------

    public function test_disconnect_destroys_the_credential_revokes_at_the_provider_and_stops_sending(): void
    {
        [, $business, $workspace] = $this->owner();
        $this->activeAccount($business);

        $this->post(route('customer.workspaces.businesses.email.disconnect', [$workspace->uid, $business->uid]))->assertRedirect();

        $account = $this->account($business);
        $this->assertSame(State::Disconnected, $account->state);
        $this->assertNull($account->refresh_token_encrypted);
        $this->assertNull(DB::table('business_email_accounts')->where('id', $account->id)->value('refresh_token_encrypted'));
        $this->assertNull($account->granted_scopes);
        $this->assertNotNull($account->disconnected_at);
        $this->assertSame(1, $this->fakeGoogle->callCount('revokeGrant'));
        $this->assertFalse($account->isActive());
    }

    public function test_disconnect_requires_the_manage_permission(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $this->authenticateAs($customer, ['chat_box', 'view_contact']);

        $this->post(route('customer.workspaces.businesses.email.disconnect', [$workspace->uid, $business->uid]))->assertStatus(401);

        $this->assertSame(State::Active, $this->account($business)->state);
        $this->assertSame(0, $this->fakeGoogle->callCount('revokeGrant'));
    }

    public function test_reconnecting_after_a_revocation_starts_clean_and_can_switch_provider(): void
    {
        [, $business, $workspace] = $this->owner();
        $this->activeAccount($business);
        app(BusinessEmailAccountManager::class)->revoke($this->account($business));
        $this->assertSame(State::Revoked, $this->account($business)->state);

        $state = $this->beginAndGetState($workspace, $business, 'microsoft');

        $account = $this->account($business);
        $this->assertSame(State::Pending, $account->state);
        $this->assertSame(BusinessEmailProviderType::Microsoft, $account->provider);
        $this->assertNull($account->refresh_token_encrypted);
        $this->assertNull($account->mailbox_email);

        $this->get($this->callbackUrl('microsoft', ['state' => $state, 'code' => 'c']))->assertRedirect();

        $account = $this->account($business);
        $this->assertSame(State::Active, $account->state);
        $this->assertNull($account->revoked_at);
        $this->assertSame(1, $this->fakeMicrosoft->callCount('exchangeAuthorizationCode'));
        $this->assertSame(1, BusinessEmailAccount::query()->where('business_id', $business->id)->count(), 'One account per Business.');
    }

    public function test_the_database_allows_only_one_account_per_business(): void
    {
        [, $business] = $this->owner();
        $this->activeAccount($business);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        BusinessEmailAccount::create(['business_id' => $business->id, 'provider' => BusinessEmailProviderType::Microsoft, 'state' => State::Pending]);
    }

    // ---- token lifecycle -------------------------------------------------

    public function test_a_provider_revocation_destroys_the_credential_and_marks_the_account_revoked(): void
    {
        [, $business] = $this->owner();
        $account = $this->activeAccount($business);
        $this->fakeGoogle->refreshFailure = new BusinessEmailProviderException(Category::AuthenticationExpired, 'invalid_grant', revocation: true);

        try {
            app(BusinessEmailAccountManager::class)->accessTokenFor($account);
            $this->fail('Expected a provider exception.');
        } catch (BusinessEmailProviderException $exception) {
            $this->assertTrue($exception->isRevocation());
        }

        $fresh = $this->account($business);
        $this->assertSame(State::Revoked, $fresh->state);
        $this->assertNull(DB::table('business_email_accounts')->where('id', $account->id)->value('refresh_token_encrypted'));
        $this->assertNotNull($fresh->revoked_at);
    }

    public function test_a_rotated_refresh_token_is_stored_encrypted_and_replaces_the_old_one(): void
    {
        [, $business] = $this->owner();
        $account = $this->activeAccount($business);
        $this->fakeMicrosoft->rotatedRefreshToken = 'rotated-token-xyz';
        DB::table('business_email_accounts')->where('id', $account->id)->update(['provider' => 'microsoft']);

        $access = app(BusinessEmailAccountManager::class)->accessTokenFor($account->fresh());

        $this->assertSame('fake-access-token', $access);
        $raw = (string) DB::table('business_email_accounts')->where('id', $account->id)->value('refresh_token_encrypted');
        $this->assertStringNotContainsString('rotated-token-xyz', $raw);
        $this->assertSame('rotated-token-xyz', Crypt::decryptString($raw));
    }

    public function test_a_refresh_racing_a_disconnect_cannot_resurrect_the_credential(): void
    {
        [, $business] = $this->owner();
        $account = $this->activeAccount($business);
        $this->fakeGoogle->rotatedRefreshToken = 'rotated-after-disconnect';

        // The Business disconnects while the refresh call is in flight.
        $this->fakeGoogle->duringRefresh = function () use ($business): void {
            app(BusinessEmailAccountManager::class)->disconnect($this->account($business));
        };

        app(BusinessEmailAccountManager::class)->accessTokenFor($account);

        $fresh = $this->account($business);
        $this->assertSame(State::Disconnected, $fresh->state);
        $this->assertNull(DB::table('business_email_accounts')->where('id', $account->id)->value('refresh_token_encrypted'));
    }

    public function test_a_stale_writer_loses_the_optimistic_lock_race(): void
    {
        [, $business] = $this->owner();
        $this->activeAccount($business);
        $stale = $this->account($business);

        app(BusinessEmailAccountManager::class)->disconnect($this->account($business));

        $this->expectException(BusinessEmailConcurrencyException::class);
        app(BusinessEmailAccountManager::class)->disconnect($stale);
    }

    public function test_a_non_active_account_cannot_mint_an_access_token(): void
    {
        [, $business] = $this->owner();
        $account = $this->activeAccount($business);
        app(BusinessEmailAccountManager::class)->disconnect($account);

        try {
            app(BusinessEmailAccountManager::class)->accessTokenFor($this->account($business));
            $this->fail('Expected a refusal.');
        } catch (BusinessEmailProviderException $exception) {
            $this->assertSame(Category::DisconnectedAccount, $exception->category);
        }

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeRefreshToken'));
    }

    // ---- tenancy and membership -----------------------------------------

    public function test_another_tenants_user_cannot_see_or_connect_this_business(): void
    {
        [, $business, $workspace] = $this->owner();
        [$stranger] = $this->emailTenant('Stranger Business');
        $this->authenticateAs($stranger);

        $this->get(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]))->assertNotFound();
        $this->post($this->connectUrl($workspace, $business))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.email.disconnect', [$workspace->uid, $business->uid]))->assertNotFound();

        $this->assertNull($this->account($business));
    }

    public function test_a_member_scoped_to_other_businesses_and_an_inactive_member_have_no_access(): void
    {
        [, $business, $workspace] = $this->owner();

        $scoped = $this->createCustomer();
        $this->member($workspace, $scoped->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->authenticateAs($scoped);
        $this->get(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]))->assertNotFound();
        $this->post($this->connectUrl($workspace, $business))->assertNotFound();

        $inactive = $this->createCustomer();
        $this->member($workspace, $inactive->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, active: false);
        $this->authenticateAs($inactive);
        $this->get(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]))->assertNotFound();

        $this->assertNull($this->account($business));
    }

    public function test_an_assigned_staff_member_with_the_permission_may_connect_but_without_it_may_not(): void
    {
        [, $business, $workspace] = $this->owner();

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);

        $this->authenticateAs($staff, ['chat_box', 'view_contact']);
        $this->post($this->connectUrl($workspace, $business))->assertStatus(401);
        $this->assertNull($this->account($business));

        $this->authenticateAs($staff, ['manage_business_email']);
        $this->post($this->connectUrl($workspace, $business))->assertRedirect();
        $this->assertSame(State::Pending, $this->account($business)->state);
    }

    public function test_a_view_as_agency_actor_can_neither_connect_disconnect_nor_send(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(\App\Enums\Entitlement\WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $this->activeAccount($viewed);
        $contact = $this->contactWithEmails($viewed, ['pat@example.com']);

        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        $ws = $workspace->uid;
        $biz = $viewed->uid;

        foreach ([
            $this->post(route('customer.workspaces.businesses.email.connect', [$ws, $biz, 'google'])),
            $this->post(route('customer.workspaces.businesses.email.disconnect', [$ws, $biz])),
            $this->post(route('customer.workspaces.businesses.email.send', [$ws, $biz]), [
                'contact_uid' => $contact->uid, 'subject' => 's', 'body' => 'b', 'send_token' => (string) \Illuminate\Support\Str::uuid(),
            ]),
        ] as $response) {
            // The platform refuses a prohibited View-As action by sending the
            // actor back to the dashboard (or a 403/404) — never to the provider.
            $this->assertContains($response->getStatusCode(), [302, 403, 404]);

            if ($response->getStatusCode() === 302) {
                $this->assertSame(route('user.home'), $response->headers->get('Location'));
            }
        }

        $account = $this->account($viewed);
        $this->assertSame(State::Active, $account->state);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        $this->assertSame(0, $this->fakeGoogle->callCount('revokeGrant'));
        $this->assertSame(0, \App\Models\BusinessEmailMessage::query()->count());

        // Reading the status page is ordinary support work and stays allowed.
        $this->get(route('customer.workspaces.businesses.email.show', [$ws, $biz]))->assertOk();
    }
}
