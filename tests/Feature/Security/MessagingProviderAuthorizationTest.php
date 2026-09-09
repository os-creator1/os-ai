<?php

namespace Tests\Feature\Security;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\SendingServer;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Security Remediation Slice 0 §16.A.3 (D-9) — the actual, fail-closed,
 * server-side authorization boundary on MessagingChannelsController's
 * eight public methods. Menu visibility
 * (CustomerMenuBuilder::advancedItems()) was never authorization; this
 * proves the boundary is the controller, independent of navigation, and
 * independent of the pre-existing `view_numbers` permission (default true
 * for every customer).
 */
class MessagingProviderAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private int $platformAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId = User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;

        $this->ensureRequiredAppConfigRowsExist();
    }

    // -----------------------------------------------------------------
    // Positive access — the change must be narrowing, not breaking.
    // -----------------------------------------------------------------

    public function test_an_agency_owner_with_the_entitlement_retains_full_access(): void
    {
        [$owner, $business] = $this->tenantWithPlan(WorkspacePlanTier::Agency);
        $this->authenticateAsCustomer($owner, ['view_numbers']);

        $response = $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]));

        $this->assertGuardAllowsAccess($response);
    }

    public function test_get_channels_index_does_not_redirect_a_core_actor_into_the_surface(): void
    {
        [$owner, $business] = $this->tenantWithPlan(WorkspacePlanTier::Core);
        $this->authenticateAsCustomer($owner, ['view_numbers']);

        // Exactly one accessible Business existed before this fix, which
        // would have auto-redirected straight into the guarded surface.
        // After the fix, a Core-only actor's filtered accessible list is
        // empty, so entry() must fall through to its own (unguarded)
        // chooser/empty-state branch instead of redirecting. This asserts
        // the redirect decision itself — not the page content — so it
        // holds even in an environment (this sandbox, and unmodified
        // origin/main alike) where the shared customer layout cannot
        // compile because of pre-existing, unrelated missing frontend
        // build artifacts (Mix manifest / BladeUI icon manifest).
        $response = $this->get(route('customer.channels.index'));

        $this->assertFalse(
            $response->isRedirect(),
            'entry() must not auto-redirect a denied actor into a Business it cannot access.',
        );
        $this->assertNotSame(
            route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]),
            $response->headers->get('Location'),
        );
    }

    // -----------------------------------------------------------------
    // Entitlement is checked, not inferred from tier alone.
    // -----------------------------------------------------------------

    public function test_an_agency_owner_whose_plan_does_not_package_the_capability_receives_404(): void
    {
        [$owner, $business] = $this->tenantWithPlan(WorkspacePlanTier::Agency);

        app(EntitlementManager::class)->createOrChangeOverride(
            $business->workspace,
            PlatformFeature::Conversations,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId,
            'Test: deny messaging entitlement.',
        );

        $this->authenticateAsCustomer($owner, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]))
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Every one of the eight routes, for every denied actor class, GET
    // and mutation alike.
    // -----------------------------------------------------------------

    public static function deniedActorProvider(): array
    {
        return ['core owner' => ['core'], 'growth owner' => ['growth'], 'agency staff without manage rights' => ['agencyStaff'], 'restricted business user' => ['restrictedStaff']];
    }

    /**
     * @dataProvider deniedActorProvider
     */
    public function test_every_read_route_denies_the_actor_by_direct_url(string $actorKind): void
    {
        [$actor, $business, $connection] = $this->deniedActorWithConnection($actorKind);
        $this->authenticateAsCustomer($actor, ['view_numbers']);
        $args = [$business->workspace->uid, $business->uid];

        // entry() itself must not 404 and must not redirect a denied actor
        // into the Business it cannot access — it should fall through to
        // its own (now-filtered) empty/chooser state. Checked at the
        // status/redirect level, not by rendering that state: the shared
        // customer layout cannot compile in this sandbox for reasons
        // unrelated to this change (see the class docblock), and that gap
        // is reproduced identically on unmodified origin/main.
        $entryResponse = $this->get(route('customer.channels.index'));
        $this->assertNotSame(404, $entryResponse->getStatusCode());
        $this->assertFalse($entryResponse->isRedirect());

        $this->get(route('customer.workspaces.businesses.channels.index', $args))->assertStatus(404);
        $this->get(route('customer.workspaces.businesses.channels.connect', array_merge($args, [SendingServer::TYPE_TWILIO])))->assertStatus(404);
        $this->get(route('customer.workspaces.businesses.channels.connections.show', [...$args, $connection->uid]))->assertStatus(404);
    }

    /**
     * @dataProvider deniedActorProvider
     */
    public function test_every_mutation_route_denies_the_actor_and_leaves_state_unchanged(string $actorKind): void
    {
        [$actor, $business, $connection] = $this->deniedActorWithConnection($actorKind);
        $originalServer = SendingServer::find($connection->sending_server)->toArray();
        $originalConnectionStatus = $connection->status;

        $this->authenticateAsCustomer($actor, ['view_numbers']);
        $args = [$business->workspace->uid, $business->uid];

        $this->post(route('customer.workspaces.businesses.channels.connect', array_merge($args, [SendingServer::TYPE_TWILIO])), [
            'account_sid' => 'SHOULD_NOT_BE_SAVED',
            'auth_token' => 'should_not_be_saved',
        ])->assertStatus(404);
        $this->assertDatabaseMissing('sending_servers', ['account_sid' => 'SHOULD_NOT_BE_SAVED']);

        $this->put(route('customer.workspaces.businesses.channels.connections.update', [...$args, $connection->uid]), [
            'account_sid' => 'HACKED',
            'auth_token' => 'hacked_token',
        ])->assertStatus(404);

        $this->post(route('customer.workspaces.businesses.channels.connections.enable', [...$args, $connection->uid]))
            ->assertStatus(404);

        $this->post(route('customer.workspaces.businesses.channels.connections.disable', [...$args, $connection->uid]))
            ->assertStatus(404);

        $this->assertSame($originalServer, SendingServer::find($connection->sending_server)->fresh()->toArray());
        $this->assertSame($originalConnectionStatus, $connection->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Business}
     */
    private function tenantWithPlan(WorkspacePlanTier $tier): array
    {
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());

        app(EntitlementManager::class)->assignFirstPlan(
            $business->workspace,
            $tier,
            $this->platformAdminId,
            'Test fixture assignment.',
            true,
            0,
        );

        // createBusinessWithWorkspace() always starts a Business in Draft
        // (real onboarding's transient starting state). The guard also
        // requires active Business state, so every fixture here is
        // activated — denial in the tier/role/tenancy tests below must
        // come from the dimension each test actually targets, not be
        // conflated with an incidentally-Draft fixture.
        app(BusinessRepository::class)->updateStatus($business, BusinessStatus::Active);

        return [$tenant, $business->fresh()];
    }

    /**
     * @return array{0: Customer, 1: Business, 2: CustomerBasedSendingServer}
     */
    private function deniedActorWithConnection(string $kind): array
    {
        return match ($kind) {
            'core' => $this->coreOrGrowthDeniedActor(WorkspacePlanTier::Core),
            'growth' => $this->coreOrGrowthDeniedActor(WorkspacePlanTier::Growth),
            'agencyStaff' => $this->agencyStaffDeniedActor(fullScope: true),
            default => $this->agencyStaffDeniedActor(fullScope: false),
        };
    }

    /**
     * @return array{0: Customer, 1: Business, 2: CustomerBasedSendingServer}
     */
    private function coreOrGrowthDeniedActor(WorkspacePlanTier $tier): array
    {
        [$owner, $business] = $this->tenantWithPlan($tier);
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);

        return [$owner, $business, $connection];
    }

    /**
     * fullScope=true: an Agency-tier Workspace staff member with 'all'
     * business_access_scope but a plain 'staff' role — denied because the
     * guard requires owner-or-active-admin, not merely Business access.
     *
     * fullScope=false ("restricted Business user"): the same 'staff' role,
     * scoped only to a DIFFERENT Business — denied twice over, by role
     * AND by tenancy.
     */
    private function agencyStaffDeniedActor(bool $fullScope): array
    {
        [, $business] = $this->tenantWithPlan(WorkspacePlanTier::Agency);
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);

        $staff = $this->createCustomer();

        if ($fullScope) {
            WorkspaceMembership::create([
                'workspace_id' => $business->workspace_id,
                'user_id' => $staff->user_id,
                'role' => 'staff',
                'business_access_scope' => 'all',
                'is_active' => true,
            ]);
        } else {
            WorkspaceMembership::create([
                'workspace_id' => $business->workspace_id,
                'user_id' => $staff->user_id,
                'role' => 'staff',
                'business_access_scope' => 'selected',
                'is_active' => true,
            ]);
            // No per-Business assignment row links $staff to $business at
            // all — the "selected" scope member has no explicit grant onto
            // it, denied twice over: by role AND by tenancy.
        }

        return [$staff, $business, $connection];
    }

    private function createDedicatedConnection(Business $business, string $provider, array $credentialOverrides = []): CustomerBasedSendingServer
    {
        $defaults = match ($provider) {
            SendingServer::TYPE_TWILIO => ['account_sid' => 'AC_DEFAULT', 'auth_token' => 'default_token'],
            SendingServer::TYPE_TELNYX => ['api_key' => 'default_key', 'c1' => 'default_profile', 'c2' => 'default_connection'],
            default => [],
        };

        $sendingServer = SendingServer::create(array_merge([
            'name' => $provider,
            'settings' => $provider,
            'status' => true,
            'plain' => true,
            'mms' => true,
            'user_id' => $business->customer_id,
        ], $defaults, $credentialOverrides));

        return CustomerBasedSendingServer::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'sending_server' => $sendingServer->id,
            'status' => true,
        ]);
    }

    /**
     * Proves the authorization guard allowed this actor through, without
     * requiring the shared customer layout to actually compile. In this
     * sandbox (and identically on unmodified origin/main — confirmed by
     * `git stash`-based reproduction), rendering the real channels views
     * fails on pre-existing, unrelated missing frontend build artifacts
     * (Laravel Mix manifest entries, the BladeUI icon manifest). A denied
     * actor gets abort(404) before any view is ever touched, so 404 is
     * always the unambiguous "the guard blocked this" signal; anything
     * else — 200, or the specific pre-existing render failure — means the
     * guard let the request through.
     */
    private function assertGuardAllowsAccess(\Illuminate\Testing\TestResponse $response): void
    {
        $this->assertNotSame(404, $response->getStatusCode(), 'The authorization guard denied an actor expected to pass.');

        if ($response->getStatusCode() === 200) {
            return;
        }

        $exception = $response->exception;
        $message = $exception?->getMessage() ?? '';

        $this->assertTrue(
            str_contains($message, 'Unable to locate Mix file') || str_contains($message, 'IconsManifest'),
            "Expected only the pre-existing Mix/IconsManifest asset-build gap reproduced on unmodified origin/main, got: {$message}",
        );
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions = []): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }
}
