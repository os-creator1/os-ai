<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Usage\BusinessPayerChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — T-PAYER-1..4 (contract §12.4, §18 S-6/S-7).
 * The payer selector never renders; Core/Growth pages carry no Workspace
 * vocabulary; a Business-scoped actor cannot mutate the payer by form or
 * direct POST; an agency-paid client reads "Billing managed by your
 * agency" with no funding controls; and submitting the unchanged payer is
 * a true no-op — no update, no transition, no event, no notification, and
 * a neutral message.
 */
class PayerVisibilityAndNoOpTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_core_and_growth_customers_see_no_payer_selector_and_no_workspace_wording(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, $business, $workspace] = $this->tenantWithWallet($tier);
            $this->authenticateAs($owner);

            $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

            $this->assertStringNotContainsString('name="payer_type"', $html, $tier->value);
            $this->assertStringNotContainsString('Set payer', $html, $tier->value);
            $this->assertStringContainsString("You pay for this business's usage.", html_entity_decode($html), $tier->value);
            $this->assertStringNotContainsString('Workspace', $html, $tier->value . ': no Workspace vocabulary for a single-Business customer.');
            $this->assertStringNotContainsString('Agency-wide', $html, $tier->value);
        }
    }

    public function test_an_agency_paid_client_reads_billing_managed_by_your_agency_and_no_funding_controls(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [$client, $business] = $this->clientBusiness($workspace);
        $this->setPayer($business, PayerType::Workspace);
        $this->assign($this->member($workspace, $client->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $business);
        $this->fakeProvider();
        $this->authenticateAs($client);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Billing managed by your agency', $html);
        $this->assertStringNotContainsString('id="usage-billing-top-up-form"', $html);
        $this->assertStringNotContainsString('id="usage-billing-auto-recharge-form"', $html);
        $this->assertStringNotContainsString('Turn on automatic top-up', $html);
        $this->assertStringNotContainsString('name="payer_type"', $html);
        $this->assertStringNotContainsString('Northwind Agency', $html, 'The Agency identity is not disclosed to the client.');
        $this->assertStringNotContainsString('Agency-wide', $html);
    }

    public function test_a_client_paid_business_sees_its_own_funding_controls_but_never_the_payer_control(): void
    {
        [, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [$client, $business] = $this->clientBusiness($workspace);
        $this->setPayer($business, PayerType::Business);
        $this->assign($this->member($workspace, $client->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $business);
        $this->fakeProvider();
        $this->authenticateAs($client);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('id="usage-billing-top-up-form"', $html);
        $this->assertStringContainsString('id="usage-billing-auto-recharge-form"', $html);
        $this->assertStringNotContainsString('name="payer_type"', $html);
        $this->assertStringNotContainsString('Billing managed by your agency', $html);
    }

    public function test_a_business_scoped_actor_cannot_mutate_the_payer_by_direct_post(): void
    {
        [, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [$client, $business] = $this->clientBusiness($workspace);
        $this->setPayer($business, PayerType::Workspace);
        $this->assign($this->member($workspace, $client->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $business);
        $this->authenticateAs($client);
        Event::fake([BusinessPayerChanged::class]);

        $before = DB::table('business_payer_assignments')->where('business_id', $business->id)->first();

        $this->post($this->usageBillingRoute('payer', $workspace, $business), ['payer_type' => 'business'])
            ->assertRedirect()
            ->assertSessionHas('flash_error');

        $this->assertEquals((array) $before, (array) DB::table('business_payer_assignments')->where('business_id', $business->id)->first());
        $this->assertSame(0, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
        Event::assertNotDispatched(BusinessPayerChanged::class);

        // A scoped Admin is still a Business-scoped actor.
        $scopedAdmin = $this->createCustomer();
        $this->assign($this->member($workspace, $scopedAdmin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected), $business);
        $this->authenticateAs($scopedAdmin);

        $this->post($this->usageBillingRoute('payer', $workspace, $business), ['payer_type' => 'business'])
            ->assertRedirect()
            ->assertSessionHas('flash_error');
        $this->assertSame(0, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());

        // A stranger cannot even find the page.
        $stranger = $this->createCustomer();
        $this->authenticateAs($stranger);
        $this->post($this->usageBillingRoute('payer', $workspace, $business), ['payer_type' => 'business'])->assertNotFound();
    }

    public function test_core_and_growth_owners_cannot_manufacture_an_agency_payer_change_post(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, $business, $workspace] = $this->tenantWithWallet($tier);
            $this->authenticateAs($owner);

            $this->post($this->usageBillingRoute('payer', $workspace, $business), ['payer_type' => 'business'])
                ->assertRedirect()
                ->assertSessionHas('flash_error');

            $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'workspace']);
            $this->assertSame(0, DB::table('business_payer_transitions')->where('business_id', $business->id)->count(), $tier->value);
        }
    }

    public function test_submitting_the_unchanged_payer_is_a_true_no_op(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [, $business] = $this->clientBusiness($workspace);
        $this->setPayer($business, PayerType::Workspace);
        $this->authenticateAs($agency);
        Event::fake([BusinessPayerChanged::class]);
        Notification::fake();

        $before = DB::table('business_payer_assignments')->where('business_id', $business->id)->first();
        $ledgerBefore = DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count();

        $response = $this->post($this->usageBillingRoute('payer', $workspace, $business), ['payer_type' => 'workspace'])
            ->assertRedirect($this->usageBillingUrl($workspace, $business))
            ->assertSessionHas('flash_info')
            ->assertSessionMissing('flash_success');

        $message = session('flash_info');
        $this->assertStringContainsString('No change', $message);
        $this->assertStringContainsString('already billed to your agency', $message);
        $this->assertStringNotContainsStringIgnoringCase('updated', $message);

        $after = DB::table('business_payer_assignments')->where('business_id', $business->id)->first();
        $this->assertEquals((array) $before, (array) $after, 'The row, updated_at included, is byte-for-byte unchanged.');
        $this->assertSame((string) $before->updated_at, (string) $after->updated_at);
        $this->assertSame(0, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
        $this->assertSame($ledgerBefore, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
        Event::assertNotDispatched(BusinessPayerChanged::class);
        Notification::assertNothingSent();

        // The page never says "Payer updated" afterwards either.
        $this->assertStringNotContainsString('Payer updated', $this->get($this->usageBillingUrl($workspace, $business))->getContent());
    }

    public function test_a_real_payer_change_by_the_agency_owner_is_audited_and_announced(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [, $business] = $this->clientBusiness($workspace);
        $this->setPayer($business, PayerType::Workspace);
        $this->authenticateAs($agency);
        Event::fake([BusinessPayerChanged::class]);

        $this->post($this->usageBillingRoute('payer', $workspace, $business), ['payer_type' => 'business'])
            ->assertRedirect()
            ->assertSessionHas('flash_success');

        $this->assertDatabaseHas('business_payer_assignments', ['business_id' => $business->id, 'payer_type' => 'business']);
        $this->assertDatabaseHas('business_payer_transitions', ['business_id' => $business->id, 'from_payer_type' => 'workspace', 'to_payer_type' => 'business', 'actor_user_id' => $agency->user_id]);
        Event::assertDispatched(BusinessPayerChanged::class, fn (BusinessPayerChanged $event) => $event->businessId === (int) $business->id && $event->toPayerType === 'business');

        // Repeating the same submission is now the no-op.
        $this->post($this->usageBillingRoute('payer', $workspace, $business), ['payer_type' => 'business'])
            ->assertSessionHas('flash_info');
        $this->assertSame(1, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
    }

    public function test_the_agency_owner_sees_agency_wide_controls_and_the_agency_paid_client_page_shows_funding_controls_to_the_payer_only(): void
    {
        [$agency, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        [, $business] = $this->clientBusiness($workspace);
        $this->setPayer($business, PayerType::Workspace);
        $this->fakeProvider();
        $this->authenticateAs($agency);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Agency-wide controls', $html);
        $this->assertStringContainsString('id="usage-billing-top-up-form"', $html, 'The Agency is the payer of an agency-paid client.');
        $this->assertStringContainsString("Your agency pays for this client account's usage.", html_entity_decode($html));
        $this->assertStringContainsString('Client accounts', $html, 'The page points to where responsibility is managed.');
        $this->assertStringNotContainsString('name="payer_type"', $html, 'The selector does not live on the Business page.');
    }
}
