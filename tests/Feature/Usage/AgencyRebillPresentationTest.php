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
     * `WorkspaceController::billingResponsibilityViewData()` (the account
     * frame's "Client accounts" panel, reached via `customer.workspaces.show`)
     * only ever lists a Business whose `customer_id` differs from ITS OWN
     * Workspace's `owner_user_id`. A real managed client built by
     * rebilledClient() is a SEPARATE Client Workspace whose one Business
     * (Contract 13) is, as always, owned by that same Client Workspace's own
     * owner — there is no "different customer" divergence to show, rebilled
     * or not. So a real AgencyRebill relationship never surfaces through this
     * legacy same-Workspace selector at all: the panel renders for the
     * Client Workspace's own owner (Agency tier + manages), but its business
     * list stays empty. That absence — not a read-only row — is the true V1
     * presentation fact, and is exactly what distinguishes real
     * relationship-backed AgencyRebill from the old same-Workspace payer
     * selector. See AgencyBillingResponsibilityTest for the sibling coverage
     * of the same panel's one legitimate (non-managed-client) precondition.
     */
    public function test_the_client_account_frame_never_surfaces_a_real_agency_rebill_relationship_through_the_legacy_selector(): void
    {
        $m = $this->rebilledClient(WorkspacePlanTier::Agency);
        $this->authenticateAs($m['clientOwner']);

        $response = $this->get(route('customer.workspaces.show', [$m['client']->uid]))->assertOk();
        $html = $response->getContent();

        // The panel itself renders (Agency tier + owner), but lists no
        // business at all: this Business's customer_id is its own Client
        // Workspace's owner_user_id, so it never meets the panel's
        // different-direct-owner precondition, AgencyRebill or not.
        $this->assertStringContainsString('data-role="billing-responsibility"', $html);
        $this->assertStringContainsString(__('locale.usage_billing.responsibility.account_frame_empty'), $html);
        $this->assertSame(['businesses' => []], $response->original->getData()['billingResponsibility']);

        // No read-only "managing agency" row and no legacy agency/client
        // selector form exist for this Business anywhere on the page (the
        // panel has zero rows, so neither can be present for any uid).
        $this->assertStringNotContainsString('data-role="billing-responsibility-managing-agency"', $html);
        $this->assertStringNotContainsString('data-role="billing-responsibility-form"', $html);
        $this->assertStringNotContainsString('name="billing_responsibility"', $html);
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
