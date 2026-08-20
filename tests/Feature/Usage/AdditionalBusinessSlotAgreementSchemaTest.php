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
 * M4 contract §18 — additional_business_slot_agreements exact schema
 * shape: local_idempotency_key and provider_session_or_intent_reference
 * both unique; workspace_id/currency_id_snapshot/plan_catalog_id_snapshot/
 * provider_customer_id all restrictOnDelete.
 */
class AdditionalBusinessSlotAgreementSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $workspaceId;

    private int $currencyId;

    private int $catalogId;

    private int $providerCustomerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;

        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $this->workspaceId = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true])->id;

        $this->catalogId = (int) DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');

        $this->providerCustomerId = DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe', 'workspace_id' => $this->workspaceId, 'provider_customer_id' => 'cus_fixture_'.uniqid(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function baseAttributes(array $overrides = []): array
    {
        return array_merge([
            'workspace_id' => $this->workspaceId,
            'current_allocation_count' => 2,
            'target_allocation_count' => 3,
            'paid_delta' => 1,
            'price_per_slot_micro_snapshot' => 5_000_000,
            'total_amount_micro_snapshot' => 5_000_000,
            'currency_id_snapshot' => $this->currencyId,
            'ratio_snapshot' => '0.5000',
            'plan_catalog_id_snapshot' => $this->catalogId,
            'plan_tier_snapshot' => 'core',
            'requesting_customer_user_id' => 1,
            'requesting_customer_email_snapshot' => 'owner@example.test',
            'provider_customer_id' => $this->providerCustomerId,
            'payment_method_display_snapshot' => 'Pending Checkout',
            'local_idempotency_key' => 'idem-'.uniqid(),
            'state' => 'quote_created',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    public function test_local_idempotency_key_is_unique(): void
    {
        $key = 'idem-'.uniqid();
        DB::table('additional_business_slot_agreements')->insert($this->baseAttributes(['local_idempotency_key' => $key]));

        $this->expectException(QueryException::class);
        DB::table('additional_business_slot_agreements')->insert($this->baseAttributes(['local_idempotency_key' => $key]));
    }

    public function test_provider_session_or_intent_reference_is_unique_when_populated(): void
    {
        $ref = 'cs_fixture_'.uniqid();
        DB::table('additional_business_slot_agreements')->insert($this->baseAttributes(['provider_session_or_intent_reference' => $ref]));

        $this->expectException(QueryException::class);
        DB::table('additional_business_slot_agreements')->insert($this->baseAttributes(['provider_session_or_intent_reference' => $ref]));
    }

    public function test_workspace_id_restricts_deletion_while_referenced(): void
    {
        DB::table('additional_business_slot_agreements')->insert($this->baseAttributes());

        $this->expectException(QueryException::class);
        DB::table('workspaces')->where('id', $this->workspaceId)->delete();
    }

    public function test_provider_session_or_intent_reference_is_nullable(): void
    {
        $id = DB::table('additional_business_slot_agreements')->insertGetId($this->baseAttributes());

        $row = DB::table('additional_business_slot_agreements')->find($id);
        $this->assertNull($row->provider_session_or_intent_reference);
    }
}
