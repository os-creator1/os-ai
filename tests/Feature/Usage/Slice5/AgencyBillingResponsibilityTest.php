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

    /**
     * BLOCKED (Contract 14) — dead-model coverage, not restorable without
     * reintroducing the invalid topology.
     *
     * `WorkspaceController::billingResponsibilityViewData()` only ever lists
     * a Business whose `customer_id` differs from ITS OWN Workspace's
     * `owner_user_id`. Under Contract 13 a Workspace holds exactly one
     * Business, so the ONLY way to make this panel non-empty is to give a
     * Workspace a Business owned by a different customer than that
     * Workspace's own owner — which is exactly the "Agency Workspace
     * contains a client-owned Business" shape Contract 13 forbids using to
     * represent a managed client (see AgencyClientPortfolio.php: "under the
     * V1 topology an Agency Workspace holds exactly one Business, its OWN").
     * There is no canonical V1 equivalent that both (a) genuinely represents
     * an Agency-managed client (a separate Client Workspace reached through
     * a real AgencyClientWorkspaceRelationship) and (b) satisfies this
     * panel's own precondition — a real managed client's own Business is,
     * as always, owned by that Client Workspace's own owner, so the panel
     * would never show it either way.
     *
     * Reserved for Contract 14 to decide: either retire this panel (it can
     * no longer fire for any real V1 account) or give it a genuine
     * relationship-backed data source.
     */
    public function test_the_agency_owner_sees_the_control_in_client_accounts_with_the_active_option_marked(): void
    {
        $this->markTestSkipped('Contract 14: billingResponsibilityViewData()\'s "Client accounts" panel cannot fire for any real V1 account without the forbidden Business-inside-Agency-Workspace shape; see docblock.');
    }

    /** BLOCKED (Contract 14) — same root cause as the owner-visibility test above; see its docblock. */
    public function test_an_agency_wide_active_admin_sees_the_control(): void
    {
        $this->markTestSkipped('Contract 14: billingResponsibilityViewData()\'s "Client accounts" panel cannot fire for any real V1 account without the forbidden Business-inside-Agency-Workspace shape.');
    }

    public function test_core_and_growth_accounts_never_see_it(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, , $workspace] = $this->tenantWithWallet($tier, 'Harbor Lane Studios', 'Harbor Lane');
            $this->authenticateAs($owner);

            // Their account page opens their Business's Settings; neither shows it.
            $response = $this->followingRedirects()->get($this->accountFrameUrl($workspace))->assertOk();
            $html = $response->getContent();

            $this->assertStringNotContainsString('data-role="billing-responsibility"', $html);
            $this->assertStringNotContainsString('name="billing_responsibility"', $html);
            $this->assertStringNotContainsString('name="payer_type"', $html);
            $this->assertArrayNotHasKey('billingResponsibility', $response->original->getData());
        }
    }

    /**
     * NOTE (Contract 13): this test used to also prove that the Business's
     * own direct customer (owned inside the SAME Agency Workspace, distinct
     * from the workspace owner — the pre-Contract-13 "Business client" shape)
     * is denied the account frame. That specific case is dropped: it is the
     * same "Agency Workspace contains a client-owned Business" shape Contract
     * 13 forbids using to represent a managed client (see
     * AgencyBillingResponsibilityTest's other BLOCKED tests, and
     * AgencyClientPortfolio.php). The staff/scoped-admin/inactive-admin/
     * stranger denials below need no client concept at all — they hold for
     * the Agency's own ordinary Business — and remain fully proven.
     */
    public function test_staff_selected_scope_admins_and_strangers_never_see_it(): void
    {
        [, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Northwind Bakery', 'Northwind Agency');

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
        // Unlike the mutation-only tests elsewhere in this file, this one
        // also reads the account-frame's OWN rendered radio button back after
        // the change — which billingResponsibilityViewData() only ever
        // renders for a Business owned by a different customer than the
        // Workspace owner (an ordinary V1 shape, not an Agency-managed-client
        // relationship — see businessOwnedByAnotherCustomer()'s own
        // docblock). An owner-owned Business would never be listed at all,
        // so the mechanic is genuinely needed here.
        [$agency, $workspace] = $this->agencyAccountWithWallet('Northwind Agency');
        [, $business] = $this->businessOwnedByAnotherCustomer($workspace, 'Riverside Bakery');
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
        // Two ordinary, unrelated Agency-tier accounts — no relationship
        // between them, and no different-customer mechanic needed: this
        // tests cross-Workspace concealment of the mutation route, not the
        // account frame's list of Businesses.
        [$agencyA, $businessA, $workspaceA] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Client A', 'Agency A Workspace');
        [, $businessB, $workspaceB] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Client B', 'Agency B Workspace');
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

        // A selected-scope Admin of an Agency Workspace cannot either — this
        // needs only an ordinary Agency-tier account and a scoped-admin
        // membership, no different-customer mechanic.
        [, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Northwind Bakery', 'Northwind Agency');
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
