<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Usage\BusinessPayerChanged;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — Correction Round 1 §8, §10 and §13.7
 * (tests 45–57): the Agency payer control at Client accounts → [Business]
 * → Billing responsibility on the Slice 1B account frame, its visibility,
 * its customer vocabulary, server-side authorization with concealed 404s,
 * the audited real change, and the complete no-op through the real form.
 */
class AgencyBillingResponsibilityTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    private function accountFrameUrl(Workspace $workspace): string
    {
        return route('customer.workspaces.show', [$workspace->uid]);
    }

    private function payerPostUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.usage-billing.payer', [$workspace->uid, $business->uid]);
    }

    private function assertCustomerVocabularyOnly(string $html): void
    {
        $this->assertStringContainsString('data-role="billing-responsibility"', $html);
        $this->assertStringContainsString('name="billing_responsibility"', $html);
        $this->assertStringContainsString('Agency pays', $html);
        $this->assertStringContainsString('Client pays', $html);
        $this->assertStringContainsString('Your agency adds funds and manages automatic top-up and spending limits for this client account.', $html);
        $this->assertStringContainsString('The client adds their own funds and manages automatic top-up and spending limits for this client account.', $html);
        $this->assertStringContainsString('Save billing responsibility', $html);

        $this->assertStringNotContainsString('name="payer_type"', $html);
        $this->assertStringNotContainsString('payer_type', $html);
        $this->assertStringNotContainsString('Workspace pays', $html);
        $this->assertStringNotContainsString('Business pays', $html);
        $this->assertStringNotContainsStringIgnoringCase('payer assignment', $html);
        $this->assertStringNotContainsStringIgnoringCase('assignment id', $html);
    }

    public function test_the_agency_owner_sees_the_control_in_client_accounts_with_the_active_option_marked(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [, $business] = $this->clientBusiness($workspace, 'Client Bakery');
        $this->setPayer($business, PayerType::Workspace);
        $this->authenticateAs($agency);

        $response = $this->get($this->accountFrameUrl($workspace))->assertOk();
        $html = $response->getContent();

        $this->assertCustomerVocabularyOnly($html);
        $this->assertStringContainsString('Client Bakery', $html);
        $this->assertSame(1, preg_match('/<input[^>]*name="billing_responsibility"[^>]*value="agency"[^>]*>/', $html, $agencyRadio));
        $this->assertStringContainsString('checked', $agencyRadio[0]);
        $this->assertSame(1, preg_match('/<input[^>]*name="billing_responsibility"[^>]*value="client"[^>]*>/', $html, $clientRadio));
        $this->assertStringNotContainsString('checked', $clientRadio[0]);
        $this->assertStringContainsString('<input type="hidden" name="return_to" value="account">', $html);

        // Presentation facts only: uid, name, customer-facing responsibility.
        $rows = $response->original->getData()['billingResponsibility']['businesses'];
        $this->assertSame([['uid' => $business->uid, 'name' => 'Client Bakery', 'responsibility' => 'agency']], $rows);
    }

    public function test_an_agency_wide_active_admin_sees_the_control(): void
    {
        [, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [, $business] = $this->clientBusiness($workspace, 'Client Bakery');
        $this->setPayer($business, PayerType::Workspace);
        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($admin);

        $html = $this->get($this->accountFrameUrl($workspace))->assertOk()->getContent();

        $this->assertCustomerVocabularyOnly($html);
        $this->assertStringContainsString('Client Bakery', $html);
    }

    public function test_core_and_growth_accounts_never_see_it(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, , $workspace] = $this->tenantWithWallet($tier, 'Harbor Lane Studios', 'Harbor Lane');
            $this->authenticateAs($owner);

            $response = $this->get($this->accountFrameUrl($workspace))->assertOk();
            $html = $response->getContent();

            $this->assertStringNotContainsString('data-role="billing-responsibility"', $html);
            $this->assertStringNotContainsString('name="billing_responsibility"', $html);
            $this->assertStringNotContainsString('name="payer_type"', $html);
            $this->assertArrayNotHasKey('billingResponsibility', $response->original->getData());
        }
    }

    public function test_business_clients_staff_selected_scope_admins_and_strangers_never_see_it(): void
    {
        [, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [$client, $business] = $this->clientBusiness($workspace, 'Client Bakery');
        $this->setPayer($business, PayerType::Workspace);

        // The Business client: the account frame itself is concealed (S-6).
        $this->authenticateAs($client);
        $this->get($this->accountFrameUrl($workspace))->assertNotFound();
        $this->assertStringNotContainsString('name="billing_responsibility"', $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent());

        // Staff (scope all) reaches the frame but never the control.
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff);
        $html = $this->get($this->accountFrameUrl($workspace))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="billing-responsibility"', $html);
        $this->assertStringNotContainsString('name="billing_responsibility"', $html);

        // A selected-scope Admin does not see the account frame at all.
        $scoped = $this->createCustomer();
        $membership = $this->member($workspace, $scoped->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAs($scoped);
        $this->get($this->accountFrameUrl($workspace))->assertNotFound();

        // A suspended (inactive) Admin and a stranger: nothing.
        $inactive = $this->createCustomer();
        $this->member($workspace, $inactive->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, false);
        $this->authenticateAs($inactive);
        $this->get($this->accountFrameUrl($workspace))->assertNotFound();

        $stranger = $this->createCustomer();
        $this->authenticateAs($stranger);
        $this->get($this->accountFrameUrl($workspace))->assertNotFound();
    }

    public function test_a_genuine_change_through_the_new_form_is_audited_once_and_repeating_it_is_a_complete_no_op(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [, $business] = $this->clientBusiness($workspace, 'Client Bakery');
        $this->setPayer($business, PayerType::Workspace);
        $this->authenticateAs($agency);
        Event::fake([BusinessPayerChanged::class]);
        Notification::fake();

        // 53. Agency pays → Client pays: exactly one transition and one event; returns to the account frame.
        $this->from($this->accountFrameUrl($workspace))
            ->post($this->payerPostUrl($workspace, $business), ['billing_responsibility' => 'client', 'return_to' => 'account'])
            ->assertRedirect($this->accountFrameUrl($workspace))
            ->assertSessionHas('flash_success')
            ->assertSessionMissing('flash_info');
        $this->assertStringContainsString('this client now pays for their own account', session('flash_success'));
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'business']);
        $this->assertSame(1, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('business_payer_transitions', ['business_id' => $business->id, 'from_payer_type' => 'workspace', 'to_payer_type' => 'business', 'actor_user_id' => $agency->user_id]);
        Event::assertDispatchedTimes(BusinessPayerChanged::class, 1);

        // The page now marks "Client pays" as current.
        $html = $this->get($this->accountFrameUrl($workspace))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<input[^>]*name="billing_responsibility"[^>]*value="client"[^>]*>/', $html, $clientRadio));
        $this->assertStringContainsString('checked', $clientRadio[0]);

        // 54/55. Repeating the active choice through the same form: nothing at all happens.
        $before = (array) DB::table('business_payer_assignments')->where('business_id', $business->id)->first();
        $ledgerBefore = DB::table('business_usage_ledger_entries')->count();
        $attemptsBefore = DB::table('business_funding_attempts')->count();

        $this->from($this->accountFrameUrl($workspace))
            ->post($this->payerPostUrl($workspace, $business), ['billing_responsibility' => 'client', 'return_to' => 'account'])
            ->assertRedirect($this->accountFrameUrl($workspace))
            ->assertSessionHas('flash_info')
            ->assertSessionMissing('flash_success');
        $message = session('flash_info');
        $this->assertSame('No change — this client already pays for this account.', $message);
        $this->assertStringNotContainsStringIgnoringCase('updated', $message);

        $this->assertSame($before, (array) DB::table('business_payer_assignments')->where('business_id', $business->id)->first(), 'The assignment row, updated_at included, is byte-for-byte unchanged.');
        $this->assertSame(1, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
        $this->assertSame($ledgerBefore, DB::table('business_usage_ledger_entries')->count());
        $this->assertSame($attemptsBefore, DB::table('business_funding_attempts')->count());
        Event::assertDispatchedTimes(BusinessPayerChanged::class, 1);
        Notification::assertNothingSent();

        // Back to Agency pays, then its own no-op wording.
        $this->post($this->payerPostUrl($workspace, $business), ['billing_responsibility' => 'agency', 'return_to' => 'account'])
            ->assertSessionHas('flash_success');
        $this->assertStringContainsString('your agency now pays for this client account', session('flash_success'));
        $this->post($this->payerPostUrl($workspace, $business), ['billing_responsibility' => 'agency', 'return_to' => 'account'])
            ->assertSessionHas('flash_info');
        $this->assertSame('No change — this client account is already billed to your agency.', session('flash_info'));
        $this->assertSame(2, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
    }

    public function test_cross_workspace_and_cross_business_mutations_are_concealed_with_404(): void
    {
        [$agencyA, , $workspaceA] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency A', 'Agency A Workspace');
        [, $businessA] = $this->clientBusiness($workspaceA, 'Client A');
        [, , $workspaceB] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency B', 'Agency B Workspace');
        [, $businessB] = $this->clientBusiness($workspaceB, 'Client B');
        $this->setPayer($businessA, PayerType::Workspace);
        $this->setPayer($businessB, PayerType::Workspace);
        Event::fake([BusinessPayerChanged::class]);
        $this->authenticateAs($agencyA);

        $this->post($this->payerPostUrl($workspaceB, $businessB), ['billing_responsibility' => 'client', 'return_to' => 'account'])->assertNotFound();
        $this->post($this->payerPostUrl($workspaceA, $businessB), ['billing_responsibility' => 'client', 'return_to' => 'account'])->assertNotFound();
        $this->post(route('customer.workspaces.businesses.usage-billing.payer', [$workspaceA->uid, 'no-such-business']), ['billing_responsibility' => 'client'])->assertNotFound();

        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $businessB->id, 'payer_type' => 'workspace']);
        $this->assertSame(0, DB::table('business_payer_transitions')->count());
        Event::assertNotDispatched(BusinessPayerChanged::class);
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $businessA->id, 'payer_type' => 'workspace']);
    }

    public function test_core_and_growth_owners_and_selected_scope_admins_cannot_manufacture_a_payer_change(): void
    {
        Event::fake([BusinessPayerChanged::class]);

        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, $business, $workspace] = $this->tenantWithWallet($tier);
            $this->authenticateAs($owner);

            foreach ([['billing_responsibility' => 'client', 'return_to' => 'account'], ['payer_type' => 'business']] as $payload) {
                $this->post($this->payerPostUrl($workspace, $business), $payload)
                    ->assertRedirect()
                    ->assertSessionHas('flash_error')
                    ->assertSessionMissing('flash_success');
            }

            $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);
        }

        // A selected-scope Admin of an Agency Workspace cannot either.
        [, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [, $business] = $this->clientBusiness($workspace, 'Client Bakery');
        $this->setPayer($business, PayerType::Workspace);
        $scoped = $this->createCustomer();
        $membership = $this->member($workspace, $scoped->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAs($scoped);

        $this->post($this->payerPostUrl($workspace, $business), ['billing_responsibility' => 'client', 'return_to' => 'account'])
            ->assertRedirect()
            ->assertSessionHas('flash_error');
        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);

        $this->assertSame(0, DB::table('business_payer_transitions')->count());
        Event::assertNotDispatched(BusinessPayerChanged::class);
    }
}
