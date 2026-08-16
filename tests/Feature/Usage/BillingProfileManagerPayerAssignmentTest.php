<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.E/§13 — initializePayerAssignmentForBusiness()
 * idempotency and changePayer() explicit reassignment/reaffirmation.
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

        $updated = app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerId, 'Reaffirmed.');

        $this->assertSame(PayerType::Workspace, $updated->payer_type);
        $this->assertSame(1, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());

        $transition = DB::table('business_payer_transitions')->where('business_id', $business->id)->first();
        $this->assertSame($ownerId, (int) $transition->actor_user_id);
        $this->assertSame('Reaffirmed.', $transition->reason);
    }

    public function test_repeated_reaffirmation_with_the_same_payer_type_still_records_a_transition(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        $ownerId = (int) $business->workspace->owner_user_id;

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerId, 'First.');
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerId, 'Second.');

        $this->assertSame(2, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
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
        // merely because lazy initialization has not yet run.
        app(BillingProfileManager::class)->changePayer($business, PayerType::Business, (int) $business->customer_id, 'Self-heal.');

        $this->assertSame(1, DB::table('business_payer_assignments')->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'business']);
    }
}
