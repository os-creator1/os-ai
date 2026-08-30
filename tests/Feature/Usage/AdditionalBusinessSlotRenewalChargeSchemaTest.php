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
 * M4 contract §18 (Correction Round 1 §B) —
 * additional_business_slot_renewal_charges exact schema shape:
 * local_idempotency_key/provider_session_or_intent_reference/
 * change_operation_id all unique; allocation_delta nullable, defaults
 * NULL (scheduled_renewal never sets it, mid_period_increase always
 * does).
 */
class AdditionalBusinessSlotRenewalChargeSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $agreementId;

    private int $currencyId;

    private int $catalogId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspaceId = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true])->id;
        $this->catalogId = (int) DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');
        $providerCustomerId = DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe', 'workspace_id' => $workspaceId, 'provider_customer_id' => 'cus_fixture_'.uniqid(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->agreementId = DB::table('additional_business_slot_agreements')->insertGetId([
            'workspace_id' => $workspaceId, 'current_allocation_count' => 2, 'target_allocation_count' => 2, 'paid_delta' => 2,
            'price_per_slot_micro_snapshot' => 5_000_000, 'total_amount_micro_snapshot' => 10_000_000,
            'currency_id_snapshot' => $this->currencyId, 'ratio_snapshot' => '0.5000', 'plan_catalog_id_snapshot' => $this->catalogId,
            'plan_tier_snapshot' => 'core', 'requesting_customer_user_id' => $owner->id,
            'requesting_customer_email_snapshot' => $owner->email, 'provider_customer_id' => $providerCustomerId,
            'payment_method_display_snapshot' => 'visa •••• 4242, exp 12/30', 'local_idempotency_key' => 'idem-'.uniqid(),
            'state' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function baseAttributes(array $overrides = []): array
    {
        return array_merge([
            'agreement_id' => $this->agreementId,
            'charge_kind' => 'scheduled_renewal',
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'amount_micro_snapshot' => 10_000_000,
            'requesting_customer_email_snapshot' => 'owner@example.test',
            'payer_type_snapshot' => 'workspace',
            'provider_customer_external_id_snapshot' => 'cus_fixture',
            'payment_method_display_snapshot' => 'visa •••• 4242, exp 12/30',
            'currency_id_snapshot' => $this->currencyId,
            'plan_catalog_id_snapshot' => $this->catalogId,
            'plan_tier_snapshot' => 'core',
            'ratio_snapshot' => '0.5000',
            'initiated_by' => 'scheduled_job',
            'local_idempotency_key' => 'idem-charge-'.uniqid(),
            'allocation_delta' => null,
            'state' => 'created',
            'created_at' => now(),
        ], $overrides);
    }

    public function test_local_idempotency_key_is_unique(): void
    {
        $key = 'idem-'.uniqid();
        DB::table('additional_business_slot_renewal_charges')->insert($this->baseAttributes(['local_idempotency_key' => $key]));

        $this->expectException(QueryException::class);
        DB::table('additional_business_slot_renewal_charges')->insert($this->baseAttributes(['local_idempotency_key' => $key]));
    }

    public function test_change_operation_id_is_unique_when_populated(): void
    {
        $opId = (string) \Illuminate\Support\Str::uuid();
        DB::table('additional_business_slot_renewal_charges')->insert($this->baseAttributes(['change_operation_id' => $opId, 'charge_kind' => 'mid_period_increase', 'allocation_delta' => 1]));

        $this->expectException(QueryException::class);
        DB::table('additional_business_slot_renewal_charges')->insert($this->baseAttributes(['change_operation_id' => $opId, 'charge_kind' => 'mid_period_increase', 'allocation_delta' => 1]));
    }

    public function test_allocation_delta_defaults_null_for_scheduled_renewal(): void
    {
        $id = DB::table('additional_business_slot_renewal_charges')->insertGetId($this->baseAttributes());

        $this->assertNull(DB::table('additional_business_slot_renewal_charges')->find($id)->allocation_delta);
    }

    public function test_allocation_delta_persists_a_positive_value_for_mid_period_increase(): void
    {
        $id = DB::table('additional_business_slot_renewal_charges')->insertGetId($this->baseAttributes([
            'charge_kind' => 'mid_period_increase',
            'change_operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'allocation_delta' => 2,
        ]));

        $this->assertSame(2, DB::table('additional_business_slot_renewal_charges')->find($id)->allocation_delta);
    }

    public function test_provider_session_or_intent_reference_is_unique_when_populated(): void
    {
        $ref = 'pi_'.uniqid();
        DB::table('additional_business_slot_renewal_charges')->insert($this->baseAttributes(['provider_session_or_intent_reference' => $ref]));

        $this->expectException(QueryException::class);
        DB::table('additional_business_slot_renewal_charges')->insert($this->baseAttributes(['provider_session_or_intent_reference' => $ref]));
    }

    public function test_agreement_id_restricts_deletion_while_referenced(): void
    {
        DB::table('additional_business_slot_renewal_charges')->insert($this->baseAttributes());

        $this->expectException(QueryException::class);
        DB::table('additional_business_slot_agreements')->where('id', $this->agreementId)->delete();
    }
}
