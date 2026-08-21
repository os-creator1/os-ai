<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 contract §18 — additional_business_slot_agreement_transitions exact
 * schema shape: agreement_id restrictOnDelete, provider_event_id a plain
 * nullable FK, append-only history.
 */
class AdditionalBusinessSlotAgreementTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function createAgreement(): int
    {
        $currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspaceId = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true])->id;
        $catalogId = (int) DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');
        $providerCustomerId = DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe', 'workspace_id' => $workspaceId, 'provider_customer_id' => 'cus_fixture_'.uniqid(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('additional_business_slot_agreements')->insertGetId([
            'workspace_id' => $workspaceId, 'current_allocation_count' => 2, 'target_allocation_count' => 3, 'paid_delta' => 1,
            'price_per_slot_micro_snapshot' => 5_000_000, 'total_amount_micro_snapshot' => 5_000_000,
            'currency_id_snapshot' => $currencyId, 'ratio_snapshot' => '0.5000', 'plan_catalog_id_snapshot' => $catalogId,
            'plan_tier_snapshot' => 'core', 'requesting_customer_user_id' => $owner->id,
            'requesting_customer_email_snapshot' => $owner->email, 'provider_customer_id' => $providerCustomerId,
            'payment_method_display_snapshot' => 'Pending Checkout', 'local_idempotency_key' => 'idem-'.uniqid(),
            'state' => 'quote_created', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_agreement_id_restricts_deletion_while_referenced(): void
    {
        $agreementId = $this->createAgreement();

        DB::table('additional_business_slot_agreement_transitions')->insert([
            'agreement_id' => $agreementId, 'from_state' => 'quote_created', 'to_state' => 'quote_created',
            'source' => 'sync_response', 'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('additional_business_slot_agreements')->where('id', $agreementId)->delete();
    }

    public function test_provider_event_id_is_nullable(): void
    {
        $agreementId = $this->createAgreement();

        $id = DB::table('additional_business_slot_agreement_transitions')->insertGetId([
            'agreement_id' => $agreementId, 'from_state' => 'quote_created', 'to_state' => 'checkout_pending',
            'source' => 'sync_response', 'provider_event_id' => null, 'created_at' => now(),
        ]);

        $this->assertNull(DB::table('additional_business_slot_agreement_transitions')->find($id)->provider_event_id);
    }

    public function test_transitions_are_append_only_history(): void
    {
        $agreementId = $this->createAgreement();

        DB::table('additional_business_slot_agreement_transitions')->insert([
            'agreement_id' => $agreementId, 'from_state' => 'quote_created', 'to_state' => 'checkout_pending',
            'source' => 'sync_response', 'created_at' => now(),
        ]);
        DB::table('additional_business_slot_agreement_transitions')->insert([
            'agreement_id' => $agreementId, 'from_state' => 'checkout_pending', 'to_state' => 'payment_succeeded',
            'source' => 'webhook_event', 'created_at' => now(),
        ]);

        $this->assertSame(2, DB::table('additional_business_slot_agreement_transitions')->where('agreement_id', $agreementId)->count());
    }
}
