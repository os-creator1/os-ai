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
 * M4 contract §18/§23 — additional_business_slot_renewal_charge_transitions
 * exact schema shape: renewal_charge_id restrictOnDelete, append-only
 * history — the sole durable source the attempt-ordinal algorithm reads
 * (count of to_state = 'failed' rows).
 */
class AdditionalBusinessSlotRenewalChargeTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function createRenewalCharge(): int
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
        $agreementId = DB::table('additional_business_slot_agreements')->insertGetId([
            'workspace_id' => $workspaceId, 'current_allocation_count' => 2, 'target_allocation_count' => 2, 'paid_delta' => 2,
            'price_per_slot_micro_snapshot' => 5_000_000, 'total_amount_micro_snapshot' => 10_000_000,
            'currency_id_snapshot' => $currencyId, 'ratio_snapshot' => '0.5000', 'plan_catalog_id_snapshot' => $catalogId,
            'plan_tier_snapshot' => 'core', 'requesting_customer_user_id' => $owner->id,
            'requesting_customer_email_snapshot' => $owner->email, 'provider_customer_id' => $providerCustomerId,
            'payment_method_display_snapshot' => 'visa •••• 4242, exp 12/30', 'local_idempotency_key' => 'idem-'.uniqid(),
            'state' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('additional_business_slot_renewal_charges')->insertGetId([
            'agreement_id' => $agreementId, 'charge_kind' => 'scheduled_renewal', 'period_start' => now(), 'period_end' => now()->addMonth(),
            'amount_micro_snapshot' => 10_000_000, 'requesting_customer_email_snapshot' => $owner->email, 'payer_type_snapshot' => 'workspace',
            'provider_customer_external_id_snapshot' => 'cus_fixture', 'payment_method_display_snapshot' => 'visa •••• 4242, exp 12/30',
            'currency_id_snapshot' => $currencyId, 'plan_catalog_id_snapshot' => $catalogId, 'plan_tier_snapshot' => 'core',
            'ratio_snapshot' => '0.5000', 'initiated_by' => 'scheduled_job', 'local_idempotency_key' => 'idem-charge-'.uniqid(),
            'allocation_delta' => null, 'state' => 'created', 'created_at' => now(),
        ]);
    }

    public function test_renewal_charge_id_restricts_deletion_while_referenced(): void
    {
        $chargeId = $this->createRenewalCharge();

        DB::table('additional_business_slot_renewal_charge_transitions')->insert([
            'renewal_charge_id' => $chargeId, 'from_state' => 'created', 'to_state' => 'provider_pending',
            'source' => 'sync_response', 'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('additional_business_slot_renewal_charges')->where('id', $chargeId)->delete();
    }

    public function test_transitions_are_append_only_and_countable_by_to_state(): void
    {
        $chargeId = $this->createRenewalCharge();

        DB::table('additional_business_slot_renewal_charge_transitions')->insert([
            'renewal_charge_id' => $chargeId, 'from_state' => 'created', 'to_state' => 'provider_pending',
            'source' => 'sync_response', 'created_at' => now(),
        ]);
        DB::table('additional_business_slot_renewal_charge_transitions')->insert([
            'renewal_charge_id' => $chargeId, 'from_state' => 'provider_pending', 'to_state' => 'failed',
            'source' => 'sync_response', 'created_at' => now(),
        ]);

        $this->assertSame(2, DB::table('additional_business_slot_renewal_charge_transitions')->where('renewal_charge_id', $chargeId)->count());
        $this->assertSame(1, DB::table('additional_business_slot_renewal_charge_transitions')->where('renewal_charge_id', $chargeId)->where('to_state', 'failed')->count());
    }
}
