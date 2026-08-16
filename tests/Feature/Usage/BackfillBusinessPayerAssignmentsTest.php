<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 §32, M2 contract §11 migration 8 — the one-time backfill
 * migration creates a business_payer_assignments row for every existing
 * Business lacking one; idempotent rerun; never overwrites an explicit
 * existing assignment; continues past a Business with no resolvable
 * plan-tier defaulting to 'workspace'.
 */
class BackfillBusinessPayerAssignmentsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function runBackfillMigration(): void
    {
        $migration = require database_path('migrations/2026_08_16_130008_backfill_business_payer_assignments.php');
        $migration->up();
    }

    public function test_backfill_creates_an_assignment_for_a_business_missing_one(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        // createBusinessWithWorkspace() uses BusinessRepository directly,
        // never dispatching BusinessCreated/BusinessAssignedToWorkspace —
        // genuinely no payer assignment exists yet for this Business,
        // faithfully simulating one created before M2 deployed.
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->assertDatabaseMissing('business_payer_assignments', ['business_id' => $business->id]);

        $this->runBackfillMigration();

        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id]);
    }

    public function test_backfill_defaults_agency_tier_to_business_pays(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(EntitlementManager::class)->assignFirstPlan($business->workspace, WorkspacePlanTier::Agency, (int) $admin->id, 'Fixture.', true, 0);

        $this->runBackfillMigration();

        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'business']);
    }

    public function test_rerun_is_idempotent_and_never_overwrites_an_explicit_existing_assignment(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        // An authorized actor explicitly assigned this Business's payer
        // before the backfill ever runs.
        app(\App\Library\Usage\BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);
        DB::table('business_payer_assignments')->where('business_id', $business->id)->update(['payer_type' => 'business']);

        $this->runBackfillMigration();
        $this->runBackfillMigration();

        $this->assertSame(1, DB::table('business_payer_assignments')->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'business']);
    }

    public function test_full_rerun_makes_zero_new_writes_when_every_business_is_already_assigned(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(\App\Library\Usage\BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $countBefore = DB::table('business_payer_assignments')->count();

        $this->runBackfillMigration();

        $this->assertSame($countBefore, DB::table('business_payer_assignments')->count());
    }
}
