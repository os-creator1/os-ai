<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessPayerAssignment;
use App\Models\BusinessPayerTransition;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 09 §6.2/§7 — the Agency owner's own AgencyRebill
 * consent grant/revoke UI and HTTP action. The existing Client/legacy payer
 * selector (UpdateBusinessPayerRequest) never accepts agency_rebill
 * (Enums\Usage\PayerType's own docblock); these routes are the only
 * customer-facing way to reach BillingProfileManager::assignPayer() and
 * ::revokeAgencyRebillConsent() with PayerType::AgencyRebill. Every
 * authorization decision asserted here is BillingProfileManager's own —
 * this suite proves the controller delegates to it rather than
 * reimplementing any rule.
 */
class AgencyClientsAgencyRebillHttpTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    private function relationships(): AgencyClientRelationshipManager
    {
        return app(AgencyClientRelationshipManager::class);
    }

    /** @return array{0: Workspace, 1: User} */
    private function agency(string $name = 'Northwind Agency'): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);

        return [$workspace->fresh(), $customer->user->fresh()];
    }

    private function memberOf(Workspace $workspace, WorkspaceMembershipRole $role, bool $active = true): User
    {
        $customer = $this->createCustomer();
        $customer->permissions = json_encode([]);
        $customer->save();

        $this->createMembership($workspace, $customer->user, [
            'role' => $role,
            'is_active' => $active,
        ]);

        return $customer->user->fresh();
    }

    private function outsider(): User
    {
        return $this->createCustomer()->user->fresh();
    }

    private function actingAsCustomer(User $user): static
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $this->allCustomerPermissions()))]);

        return $this->actingAs($user);
    }

    /** @return array{0: Workspace, 1: Business, 2: User} */
    private function clientAccount(string $name = 'Alpha Dental'): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, $name . ' Clinic', $name);

        return [$workspace, $business, $customer->user->fresh()];
    }

    private function link(Workspace $agency, User $owner, Workspace $client): AgencyClientWorkspaceRelationship
    {
        return $this->relationships()->create((int) $owner->id, $agency, $client);
    }

    private function assignUrl(Workspace $agency, Workspace $client): string
    {
        return route('customer.workspaces.clients.agency-rebill.assign', [$agency->uid, $client->uid]);
    }

    private function revokeUrl(Workspace $agency, Workspace $client): string
    {
        return route('customer.workspaces.clients.agency-rebill.revoke', [$agency->uid, $client->uid]);
    }

    private function showUrl(Workspace $agency, Workspace $client): string
    {
        return route('customer.workspaces.clients.show', [$agency->uid, $client->uid]);
    }

    // ------------------------------------------------------------------
    // GRANT
    // ------------------------------------------------------------------

    public function test_the_agency_owner_can_fund_a_linked_clients_business(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $response = $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), ['confirm' => '1']);

        $response->assertRedirect($this->showUrl($agency, $client));
        $assignment = BusinessPayerAssignment::where('business_id', $business->id)->firstOrFail();
        $this->assertSame(PayerType::AgencyRebill, $assignment->payer_type);
        $this->assertNotNull($assignment->agency_rebill_consented_at);
        $this->assertSame((int) $owner->id, (int) $assignment->agency_rebill_consented_by_user_id);
    }

    public function test_granting_writes_one_audited_transition_row(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), ['confirm' => '1'])->assertRedirect();

        $this->assertSame(1, BusinessPayerTransition::where('business_id', $business->id)->count());
        $transition = BusinessPayerTransition::where('business_id', $business->id)->sole();
        $this->assertSame('agency_rebill', $transition->to_payer_type->value);
        $this->assertSame('granted', $transition->agency_rebill_consent);
        $this->assertSame((int) $owner->id, (int) $transition->actor_user_id);
    }

    public function test_confirmation_is_required(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), [])
            ->assertSessionHasErrors('confirm');

        $this->assertFalse(BusinessPayerAssignment::where('business_id', $business->id)
            ->where('payer_type', PayerType::AgencyRebill->value)->exists());
    }

    public function test_an_agency_admin_cannot_grant_agencyrebill_owner_only(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);
        $admin = $this->memberOf($agency, WorkspaceMembershipRole::Admin);

        $this->actingAsCustomer($admin)->post($this->assignUrl($agency, $client), ['confirm' => '1'])
            ->assertNotFound();

        $this->assertFalse(BusinessPayerAssignment::where('business_id', $business->id)
            ->where('payer_type', PayerType::AgencyRebill->value)->exists());
    }

    public function test_an_unrelated_actor_cannot_grant_by_crafting_the_url(): void
    {
        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB, $ownerB] = $this->agency('Agency B');
        [$clientB, $businessB] = $this->clientAccount('Client B');
        $this->link($agencyB, $ownerB, $clientB);

        $this->actingAsCustomer($ownerA)->post($this->assignUrl($agencyA, $clientB), ['confirm' => '1'])
            ->assertNotFound();

        $this->assertFalse(BusinessPayerAssignment::where('business_id', $businessB->id)
            ->where('payer_type', PayerType::AgencyRebill->value)->exists());
    }

    public function test_a_terminated_relationship_refuses_grant(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $relationship = $this->link($agency, $owner, $client);
        $this->relationships()->terminate((int) $owner->id, $relationship, 'Test: terminated.');

        $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), ['confirm' => '1'])
            ->assertNotFound();

        $this->assertFalse(BusinessPayerAssignment::where('business_id', $business->id)
            ->where('payer_type', PayerType::AgencyRebill->value)->exists());
    }

    public function test_the_existing_workspace_payer_selector_never_accepts_agency_rebill(): void
    {
        [$client, $business, $owner] = $this->clientAccount();

        $response = $this->actingAsCustomer($owner)->post(
            route('customer.workspaces.businesses.usage-billing.payer', [$client->uid, $business->uid]),
            ['payer_type' => 'agency_rebill'],
        );

        $response->assertSessionHasErrors();
        $this->assertFalse(BusinessPayerAssignment::where('business_id', $business->id)
            ->where('payer_type', PayerType::AgencyRebill->value)->exists());
    }

    // ------------------------------------------------------------------
    // REVOKE
    // ------------------------------------------------------------------

    public function test_the_agency_owner_can_revoke_standing_consent(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);
        $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), ['confirm' => '1'])->assertRedirect();

        $this->actingAsCustomer($owner)->post($this->revokeUrl($agency, $client))->assertRedirect($this->showUrl($agency, $client));

        $assignment = BusinessPayerAssignment::where('business_id', $business->id)->firstOrFail();
        $this->assertSame(PayerType::AgencyRebill, $assignment->payer_type, 'Revoking must not silently fall back to another payer.');
        $this->assertNull($assignment->agency_rebill_consented_at);
    }

    public function test_an_agency_admin_cannot_revoke_owner_only(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);
        $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), ['confirm' => '1'])->assertRedirect();
        $admin = $this->memberOf($agency, WorkspaceMembershipRole::Admin);

        $this->actingAsCustomer($admin)->post($this->revokeUrl($agency, $client))->assertNotFound();

        $this->assertNotNull(BusinessPayerAssignment::where('business_id', $business->id)->firstOrFail()->agency_rebill_consented_at);
    }

    /**
     * Contract 09 §6.2/§7 correction — the detail page must show three
     * distinct states, not two: payer_type alone cannot distinguish "never
     * configured" from "revoked" because revokeAgencyRebillConsent() never
     * falls back to another payer. This proves the page's own copy tracks
     * agency_rebill_consented_at through a full grant → revoke → re-grant
     * cycle, and that re-granting after a revoke writes a fresh, distinct
     * audited transition (never silently reusing the old one).
     */
    public function test_the_detail_page_distinguishes_not_configured_active_and_revoked_then_allows_regranting(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        // Not configured.
        $this->actingAsCustomer($owner)->get($this->showUrl($agency, $client))
            ->assertOk()
            ->assertSee('agency-rebill-not-configured', false)
            ->assertDontSee('agency-rebill-active', false)
            ->assertDontSee('agency-rebill-revoked', false);

        // Active.
        $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), ['confirm' => '1'])->assertRedirect();
        $this->actingAsCustomer($owner)->get($this->showUrl($agency, $client))
            ->assertOk()
            ->assertSee('agency-rebill-active', false)
            ->assertDontSee('agency-rebill-not-configured', false)
            ->assertDontSee('agency-rebill-revoked', false);

        // Revoked: payer_type stays agency_rebill, consent clears.
        $this->actingAsCustomer($owner)->post($this->revokeUrl($agency, $client))->assertRedirect();
        $assignment = BusinessPayerAssignment::where('business_id', $business->id)->firstOrFail();
        $this->assertSame(PayerType::AgencyRebill, $assignment->payer_type);
        $this->assertNull($assignment->agency_rebill_consented_at);

        $this->actingAsCustomer($owner)->get($this->showUrl($agency, $client))
            ->assertOk()
            ->assertSee('agency-rebill-revoked', false)
            ->assertDontSee('agency-rebill-not-configured', false)
            ->assertDontSee('agency-rebill-active', false);

        // Re-granting requires fresh confirmation, same as the first grant.
        $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), [])
            ->assertSessionHasErrors('confirm');

        // Re-grant.
        $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), ['confirm' => '1'])->assertRedirect();

        $assignment = $assignment->fresh();
        $this->assertSame(PayerType::AgencyRebill, $assignment->payer_type);
        $this->assertNotNull($assignment->agency_rebill_consented_at);

        $this->actingAsCustomer($owner)->get($this->showUrl($agency, $client))
            ->assertOk()
            ->assertSee('agency-rebill-active', false)
            ->assertDontSee('agency-rebill-not-configured', false)
            ->assertDontSee('agency-rebill-revoked', false);

        // Grant, revoke, re-grant is three distinct audited transitions.
        $this->assertSame(3, BusinessPayerTransition::where('business_id', $business->id)->count());
        $consents = BusinessPayerTransition::where('business_id', $business->id)->orderBy('id')->pluck('agency_rebill_consent')->all();
        $this->assertSame(['granted', 'revoked', 'granted'], $consents);
    }

    // ------------------------------------------------------------------
    // ISOLATION FROM LANE C (the SaaS-offer card on the same page)
    // ------------------------------------------------------------------

    public function test_agencyrebill_funding_never_creates_an_agency_saas_subscription(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $this->actingAsCustomer($owner)->post($this->assignUrl($agency, $client), ['confirm' => '1'])->assertRedirect();

        $this->assertSame(0, \App\Models\AgencyClientSubscription::where('client_workspace_id', $client->id)->count());
    }

    public function test_the_client_detail_page_shows_the_funding_card_separately_from_saas_subscription(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $response = $this->actingAsCustomer($owner)->get($this->showUrl($agency, $client));

        $response->assertOk();
        $response->assertSee('agency-rebill-assign-form', false);
        $response->assertSee('Usage funding (AgencyRebill)');
        $response->assertSee('SaaS subscription');
    }
}
