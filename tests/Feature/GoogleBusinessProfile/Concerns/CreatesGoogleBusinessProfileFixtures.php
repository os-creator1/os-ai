<?php

namespace Tests\Feature\GoogleBusinessProfile\Concerns;

use App\DTO\GoogleBusinessProfile\GoogleLocationCandidate;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Library\GoogleBusinessProfile\FakeGoogleBusinessProfileReadClient;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileCallBudget;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileCandidateTokenSigner;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * GBP Slice A contract §32 — shared fixtures.
 *
 * Mirrors CreatesWebsiteFixtures / CreatesAutomationFixtures so the three
 * Business-scoped suites stay directly comparable, with one deliberate
 * difference: the default tier here is GROWTH, not Core, because
 * google_business_profile_module is packaged to Growth and Agency ONLY
 * (contract §2.1). coreTenant() exists specifically to prove Core is
 * excluded.
 *
 * EVERY test binds FakeGoogleBusinessProfileReadClient, so no real HTTP
 * request to any googleapis.com host is ever attempted and no Google
 * credential is required (contract §34, tests T-FAKE-1/T-FAKE-2).
 */
trait CreatesGoogleBusinessProfileFixtures
{
    use CreatesBusinessTestData;

    protected FakeGoogleBusinessProfileReadClient $fakeGoogle;

    protected function bindFakeGoogleClient(): FakeGoogleBusinessProfileReadClient
    {
        // The budget is a container SINGLETON: the Fake must share the very
        // instance the services set their reservation context on, exactly
        // as the real client does (correction item 6).
        $this->fakeGoogle = new FakeGoogleBusinessProfileReadClient(app(GoogleBusinessProfileCallBudget::class));
        $this->app->instance(GoogleBusinessProfileReadClient::class, $this->fakeGoogle);

        $this->configureValidOAuthCredentials();

        return $this->fakeGoogle;
    }

