<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Http\Controllers\Customer\Business\UsageBillingController;
use App\Library\Usage\UsageBillingPresenter;
use App\Library\Workspace\AgencyClientRelationshipManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use ReflectionMethod;
use Tests\Feature\Usage\Concerns\AgencyRebillFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 09 §3 sites 4, 12, 13, 14 — AgencyRebill is
 * presented truthfully: never labelled client-paid, never shown the client's
 * card, and never offered through the legacy agency/client selector.
 */
class AgencyRebillPresentationTest extends TestCase
{
    use AgencyRebillFixtures;
    use RefreshDatabase;

    public function test_the_dashboard_shows_the_managing_agencys_payment_method_never_the_clients(): void
    {
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $this->businessProviderCustomerWithCard($m['business'], '9999');
        $this->workspaceProviderCustomerWithCard($m['client'], '8888');

        $viewModel = app(UsageBillingPresenter::class)->buildDashboardViewModel($m['business']);

        $this->assertSame(PayerType::AgencyRebill->value, $viewModel->payer['payer_type']);
        $this->assertSame('1111', $viewModel->paymentMethod['last_four']);
    }

    public function test_the_dashboard_shows_no_payment_method_for_an_agency_rebill_payer_that_no_longer_resolves(): void
    {
        $m = $this->rebilledClient();
        $this->workspaceProviderCustomerWithCard($m['agency'], '1111');
        $this->businessProviderCustomerWithCard($m['business'], '9999');
        app(AgencyClientRelationshipManager::class)->terminate((int) $m['agencyOwner']->user_id, $m['relationship'], 'Ended.');

        $viewModel = app(UsageBillingPresenter::class)->buildDashboardViewModel($m['business']);

        $this->assertSame(PayerType::AgencyRebill->value, $viewModel->payer['payer_type']);
        $this->assertNull($viewModel->paymentMethod, 'It would charge nothing, so it shows nothing — and never the client card.');
    }

    public function test_the_workspace_and_business_payer_payment_methods_are_unchanged(): void
    {
        [, $workspacePaid, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth, 'Paid By Workspace', 'Workspace Account');
        $this->setPayer($workspacePaid, PayerType::Workspace);
        $this->workspaceProviderCustomerWithCard($workspace, '4444');

        [, $businessPaid] = $this->tenantWithWallet(WorkspacePlanTier::Growth, 'Paid By Business', 'Business Account');
        $this->setPayer($businessPaid, PayerType::Business);
        $this->businessProviderCustomerWithCard($businessPaid, '5555');

        $this->assertSame('4444', app(UsageBillingPresenter::class)->buildDashboardViewModel($workspacePaid)->paymentMethod['last_four']);
        $this->assertSame('5555', app(UsageBillingPresenter::class)->buildDashboardViewModel($businessPaid)->paymentMethod['last_four']);
    }

    public function test_the_usage_and_billing_page_never_tells_the_client_it_pays(): void
    {
        $m = $this->rebilledClient();
        $this->authenticateAs($m['clientOwner']);

        $html = $this->get($this->usageBillingUrl($m['client'], $m['business']))->assertOk()->getContent();

        $this->assertStringContainsString(__('locale.usage_billing.responsibility.agency_manages'), $html);
        $this->assertStringNotContainsString(e(__('locale.usage_billing.responsibility.client_pays')), $html);
        $this->assertStringNotContainsString(e(__('locale.usage_billing.responsibility.you_pay')), $html);
    }

    /**
     * BLOCKED (Contract 14) — dead-model coverage, not restorable without
     * reintroducing the invalid topology.
     *
     * `WorkspaceController::billingResponsibilityViewData()` (the account
     * frame's "Client accounts" panel this test exercises via
     * `customer.workspaces.show`) only ever lists a Business whose
     * `customer_id` differs from ITS OWN Workspace's `owner_user_id`. Under
     * Contract 13 a Workspace holds exactly one Business, so for ANY
     * genuinely valid managed-client account — a Client Workspace built by
     * createAgencyManagedClient()/managedClient()/rebilledClient(), where
     * that Client Workspace's own Business is (as always) owned by that same
     * Client Workspace's own owner — this panel is permanently empty: there
     * is no "different customer" divergence to show, rebilled or not.
     *
     * The ONLY way to make the panel non-empty is to give a Workspace a
     * Business owned by a different customer than that Workspace's own
     * owner (businessOwnedByAnotherCustomer()) — which is exactly the
     * "Agency Workspace contains a client-owned Business" shape Contract 13
     * forbids using to represent a managed client. Since the panel's one
     * precondition and "never represent a client as a Business inside
     * someone else's single-Business Workspace" are mutually exclusive under
     * V1, there is no fixture that can exercise this panel without also
     * violating the topology it was flagged for. See
     * AgencyBillingResponsibilityTest for the sibling coverage of the same
     * panel, blocked for the same reason.
     *
     * Reserved for Contract 14 to decide: either retire
     * billingResponsibilityViewData()'s "Client accounts" panel (it can no
     * longer fire for any real V1 account), or give it a genuine
     * relationship-backed data source.
     */
    public function test_the_agency_account_frame_shows_agency_rebill_read_only_and_never_as_a_selector_option(): void
    {
        $this->markTestSkipped('Contract 14: billingResponsibilityViewData()\'s "Client accounts" account-frame panel cannot fire for any real V1 account without the forbidden Business-inside-Agency-Workspace shape; see docblock.');
    }

    public function test_the_legacy_payer_selector_still_rejects_agency_rebill(): void
    {
        $m = $this->managedClient(WorkspacePlanTier::Agency);
        $this->authenticateAs($m['clientOwner']);

        $this->post($this->usageBillingRoute('payer', $m['client'], $m['business']), ['payer_type' => 'agency_rebill'])
            ->assertSessionHasErrors('payer_type');

        $this->assertNotSame(PayerType::AgencyRebill->value, $this->assignmentRow($m['business'])->payer_type);
    }

    public function test_the_responsibility_message_mapping_is_exhaustive_and_never_calls_agency_rebill_client_paid(): void
    {
        $method = new ReflectionMethod(UsageBillingController::class, 'responsibilityMessageKey');
        $controller = app(UsageBillingController::class);

        $this->assertSame('responsibility_changed_agency', $method->invoke($controller, PayerType::Workspace, true, true));
        $this->assertSame('responsibility_changed_client', $method->invoke($controller, PayerType::Business, true, true));

        $this->expectException(LogicException::class);
        $method->invoke($controller, PayerType::AgencyRebill, true, true);
    }
}
