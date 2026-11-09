<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Business\OnboardingManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use App\Library\ViewAs\ViewAsManager;
use App\Library\Workspace\WorkspaceManagementAuthority;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * After onboarding, the Workspace OWNER of a genuinely provisioned customer (default permissions,
 * manage_google_business_profile NOT held) can manage their own Google connection, through the same
 * WorkspaceManagementAuthority rule Google Ads and Meta Ads use. Nobody else gains anything.
 */
class GoogleOwnerManagementTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['business.onboarding.enabled' => true]);
        $this->bindFakeGoogleClient();
    }

    public function test_the_owner_connects_during_draft_onboarding_then_manages_the_connection_once_active(): void
    {
        [$customer, $business, $workspace] = $this->provisionedOwner();
        $this->fakeGoogleWithLocation();

        // Draft onboarding: connect, choose, confirm, finish.
        $state = $this->stateFrom($this->post(route('customer.onboarding.google.connect')));
        $this->get(route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE) . '?code=c&state=' . urlencode($state))
            ->assertRedirect(route('customer.onboarding.google.show'));

        preg_match('/name="candidate_token" value="([^"]+)"/', $this->get(route('customer.onboarding.google.show'))->getContent(), $m);
        $this->post(route('customer.onboarding.google.select'), ['candidate_token' => html_entity_decode($m[1])])->assertRedirect();
        $this->post(route('customer.onboarding.google.confirm'), [
            'snapshot' => (string) session('onboarding.google.snapshot'),
            'name' => 'Signup Booths', 'industry' => 'photo_booth_service', 'phone' => '',
            'description' => '', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
            'service_mode' => 'online', 'main_service' => 'Photo Booth Rental', 'website_choice' => 'later',
        ])->assertSessionHasNoErrors();
        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $this->assertSame(BusinessStatus::Active, $business->refresh()->status);
        $this->assertFalse(Gate::forUser($customer->user)->allows('manage_google_business_profile'), 'The permission was never granted.');

        // Active Business: the same owner reaches and manages the module.
        $args = [$workspace->uid, $business->uid];
        $this->get(route('customer.workspaces.businesses.gbp.settings', $args))->assertOk()->assertSee('Disconnect Google account');
        $this->get(route('customer.workspaces.businesses.gbp.index', $args))->assertOk()->assertDontSee('You do not have permission to manage this connection.');

        $this->post(route('customer.workspaces.businesses.gbp.disconnect', $args))->assertRedirect();
        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(GoogleConnectionState::Disconnected, $connection->state);
        $this->assertFalse($connection->hasStoredAuthorization());

        $reconnect = $this->post(route('customer.workspaces.businesses.gbp.connect', $args));
        $reconnect->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.test/authorize', (string) $reconnect->headers->get('Location'));
    }

    public function test_a_non_owner_needs_the_explicit_permission_and_an_authorized_one_still_works(): void
    {
        [, $business, $workspace] = $this->provisionedOwner();
        $this->activate($business);
        $args = [$workspace->uid, $business->uid];

        $member = $this->createCustomer()->user;
        $this->addMember($workspace, $member, WorkspaceMembershipRole::Admin);

        $this->authenticateAsUser($member, ['view_google_business_profile']);
        $this->post(route('customer.workspaces.businesses.gbp.connect', $args))->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.gbp.disconnect', $args))->assertStatus(401);
        $this->get(route('customer.workspaces.businesses.gbp.index', $args))->assertOk()->assertSee('You do not have permission to manage this connection.');
        $this->assertSame(0, BusinessGoogleConnection::query()->count());

        // The explicit permission (existing behaviour) still authorizes a non-owner.
        $this->authenticateAsUser($member, ['view_google_business_profile', 'manage_google_business_profile']);
        $this->post(route('customer.workspaces.businesses.gbp.connect', $args))->assertRedirect();
        $this->assertSame(1, BusinessGoogleConnection::query()->count());
    }

    public function test_another_workspaces_owner_cannot_reach_or_manage_it(): void
    {
        [, $business, $workspace] = $this->provisionedOwner();
        $this->activate($business);
        $this->activeConnection($business);

        [$stranger] = $this->provisionedOwner();
        $args = [$workspace->uid, $business->uid];

        $this->post(route('customer.workspaces.businesses.gbp.disconnect', $args))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.gbp.connect', $args))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.gbp.index', $args))->assertNotFound();

        $this->assertSame(GoogleConnectionState::Active, BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail()->state);
        $this->assertNotNull($stranger);
    }

    public function test_view_as_never_provides_owner_authority(): void
    {
        [$customer, $business, $workspace] = $this->provisionedOwner();
        $this->activate($business);
        $connection = $this->activeConnection($business);
        $owner = $customer->user;
        $authority = app(WorkspaceManagementAuthority::class);

        $this->assertTrue($authority->allows($owner, $workspace->refresh(), 'manage_google_business_profile'), 'Owner authority outside View-As.');

        // A real, live View-As session for this actor.
        app(ViewAsManager::class)->start($owner, $workspace->uid, $business->uid);
        $this->assertNotNull(app(ViewAsManager::class)->current($owner), 'The View-As session is live.');

        $this->assertFalse($authority->allows($owner, $workspace, 'manage_google_business_profile'), 'A live View-As session is never owner authority.');

        $this->post(route('customer.workspaces.businesses.gbp.disconnect', [$workspace->uid, $business->uid]));
        $this->assertSame(GoogleConnectionState::Active, $connection->refresh()->state, 'Nothing was disconnected while viewing as.');
        $this->assertTrue($connection->hasStoredAuthorization());
    }

    public function test_missing_entitlement_still_blocks_connecting_but_never_traps_a_disconnect(): void
    {
        [, $business, $workspace] = $this->provisionedOwner(WorkspacePlanTier::Core);
        $this->activate($business);
        $connection = $this->activeConnection($business);
        $args = [$workspace->uid, $business->uid];

        $this->post(route('customer.workspaces.businesses.gbp.connect', $args))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.gbp.locations', $args))->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('authorizationUrl'));

        // Existing contract §39.4: disconnect skips entitlement so credentials are never trapped by a downgrade.
        $this->post(route('customer.workspaces.businesses.gbp.disconnect', $args))->assertRedirect();
        $this->assertSame(GoogleConnectionState::Disconnected, $connection->refresh()->state);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A customer provisioned exactly as signup does: the real default permission set (nothing granted by hand), a
     * Workspace they own on a plan, one real Draft Business attached to their onboarding. Signed in with those defaults.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    private function provisionedOwner(WorkspacePlanTier $tier = WorkspacePlanTier::Growth): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->reserveSuperAdminId();

        $defaults = json_decode(Customer::customerPermissions(), true);
        $this->assertNotContains('manage_google_business_profile', $defaults);

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Signup Booths']));
        $workspace = Workspace::query()->findOrFail($business->workspace_id);
        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->platformAdminId(), 'Fixture.', true, 0);
        app(OnboardingManager::class)->startForProvisionedBusiness($customer, $business);

        $this->authenticateAsCustomer($customer, $defaults);

        return [$customer, $business->refresh(), $workspace];
    }

    private function activate(Business $business): void
    {
        $business->forceFill(['status' => BusinessStatus::Active])->save();
    }

    private function stateFrom($response): string
    {
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }
}