    /**
     * Correction pass item 7 — OAuth configuration is now validated before
     * any connect writes state, so the default fixture must supply a
     * COMPLETE and MATCHING configuration. The redirect must equal the one
     * fixed callback URL exactly, which is what production must register
     * with Google.
     *
     * The dedicated configuration-failure tests override these afterwards.
     */
    protected function configureValidOAuthCredentials(): void
    {
        config([
            'services.google_business_profile.client_id' => 'test-client-id',
            'services.google_business_profile.client_secret' => 'test-client-secret',
            'services.google_business_profile.redirect' => route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE),
        ]);
    }

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function entitledTenant(WorkspacePlanTier $tier = WorkspacePlanTier::Growth): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        // EloquentAccountRepository::hasPermission() short-circuits with
        // "First user is always super admin" for user id 1, so a customer
        // that happens to be id 1 bypasses EVERY permission gate. MySQL
        // does not reset AUTO_INCREMENT on RefreshDatabase's transaction
        // rollback, so which test gets id 1 depends on declaration order —
        // making permission assertions silently order-dependent.
        //
        // Burning id 1 on a platform admin here makes every permission
        // assertion in this suite deterministic and meaningful.
        $this->reserveSuperAdminId();

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('businesses')->where('id', $business->id)->update([
            'status' => BusinessStatus::Active->value,
            'phone' => '+15550101234',
            'website_url' => 'https://example.test',
        ]);

        $workspace = Workspace::query()->findOrFail($business->workspace_id);

        app(EntitlementManager::class)->assignFirstPlan(
            $workspace,
            $tier,
            $this->platformAdminId(),
            'GBP fixture assignment.',
            true,
            0,
        );

        return [$customer, $business->fresh(), $workspace->fresh()];
    }

    /**
     * Contract §2.1 — Core is EXCLUDED. Used by T-ENT-3/T-ENT-4.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function coreTenant(): array
    {
        return $this->entitledTenant(WorkspacePlanTier::Core);
    }

    /**
     * Ensures user id 1 exists and is NOT a test customer, so the
     * super-admin short-circuit in hasPermission() can never mask a
     * permission failure. Idempotent within a test.
     */
    private function reserveSuperAdminId(): void
    {
        if (User::query()->count() > 0) {
            return;
        }

        $this->platformAdminId();
    }

    protected function platformAdminId(): int
    {
        return User::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'email' => 'platform-admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    /**
     * A BusinessLocation. `publicAddress` drives the whole §23
     * private-address invariant, so every privacy test sets it explicitly.
     */
    protected function createLocation(
        Business $business,
        bool $publicAddress = true,
        BusinessServiceMode $mode = BusinessServiceMode::Storefront,
        array $overrides = [],
    ): BusinessLocation {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'name' => 'Primary Location',
            'service_mode' => $mode,
            'address_line_1' => '77 Secret Lane',
            'city' => 'New York',
            'region' => 'NY',
            'postal_code' => '10001',
            'country_code' => 'US',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'public_address' => $publicAddress,
            'is_primary' => true,
        ], $overrides));
    }

    protected function activeConnection(Business $business, array $overrides = []): BusinessGoogleConnection
    {
        return BusinessGoogleConnection::create(array_merge([
            'business_id' => $business->id,
            'state' => GoogleConnectionState::Active,
            'refresh_token_encrypted' => 'plain-refresh-token-value',
            'granted_scopes' => 'https://www.googleapis.com/auth/business.manage',
            'google_account_email' => 'owner@example.test',
            'connected_at' => now(),
        ], $overrides));
    }

    protected function addMember(Workspace $workspace, User $user, WorkspaceMembershipRole $role, bool $isActive = true, WorkspaceBusinessAccessScope $scope = WorkspaceBusinessAccessScope::All): WorkspaceMembership
    {
        return WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'business_access_scope' => $scope->value,
            'is_active' => $isActive,
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function authenticateAsCustomer(Customer $customer, array $permissions = ['view_google_business_profile', 'manage_google_business_profile']): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function authenticateAsUser(User $user, array $permissions = ['view_google_business_profile', 'manage_google_business_profile']): void
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($user);
    }

    protected function ensureRequiredAppConfigRowsExist(): void
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

    /**
     * Correction pass item 5 — a valid candidate token, as the chooser
     * would have issued it. Binding accepts nothing else, so every bind
     * test goes through this (or deliberately corrupts it).
     */
    protected function candidateTokenFor(
        Business $business,
        BusinessGoogleConnection $connection,
        int $actorUserId,
        string $accountResourceName = 'accounts/A1',
        string $locationResourceName = 'locations/L1',
    ): string {
        return app(GoogleBusinessProfileCandidateTokenSigner::class)->issue(
            $business,
            $connection,
            $actorUserId,
            new GoogleLocationCandidate(
                resourceName: $locationResourceName,
                accountResourceName: $accountResourceName,
                title: null,
                storeCode: null,
                localityHint: null,
                regionCode: null,
            ),
        );
    }

    /**
     * A raw Google location payload that DELIBERATELY CONTAINS a
     * storefrontAddress, so the privacy tests can prove the address is
     * discarded before it reaches a DTO even when Google returns it
     * (contract §23.3).
     *
     * @return array<string, mixed>
     */
    protected function rawLocationPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'locations/L1',
            'title' => 'Snap Booth Co',
            'phoneNumbers' => ['primaryPhone' => '+1 (555) 010-1234'],
            'websiteUri' => 'https://example.test',
            'categories' => [
                'primaryCategory' => ['name' => 'categories/gcid:photo_booth', 'displayName' => 'Photo booth'],
            ],
            'storefrontAddress' => [
                'addressLines' => ['77 Secret Lane'],
                'locality' => 'New York',
                'regionCode' => 'US',
                'postalCode' => '10001',
            ],
            'latlng' => ['latitude' => 40.7128, 'longitude' => -74.0060],
            'openInfo' => ['status' => 'OPEN'],
            'metadata' => [
                'hasVoiceOfMerchant' => true,
                'hasPendingEdits' => false,
                'mapsUri' => 'https://maps.google.test/place',
                'newReviewUri' => 'https://search.google.test/review',
            ],
        ], $overrides);
    }

    /**
     * Registers one account + one location on the Fake, using the raw
     * payload above.
     */
    protected function fakeGoogleWithLocation(array $rawOverrides = []): void
    {
        $raw = $this->rawLocationPayload($rawOverrides);

        $this->fakeGoogle->withAccount('accounts/A1', 'Snap Booth Co');
        $this->fakeGoogle->withLocation('accounts/A1', $raw['name'], $raw);
    }
}
