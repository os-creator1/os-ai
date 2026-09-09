<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.E — initializePayerAssignmentForBusiness()
 * idempotency and changePayer() explicit reassignment, as corrected by
 * Customer Experience Slice 5 (contract §12.4, E-12): a real change is
 * the Agency owner's/admin's on the Agency tier and is audited; a
 * reaffirmation of the current payer writes nothing at all.
 */
class BillingProfileManagerPayerAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_initialize_is_idempotent(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);
        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $this->assertSame(1, DB::table('business_payer_assignments')->where('business_id', $business->id)->count());
    }

    public function test_change_payer_records_a_transition_and_updates_the_assignment(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        $ownerId = (int) $business->workspace->owner_user_id;

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        // The Workspace already pays: the owner's submission is a
        // reaffirmation — a true no-op that records no transition.
        $updated = app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerId, 'Reaffirmed.');
        $this->assertSame(PayerType::Workspace, $updated->payer_type);
        $this->assertSame(0, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());

        // A real change is Agency-managed: on the Agency tier the owner
        // moves billing to the client, audited with actor and reason.
        $this->assignAgencyTier($business);
        $outcome = app(BillingProfileManager::class)->assignPayer($business, PayerType::Business, $ownerId, 'Client pays.');

        $this->assertTrue($outcome['changed']);
        $this->assertSame(PayerType::Business, $outcome['assignment']->payer_type);
        $this->assertSame(1, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());

        $transition = DB::table('business_payer_transitions')->where('business_id', $business->id)->first();
        $this->assertSame($ownerId, (int) $transition->actor_user_id);
        $this->assertSame('Client pays.', $transition->reason);
        $this->assertSame('workspace', $transition->from_payer_type);
        $this->assertSame('business', $transition->to_payer_type);
    }

    public function test_repeated_reaffirmation_with_the_same_payer_type_writes_nothing(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        $ownerId = (int) $business->workspace->owner_user_id;

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);
        $before = DB::table('business_payer_assignments')->where('business_id', $business->id)->first();

        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerId, 'First.');
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerId, 'Second.');

        $this->assertSame(0, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
        $this->assertEquals((array) $before, (array) DB::table('business_payer_assignments')->where('business_id', $business->id)->first(), 'updated_at included.');
    }

    public function test_change_payer_self_heals_a_missing_assignment(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        $ownerId = (int) $business->workspace->owner_user_id;

        // Deliberately never call initializePayerAssignmentForBusiness()
        // first — a genuine explicit reassignment request must not fail
        // merely because lazy initialization has not yet run. On the
        // Agency tier the lazily-created default is 'business', so the
        // owner's request for 'workspace' is a real, audited change.
        $this->assignAgencyTier($business);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerId, 'Self-heal.');

        $this->assertSame(1, DB::table('business_payer_assignments')->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);
        $this->assertSame(1, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
    }

    private function assignAgencyTier(Business $business): void
    {
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'fixture' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        app(EntitlementManager::class)->assignFirstPlan($business->workspace, WorkspacePlanTier::Agency, $admin->id, 'Fixture.', true, 0);
        $business->workspace->refresh();
    }
}
