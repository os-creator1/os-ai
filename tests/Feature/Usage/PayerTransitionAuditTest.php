<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\PayerType;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.E — a payer change is append-only audited and
 * never rewrites historical ledger entries or the billing contact
 * already recorded (M2 contract §6.F).
 */
class PayerTransitionAuditTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_payer_change_never_touches_business_usage_ledger_entries(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');

        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);

        $ledgerCountBefore = DB::table('business_usage_ledger_entries')->count();

        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, (int) $business->workspace->owner_user_id, 'No ledger effect.');

        $this->assertSame($ledgerCountBefore, DB::table('business_usage_ledger_entries')->count());
    }

    public function test_payer_change_never_rewrites_the_billing_contact(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');

        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Jane Doe', 'jane@example.test', true, (int) $business->customer_id);
        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, (int) $business->workspace->owner_user_id, 'Payer change.');

        $this->assertDatabaseHas('business_billing_contacts', ['business_id' => $business->id, 'contact_name' => 'Jane Doe']);
    }

    public function test_every_payer_change_is_append_only_never_updates_or_deletes_a_prior_transition(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        $ownerId = (int) $business->workspace->owner_user_id;
        $directOwnerId = (int) $business->customer_id;

        app(BillingProfileManager::class)->initializePayerAssignmentForBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Business, $directOwnerId, 'First.');
        $firstTransitionId = DB::table('business_payer_transitions')->where('business_id', $business->id)->orderBy('id')->value('id');

        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerId, 'Second.');

        $this->assertDatabaseHas('business_payer_transitions', ['id' => $firstTransitionId, 'reason' => 'First.']);
        $this->assertSame(2, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
    }
}
