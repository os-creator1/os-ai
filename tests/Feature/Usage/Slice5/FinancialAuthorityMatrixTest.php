<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Usage\BusinessPayerChanged;
use App\Library\Usage\BillingProfileManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — Correction Round 1 §9 and §13.8 (tests
 * 58–64): financial controls belong to the payer side. An agency-paid
 * client sees "Billing managed by your agency", no financial mutation
 * form, and every crafted mutation is refused server-side with the
 * database, events, notifications, attempts and provider untouched. The
 * client payer of a client-paid Business keeps its own controls; the
 * Agency manager keeps the agency-paid controls; billing-contact
 * authority is unchanged.
 */
class FinancialAuthorityMatrixTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    /** @return array{0: Customer, 1: Business, 2: Workspace, 3: Customer} agency, agency-paid client business, workspace, client */
    private function agencyPaidClient(): array
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [$client, $business] = $this->clientBusiness($workspace, 'Client Bakery');
        $this->setPayer($business, PayerType::Workspace);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $agency->user_id);
        $this->activateFixtureRate('crm', '1000000');

        return [$agency, $business, $workspace, $client];
    }

    private function snapshot(Business $business, Workspace $workspace): array
    {
        return [
            'wallet' => (array) $this->walletRow($business),
            'payer' => (array) DB::table('business_payer_assignments')->where('business_id', $business->id)->first(),
            'controls' => DB::table('workspace_usage_controls')->where('workspace_id', $workspace->id)->count(),
            'transitions' => DB::table('usage_control_transitions')->count(),
            'payer_transitions' => DB::table('business_payer_transitions')->count(),
            'limits' => DB::table('business_feature_usage_limits')->where('business_id', $business->id)->count(),
            'attempts' => DB::table('business_funding_attempts')->count(),
            'ledger' => DB::table('business_usage_ledger_entries')->count(),
        ];
    }

    /** @return array<string, array{0: string, 1: array}> */
    private function financialMutations(Workspace $workspace, Business $business): array
    {
        return [
            'add funds' => [$this->usageBillingRoute('top-up.initiate', $workspace, $business), ['amount' => '5.00']],
            'automatic top-up' => [$this->usageBillingRoute('auto-recharge.configure', $workspace, $business), ['auto_recharge_enabled' => '1', 'auto_recharge_amount_micro' => '5000000', 'auto_recharge_threshold' => '2.00', 'monthly_recharge_cap' => '100.00']],
            'spending limit' => [$this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_spend_cap', 'monthly_spend_cap' => '10.00']],
            'capability limit' => [$this->usageBillingRoute('feature-limit', $workspace, $business, ['crm']), ['monthly_limit' => '5.00']],
            'pause' => [$this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '1', 'confirm_pause' => '1']],
            'billing responsibility' => [$this->usageBillingRoute('payer', $workspace, $business), ['billing_responsibility' => 'client', 'return_to' => 'account']],
            'agency-wide controls' => [$this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'workspace_controls', 'workspace_monthly_recharge_cap' => '100.00', 'workspace_monthly_spend_cap' => '100.00', 'workspace_paused' => '1']],
        ];
    }

    public function test_an_agency_paid_client_reads_billing_managed_by_your_agency_and_sees_no_financial_form(): void
    {
        [, $business, $workspace, $client] = $this->agencyPaidClient();
        $this->authenticateAs($client);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Billing managed by your agency', $html);
        foreach (['usage-billing/top-up', 'usage-billing/auto-recharge', 'name="control"', 'name="capability"', 'data-role="pause-form"', 'data-role="agency-controls-form"', 'name="billing_responsibility"', 'name="payer_type"', 'name="monthly_spend_cap"', 'name="auto_recharge_amount_micro"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "An agency-paid client must never see {$forbidden}.");
        }
        $this->assertStringNotContainsString('Agency-wide controls', $html);
        $this->assertStringNotContainsString('Add funds</button>', $html);
    }

    public function test_crafted_posts_from_an_agency_paid_client_change_nothing_and_call_no_provider(): void
    {
        [, $business, $workspace, $client] = $this->agencyPaidClient();
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined']; // any provider call would leave a failed attempt behind
        $this->authenticateAs($client);
        Event::fake([BusinessPayerChanged::class]);
        Notification::fake();
        $before = $this->snapshot($business, $workspace);

        foreach ($this->financialMutations($workspace, $business) as $label => [$url, $payload]) {
            $this->from($this->usageBillingUrl($workspace, $business))
                ->post($url, $payload)
                ->assertRedirect()
                ->assertSessionDoesntHaveErrors()
                ->assertSessionHas('flash_error')
                ->assertSessionMissing('flash_success');

            $this->assertSame($before, $this->snapshot($business, $workspace), "The {$label} mutation must leave every row untouched.");
        }

        Event::assertNotDispatched(BusinessPayerChanged::class);
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('business_funding_attempts')->count(), 'No funding attempt, so no provider call, was ever made.');
        $this->assertSame(0, (int) $this->walletRow($business)->auto_recharge_enabled);
        $this->assertNull($this->walletRow($business)->paid_activity_paused_at);
    }

    public function test_the_authority_matrix_is_the_payer_side_only(): void
    {
        [$agency, $business, $workspace, $client] = $this->agencyPaidClient();
        $manager = app(BillingProfileManager::class);
        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $scoped = $this->createCustomer();
        $this->assign($this->member($workspace, $scoped->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected), $business);
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $stranger = $this->createCustomer();

        // Agency pays: the Agency owner and the Agency-wide Admin, nobody else.
        $this->assertTrue($manager->actorManagesPayerControls($business, (int) $agency->user_id));
        $this->assertTrue($manager->actorManagesPayerControls($business, (int) $admin->user_id));
        $this->assertFalse($manager->actorManagesPayerControls($business, (int) $client->user_id));
        $this->assertFalse($manager->actorManagesPayerControls($business, (int) $scoped->user_id));
        $this->assertFalse($manager->actorManagesPayerControls($business, (int) $staff->user_id));
        $this->assertFalse($manager->actorManagesPayerControls($business, (int) $stranger->user_id));
        // Generic billing-management authority is a different thing (the client keeps it).
        $this->assertTrue($manager->billingResponsibilityFor($business, (int) $client->user_id)['actor_manages_billing_contact']);
        $this->assertFalse($manager->billingResponsibilityFor($business, (int) $client->user_id)['actor_manages_limits']);

        // Client pays: the client only.
        $this->setPayer($business, PayerType::Business);
        $this->assertTrue($manager->actorManagesPayerControls($business, (int) $client->user_id));
        $this->assertFalse($manager->actorManagesPayerControls($business, (int) $agency->user_id));
        $this->assertFalse($manager->actorManagesPayerControls($business, (int) $admin->user_id));
        $this->assertFalse($manager->actorManagesPayerControls($business, (int) $scoped->user_id));
        $this->assertFalse($manager->actorManagesPayerControls($business, (int) $staff->user_id));
        $this->assertTrue($manager->billingResponsibilityFor($business, (int) $agency->user_id)['actor_manages_responsibility']);
        $this->assertFalse($manager->billingResponsibilityFor($business, (int) $agency->user_id)['actor_manages_limits']);
    }

    public function test_a_client_paid_business_owner_retains_the_payer_owned_controls(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [$client, $business] = $this->clientBusiness($workspace, 'Client Bakery');
        $this->setPayer($business, PayerType::Business);
        $this->fakeProvider();
        $this->attachFakeCard($business, (int) $client->user_id);
        $this->activateFixtureRate('crm', '1000000');
        $this->authenticateAs($client);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('usage-billing/auto-recharge', $html);
        $this->assertStringContainsString('name="monthly_spend_cap"', $html);
        $this->assertStringContainsString('data-role="pause-form"', $html);
        $this->assertStringNotContainsString('name="billing_responsibility"', $html);

        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_spend_cap', 'monthly_spend_cap' => '10.00'])->assertSessionHas('flash_success');
        $this->assertSame('10000000', (string) $this->walletRow($business)->monthly_spend_cap_micro);
        $this->post($this->usageBillingRoute('feature-limit', $workspace, $business, ['crm']), ['monthly_limit' => '5.00'])->assertSessionHas('flash_success');
        $this->assertDatabaseHas('business_feature_usage_limits', ['business_id' => $business->id, 'feature_key' => 'crm', 'monthly_limit_micro' => 5_000_000]);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '1', 'confirm_pause' => '1'])->assertSessionHas('flash_success');
        $this->assertNotNull($this->walletRow($business)->paid_activity_paused_at);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '0'])->assertSessionHas('flash_success');
        $this->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), ['auto_recharge_enabled' => '1', 'auto_recharge_amount_micro' => '10000000', 'auto_recharge_threshold' => '2.00', 'monthly_recharge_cap' => '50.00'])->assertSessionHas('flash_success');
        $this->assertSame(1, (int) $this->walletRow($business)->auto_recharge_enabled);
        $this->assertSame((int) $client->user_id, (int) $this->walletRow($business)->auto_recharge_consented_by_user_id);

        // The Agency owner changes responsibility and views the summary, but does not alter the client payer's controls.
        $this->authenticateAs($agency);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_spend_cap', 'monthly_spend_cap' => '99.00'])->assertSessionHas('flash_error');
        $this->assertSame('10000000', (string) $this->walletRow($business)->monthly_spend_cap_micro);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '1', 'confirm_pause' => '1'])->assertSessionHas('flash_error');
        $this->assertNull($this->walletRow($business)->paid_activity_paused_at);
        $this->assertStringNotContainsString('name="monthly_spend_cap"', $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent());
    }

    public function test_the_agency_manager_retains_the_agency_paid_controls(): void
    {
        [$agency, $business, $workspace] = $this->agencyPaidClient();
        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);

        $this->authenticateAs($agency);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_spend_cap', 'monthly_spend_cap' => '20.00'])->assertSessionHas('flash_success');
        $this->assertSame('20000000', (string) $this->walletRow($business)->monthly_spend_cap_micro);
        $this->post($this->usageBillingRoute('feature-limit', $workspace, $business, ['crm']), ['monthly_limit' => '5.00'])->assertSessionHas('flash_success');
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '1', 'confirm_pause' => '1'])->assertSessionHas('flash_success');
        $this->assertNotNull($this->walletRow($business)->paid_activity_paused_at);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_pause', 'paused' => '0'])->assertSessionHas('flash_success');
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'workspace_controls', 'workspace_monthly_recharge_cap' => '100.00', 'workspace_monthly_spend_cap' => '250.00'])->assertSessionHas('flash_success');
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => 100_000_000]);
        $this->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), ['auto_recharge_enabled' => '1', 'auto_recharge_amount_micro' => '5000000', 'auto_recharge_threshold' => '2.00', 'monthly_recharge_cap' => '50.00'])->assertSessionHas('flash_success');
        $this->assertSame((int) $agency->user_id, (int) $this->walletRow($business)->auto_recharge_consented_by_user_id);

        // The Agency-wide Admin manages the limits, ceilings and pauses; charge-causing consent (RFC-005 §16) stays with the payer of record.
        $this->authenticateAs($admin);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'business_spend_cap', 'monthly_spend_cap' => '30.00'])->assertSessionHas('flash_success');
        $this->assertSame('30000000', (string) $this->walletRow($business)->monthly_spend_cap_micro);
        $this->post($this->usageBillingRoute('spend-cap', $workspace, $business), ['control' => 'workspace_controls', 'workspace_monthly_recharge_cap' => '200.00', 'workspace_monthly_spend_cap' => '250.00'])->assertSessionHas('flash_success');
        $this->assertDatabaseHas('workspace_usage_controls', ['workspace_id' => $workspace->id, 'monthly_aggregate_recharge_cap_micro' => 200_000_000]);
        $this->post($this->usageBillingRoute('auto-recharge.configure', $workspace, $business), ['auto_recharge_enabled' => '0'])->assertSessionHas('flash_error');
        $this->assertSame(1, (int) $this->walletRow($business)->auto_recharge_enabled, 'Only the payer of record can switch automatic top-up.');
    }

    public function test_billing_contact_authority_is_unchanged(): void
    {
        [, $business, $workspace, $client] = $this->agencyPaidClient();
        $this->authenticateAs($client);

        $this->post($this->usageBillingRoute('billing-contact', $workspace, $business), ['contact_name' => 'Client Contact', 'contact_email' => 'client@example.test', 'notification_opt_in' => '1'])
            ->assertSessionHas('flash_success');
        $this->assertDatabaseHas('business_billing_contacts', ['business_id' => $business->id, 'contact_email' => 'client@example.test']);
    }
}
