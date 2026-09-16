<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Http\Controllers\Customer\Business\UsageBillingController;
use App\Library\Usage\BillingProfileManager;
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

    public function test_the_agency_account_frame_shows_agency_rebill_read_only_and_never_as_a_selector_option(): void
    {
        // An Agency-tier Client Workspace managed by another Agency, so its own
        // account frame lists client accounts; one of them is AgencyRebill.
        $m = $this->managedClient(WorkspacePlanTier::Agency);
        [, $rebilled] = $this->clientBusiness($m['client'], 'Rebilled Client');
        [, $selfPaying] = $this->clientBusiness($m['client'], 'Self Paying Client');
        app(BillingProfileManager::class)->assignPayer($rebilled, PayerType::AgencyRebill, (int) $m['agencyOwner']->user_id, 'Agency funds this client.');
        $this->setPayer($selfPaying, PayerType::Business);

        $this->authenticateAs($m['clientOwner']);
        $html = $this->get(route('customer.workspaces.show', $m['client']->uid))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="billing-responsibility-managing-agency" data-business-uid="' . $rebilled->uid . '"', $html);
        $this->assertStringContainsString(e(__('locale.usage_billing.responsibility.managing_agency_option')), $html);
        $this->assertStringNotContainsString('billing-responsibility-agency-' . $rebilled->uid, $html);
        $this->assertStringNotContainsString('billing-responsibility-client-' . $rebilled->uid, $html);
        $this->assertStringNotContainsString('data-business-uid="' . $rebilled->uid . '" data-role="billing-responsibility-form"', $html);

        // The ordinary client account keeps its selector.
        $this->assertStringContainsString('billing-responsibility-client-' . $selfPaying->uid, $html);
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
