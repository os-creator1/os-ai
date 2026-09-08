<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — T-WALLET-6: the Business monthly
 * automatic top-up ceiling (RFC-005 monthly_recharge_cap_micro, enforced
 * by the existing evaluation job) and the Workspace aggregate monthly
 * ceiling (new, evaluated by UsageWalletManager::autoRechargeCeilingAdmission())
 * each stop further automatic top-ups at their exact integer boundary.
 *
 * Gated (§28.9 c/d): the platform hard maxima for either ceiling are NOT
 * invented here — any value the operator configures is accepted.
 */
class AutoRechargeCeilingsTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_the_business_monthly_ceiling_admits_the_exact_boundary_and_refuses_one_unit_over(): void
    {
        [$owner, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $owner->user_id);
        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '5000000', '10000000', (int) $owner->user_id);

        // 5,000,000 already added this month + 5,000,000 = exactly the 10,000,000 ceiling: admitted.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 1_000_000, 'recharged_this_period_micro' => 5_000_000]);
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame('6000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame('10000000', (string) $this->walletRow($business)->recharged_this_period_micro);

        // One more unit over the ceiling: refused, balance untouched, no attempt.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 1_000_000, 'recharged_this_period_micro' => 5_000_001]);
        $attemptsBefore = DB::table('business_funding_attempts')->where('business_id', $business->id)->count();
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);
        $this->assertSame('1000000', (string) $this->walletRow($business)->available_balance_micro);
        $this->assertSame($attemptsBefore, DB::table('business_funding_attempts')->where('business_id', $business->id)->count());

        $admission = app(UsageWalletManager::class)->autoRechargeCeilingAdmission($business, 5_000_000);
        $this->assertFalse($admission->allowed);
        $this->assertSame('business_recharge_cap', $admission->denialReason);
    }

    public function test_the_workspace_aggregate_ceiling_admits_the_exact_boundary_and_refuses_one_unit_over(): void
    {
        [$agency, $agencyBusiness, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [, $clientA] = $this->clientBusiness($workspace, 'Client A');
        [, $clientB] = $this->clientBusiness($workspace, 'Client B');
        [, $selfPaid] = $this->clientBusiness($workspace, 'Self Paid');
        $this->setPayer($clientA, PayerType::Workspace);
        $this->setPayer($clientB, PayerType::Workspace);
        $this->setPayer($selfPaid, PayerType::Business);

        app(UsageWalletManager::class)->setWorkspaceAggregateRechargeCap($workspace, '20000000', (int) $agency->user_id, 'Agency ceiling.');

        DB::table('business_usage_wallets')->where('business_id', $clientA->id)->update(['recharged_this_period_micro' => 10_000_000]);
        DB::table('business_usage_wallets')->where('business_id', $clientB->id)->update(['recharged_this_period_micro' => 5_000_000]);
        DB::table('business_usage_wallets')->where('business_id', $selfPaid->id)->update(['recharged_this_period_micro' => 50_000_000]);

        // 10,000,000 + 5,000,000 = 15,000,000 aggregate; +5,000,000 reaches exactly 20,000,000: admitted.
        $exact = app(UsageWalletManager::class)->autoRechargeCeilingAdmission($clientB, 5_000_000);
        $this->assertTrue($exact->allowed);
        $this->assertSame('5000000', $exact->remainingHeadroomMicro);

        // One unit over: refused with the Workspace reason.
        DB::table('business_usage_wallets')->where('business_id', $clientB->id)->update(['recharged_this_period_micro' => 5_000_001]);
        $over = app(UsageWalletManager::class)->autoRechargeCeilingAdmission($clientB, 5_000_000);
        $this->assertFalse($over->allowed);
        $this->assertSame(UsageWalletManager::DENIAL_WORKSPACE_RECHARGE_CAP, $over->denialReason);

        // A client-paid Business neither counts towards nor is limited by the Agency ceiling.
        $this->assertTrue(app(UsageWalletManager::class)->autoRechargeCeilingAdmission($selfPaid, 50_000_000)->allowed);
    }

    public function test_the_workspace_ceiling_is_stored_audited_and_owner_or_agency_admin_only(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        $client = $this->createCustomer();
        $business = $this->addBusiness($client, $workspace, 'Client');

        app(UsageWalletManager::class)->setWorkspaceAggregateRechargeCap($workspace, '30000000', (int) $agency->user_id, 'Set.');
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => 30_000_000]);
        $this->assertDatabaseHas('usage_control_transitions', ['scope' => 'workspace', 'scope_id' => $workspace->id, 'control' => UsageWalletManager::CONTROL_WORKSPACE_RECHARGE_CAP, 'from_value' => null, 'to_value' => '30000000', 'actor_user_id' => $agency->user_id]);

        try {
            app(UsageWalletManager::class)->setWorkspaceAggregateRechargeCap($workspace, '1000000', (int) $client->user_id, 'Denied.');
            $this->fail('A Business user must not set the Agency ceiling.');
        } catch (\App\Exceptions\Usage\UnauthorizedUsageBillingManagementException) {
            $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => 30_000_000]);
        }

        app(UsageWalletManager::class)->setWorkspaceAggregateRechargeCap($workspace, null, (int) $agency->user_id, 'Clear.');
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => null]);
    }
}
