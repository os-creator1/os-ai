<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — the spending-controls surface end to end
 * (contract §12.2 E-16/E-19/E-20, §15, §18 S-2/S-6): Business limit and
 * emergency stop by POST, Agency-wide controls for the Agency owner/admin
 * only, unauthorized direct POSTs refused server-side, and strict tenancy
 * (404 across Workspaces and Businesses). Also proves the additive schema
 * this slice adds.
 */
class WorkspaceUsageControlsTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_the_additive_schema_exists(): void
    {
        foreach (['paid_activity_paused_at', 'paid_activity_paused_by_user_id', 'auto_recharge_consented_at', 'auto_recharge_consented_by_user_id', 'spending_limit_alert_period_key'] as $column) {
            $this->assertTrue(Schema::hasColumn('business_usage_wallets', $column), $column);
        }

        $this->assertTrue(Schema::hasTable('workspace_usage_controls'));
        $this->assertTrue(Schema::hasTable('usage_control_transitions'));
        $this->assertTrue(Schema::hasColumns('workspace_usage_controls', ['workspace_id', 'monthly_aggregate_spend_cap_micro', 'monthly_aggregate_recharge_cap_micro', 'paid_activity_paused_at']));
    }

    public function test_the_owner_sets_the_monthly_limit_and_pauses_and_resumes_by_post(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_spend_cap', 'monthly_spend_cap' => '25.00'])
            ->assertRedirect($this->usageBillingUrl($workspace, $business))
            ->assertSessionHas('flash_success');
        $this->assertSame('25000000', (string) $this->walletRow($business)->monthly_spend_cap_micro);

        // Pausing needs the explicit confirmation.
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '1'])
            ->assertRedirect()
            ->assertSessionHas('flash_error');
        $this->assertNull($this->walletRow($business)->paid_activity_paused_at);

        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '1', 'confirm_pause' => '1'])
            ->assertRedirect()
            ->assertSessionHas('flash_success');
        $this->assertNotNull($this->walletRow($business)->paid_activity_paused_at);
        $this->assertSame((int) $owner->user_id, (int) $this->walletRow($business)->paid_activity_paused_by_user_id);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Paid activity paused', $html);
        $this->assertStringContainsString('Resume paid activity', $html);
        $this->assertStringNotContainsString('name="confirm_pause"', $html);

        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '0'])
            ->assertSessionHas('flash_success');
        $this->assertNull($this->walletRow($business)->paid_activity_paused_at);
        $this->assertSame(2, DB::table('usage_control_transitions')->where('scope', 'business')->where('scope_id', $business->id)->count());
    }

    public function test_staff_and_strangers_cannot_pause_or_set_limits(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff);
        $this->authenticateAs($staff);

        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '1', 'confirm_pause' => '1'])
            ->assertRedirect()
            ->assertSessionHas('flash_error');
        $this->assertNull($this->walletRow($business)->paid_activity_paused_at);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="pause-form"', $html, 'Staff can read but never sees the controls.');

        $stranger = $this->createCustomer();
        $this->authenticateAs($stranger);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '1', 'confirm_pause' => '1'])->assertNotFound();
        $this->get($this->usageBillingUrl($workspace, $business))->assertNotFound();
    }

    public function test_agency_wide_controls_are_saved_by_the_agency_owner_and_refused_to_a_business_user(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [$client, $business] = $this->clientBusiness($workspace);
        $this->setPayer($business, PayerType::Workspace);
        $this->assign($this->member($workspace, $client->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $business);

        $this->authenticateAs($agency);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), [
            'control' => 'workspace_controls',
            'workspace_monthly_spend_cap' => '500.00',
            'workspace_monthly_recharge_cap' => '200.00',
            'workspace_paused' => '1',
        ])->assertRedirect($this->usageBillingUrl($workspace, $business))->assertSessionHas('flash_success');

        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_spend_cap_micro' => 500_000_000, 'monthly_aggregate_recharge_cap_micro' => 200_000_000]);
        $this->assertNotNull(DB::table('workspace_usage_controls')->where('workspace_id', $workspace->id)->value('paid_activity_paused_at'));

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="agency-controls-form"', $html);
        $this->assertStringContainsString('Paid activity is paused for every client account by your agency.', $html);

        // The client never sees the Agency controls and cannot post them.
        $this->authenticateAs($client);
        $clientHtml = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="agency-controls-form"', $clientHtml);
        $this->assertStringNotContainsString('500.00', $clientHtml, 'The Agency limit is not disclosed to the client.');

        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), [
            'control' => 'workspace_controls',
            'workspace_monthly_spend_cap' => '1.00',
            'workspace_paused' => '0',
        ])->assertRedirect()->assertSessionHas('flash_error');

        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_spend_cap_micro' => 500_000_000]);
        $this->assertNotNull(DB::table('workspace_usage_controls')->where('workspace_id', $workspace->id)->value('paid_activity_paused_at'), 'A Business user cannot lift the Agency stop.');
    }

    public function test_an_agency_wide_admin_may_manage_the_workspace_controls_but_a_scoped_admin_may_not(): void
    {
        [, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        $agencyAdmin = $this->createCustomer();
        $this->member($workspace, $agencyAdmin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $scopedAdmin = $this->createCustomer();
        [, $business] = $this->clientBusiness($workspace);
        $this->assign($this->member($workspace, $scopedAdmin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected), $business);

        app(UsageWalletManager::class)->setWorkspaceAggregateSpendCap($workspace, '10000000', (int) $agencyAdmin->user_id, 'Agency admin.');
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_spend_cap_micro' => 10_000_000]);

        $this->expectException(\App\Exceptions\Usage\UnauthorizedUsageBillingManagementException::class);
        app(UsageWalletManager::class)->setWorkspaceAggregateSpendCap($workspace, '1', (int) $scopedAdmin->user_id, 'Denied.');
    }

    public function test_one_workspace_can_never_read_or_change_another_workspaces_balances_caps_or_payer(): void
    {
        [$ownerA, $businessA, $workspaceA] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'A One', 'Agency A');
        [$ownerB, $businessB, $workspaceB] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'B One', 'Agency B');
        $this->fund($businessB, 42_420_000);
        app(UsageWalletManager::class)->setWorkspaceAggregateSpendCap($workspaceB, '77000000', (int) $ownerB->user_id, 'B.');

        $this->authenticateAs($ownerA);

        $this->get($this->usageBillingUrl($workspaceB, $businessB))->assertNotFound();
        $this->get($this->usageBillingUrl($workspaceA, $businessB))->assertNotFound();
        $this->post($this->usageBillingRoute('spend-cap', $workspaceB, $businessB), ['control' => 'workspace_controls', 'workspace_monthly_spend_cap' => '1.00'])->assertNotFound();
        $this->post($this->usageBillingRoute('payer', $workspaceB, $businessB), ['payer_type' => 'business'])->assertNotFound();
        $this->post($this->usageBillingRoute('spend-cap', $workspaceA, $businessB), ['control' => 'business_pause', 'paused' => '1', 'confirm_pause' => '1'])->assertNotFound();

        $ownHtml = $this->get($this->usageBillingUrl($workspaceA, $businessA))->assertOk()->getContent();
        $this->assertStringNotContainsString('42.42', $ownHtml);
        $this->assertStringNotContainsString('77.00', $ownHtml);
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspaceB->id, 'monthly_aggregate_spend_cap_micro' => 77_000_000]);
        $this->assertNull($this->walletRow($businessB)->paid_activity_paused_at);
    }

    public function test_the_threshold_alert_command_announces_once_per_period(): void
    {
        [$owner, $business] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->billingContact($business, (int) $owner->user_id);
        app(UsageWalletManager::class)->setSpendCap($business, '10000000', (int) $owner->user_id, 'Limit.');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['committed_spend_this_period_micro' => 8_000_000]);
        \Illuminate\Support\Facades\Notification::fake();

        $this->artisan('usage:spending-threshold-alerts')->expectsOutput('Spending threshold alerts sent: 1.')->assertExitCode(0);
        $this->artisan('usage:spending-threshold-alerts')->expectsOutput('Spending threshold alerts sent: 0.')->assertExitCode(0);

        \Illuminate\Support\Facades\Notification::assertSentTimes(\App\Notifications\Usage\SpendingLimitReachedNotification::class, 1);
    }
}
