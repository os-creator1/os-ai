<?php

namespace Tests\Feature\Business\Concerns;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\LocationSlotAllocationAuthority;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Customer Experience Slice 1A — shared fixtures for the physical-location
 * capacity suite.
 *
 * The default tier is GROWTH, because Core and Growth share the identical
 * location capacity (3 included, 4-5 paid, 6+ Agency) and Growth is the
 * ordinary paid customer. agencyTenant() proves the unlimited path.
 *
 * Locations are seeded DIRECTLY through the model here, not through
 * BusinessLocationManager, precisely so a fixture can construct an
 * over-capacity or pre-migration state that the product itself would
 * refuse — contract §7.3b point 3 permits test-support helpers to write
 * directly, and T-LOC-9 lists this trait as an approved non-production
 * seam.
 */
trait CreatesLocationCapacityFixtures
{
    use CreatesBusinessTestData;

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function locationTenant(WorkspacePlanTier $tier = WorkspacePlanTier::Growth): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->reserveSuperAdminId();

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('businesses')->where('id', $business->id)->update([
            'status' => BusinessStatus::Active->value,
        ]);

        $workspace = Workspace::query()->findOrFail($business->workspace_id);

        app(EntitlementManager::class)->assignFirstPlan(
            $workspace,
            $tier,
            $this->platformAdminId(),
            'Location capacity fixture assignment.',
            true,
            0,
        );

        return [$customer, $business->fresh(), $workspace->fresh()];
    }

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function agencyTenant(): array
    {
        return $this->locationTenant(WorkspacePlanTier::Agency);
    }

    /**
     * Seeds one location directly, bypassing the capacity boundary. Used
     * only to construct pre-existing / over-capacity states.
     */
    protected function seedLocation(
        Business $business,
        string $name,
        bool $isPrimary = false,
        BusinessLocationLifecycleState $state = BusinessLocationLifecycleState::Active,
    ): BusinessLocation {
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => BusinessServiceMode::Storefront,
            'address_line_1' => '1 Test Street',
            'city' => 'New York',
            'country_code' => 'US',
            'public_address' => true,
        ]);

        $location->forceFill([
            'is_primary' => $isPrimary,
            'lifecycle_state' => $state,
            'archived_at' => $state === BusinessLocationLifecycleState::Archived ? now() : null,
        ])->save();

        return $location->refresh();
    }

    /**
     * Seeds $count ACTIVE locations, the first of them primary.
     *
     * @return array<int, BusinessLocation>
     */
    protected function seedActiveLocations(Business $business, int $count): array
    {
        $locations = [];

        for ($index = 1; $index <= $count; $index++) {
            $locations[] = $this->seedLocation($business, 'Branch ' . $index, $index === 1);
        }

        return $locations;
    }

    /**
     * The payload the product itself would post to create a location.
     *
     * @return array<string, mixed>
     */
    protected function locationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Branch',
            'service_mode' => BusinessServiceMode::Storefront->value,
            'address_line_1' => '2 Test Street',
            'city' => 'New York',
            // UpsertBusinessLocationRequest conditionally requires `region`
            // for a storefront or service-area location, so the shared
            // payload carries it and is valid for the edit route as well as
            // the create route.
            'region' => 'NY',
            'country_code' => 'US',
            'public_address' => true,
        ], $overrides);
    }

    protected function setAdditionalLocationSlots(Business $business, int $count): void
    {
        DB::table('businesses')->where('id', $business->id)->update(['additional_location_slots' => $count]);
    }

    protected function setGrandfatheredLocationSlots(Business $business, int $count): void
    {
        DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => $count]);
    }

    /**
     * Correction round 1 — the paid-allocation seam refuses a bare actor
     * id, so a domain test must present real provenance. Platform-operator
     * provenance is used here because it is re-verifiable inside
     * EntitlementManager against users.is_admin; a fresh administrator is
     * created so the id genuinely holds that flag.
     */
    protected function operatorLocationSlotAuthority(string $reason = 'Test-support operator allocation.'): LocationSlotAllocationAuthority
    {
        return LocationSlotAllocationAuthority::fromPlatformOperator($this->platformAdminId(), $reason);
    }

    /**
     * The other permitted provenance: a future billing caller that has
     * already verified payment and presents its evidence.
     */
    protected function verifiedBillingLocationSlotAuthority(
        int $requestingCustomerUserId,
        ?string $idempotencyKey = null,
        string $providerReference = 'test_provider_ref',
        string $reason = 'Test-support verified-billing allocation.',
    ): LocationSlotAllocationAuthority {
        return LocationSlotAllocationAuthority::fromVerifiedBilling(
            $requestingCustomerUserId,
            $idempotencyKey ?? uniqid('loc_slot_', true),
            $providerReference,
            $reason,
        );
    }

    protected function activeLocationCount(Business $business): int
    {
        return (int) DB::table('business_locations')
            ->where('business_id', $business->id)
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->count();
    }

    /**
     * Authenticates the Workspace OWNER, who holds the management authority
     * every mutating location action requires.
     */
    protected function authenticateAsOwner(Customer $customer): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer->user);
    }

    protected function authenticateAsUser(User $user): void
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($user);
    }

    /**
     * EloquentAccountRepository::hasPermission() short-circuits for user id
     * 1 ("first user is always super admin"), and MySQL does not reset
     * AUTO_INCREMENT under RefreshDatabase, so which test gets id 1 is
     * declaration-order dependent. Burning id 1 on a platform admin makes
     * every authorization assertion in this suite deterministic.
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
}
