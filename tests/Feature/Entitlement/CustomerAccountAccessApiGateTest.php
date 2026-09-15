<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * PR #302 correction 1 — CustomerAccountAccessApiGate end to end: the
 * authenticated /api/v3 (Sanctum) surface must be locked exactly like the
 * web gate for an Inactive/Suspended Workspace plan, and it must never
 * guess a Workspace when a legacy customer's own Business set is
 * ambiguous.
 */
class CustomerAccountAccessApiGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private ?int $platformAdminId = null;

    private function platformAdminId(): int
    {
        if ($this->platformAdminId !== null) {
            return $this->platformAdminId;
        }

        $admin = User::create([
            'first_name' => 'Platform', 'last_name' => 'Owner',
            'email' => 'api-gate-admin-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        return $this->platformAdminId = (int) $admin->id;
    }

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    private function apiCustomerWithWorkspace(WorkspacePlanTier $tier = WorkspacePlanTier::Core): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        app(EntitlementManager::class)->assignFirstPlan($business->workspace, $tier, $this->platformAdminId(), 'Fixture assignment.', true, 0);

        return [$customer, $business, $business->workspace->fresh()];
    }

    private function lockWorkspace(Workspace $workspace, WorkspacePlanAssignmentStatus $status): void
    {
        app(EntitlementManager::class)->changePlanStatus($workspace, $status, $this->platformAdminId(), 'Chat F correction 1 fixture lock.');
    }

    private function authenticateApi(Customer $customer): User
    {
        $user = $customer->user;
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function fixtureContactGroup(Customer $customer, Business $business): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $customer->user_id,
            'business_id' => $business->id,
            'name' => 'Fixture Group',
        ]);
    }

    public function test_an_active_customer_retains_existing_api_behavior(): void
    {
        [$customer, $business] = $this->apiCustomerWithWorkspace();
        $this->authenticateApi($customer);
        $group = $this->fixtureContactGroup($customer, $business);

        $response = $this->postJson(route('api.contact.store', $group), ['PHONE' => '15551234567']);

        // Never blocked by the new gate: whatever the pre-existing legacy
        // authorization/validation does next (this route also requires the
        // 'create_contact' customer permission and a configured PHONE
        // field, neither of which this correction touches) is unaffected.
        $this->assertNotSame(403, $response->getStatusCode(), 'An Active customer must never receive the account-access gate denial.');
        $this->assertNotContains($response->json('reason'), ['plan_inactive', 'plan_suspended', 'workspace_ambiguous']);
    }

    public function test_an_inactive_customer_cannot_call_the_real_sms_send_api_path(): void
    {
        [$customer, , $workspace] = $this->apiCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateApi($customer);

        $response = $this->postJson(route('api.sms.send'), [
            'recipient' => '15551234567',
            'message' => 'Blocked attempt.',
            'sender_id' => 'Test',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_inactive']);
    }

    public function test_a_suspended_customer_cannot_call_the_real_sms_send_api_path(): void
    {
        [$customer, , $workspace] = $this->apiCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);
        $this->authenticateApi($customer);

        $response = $this->postJson(route('api.sms.send'), [
            'recipient' => '15551234567',
            'message' => 'Blocked attempt.',
            'sender_id' => 'Test',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_suspended']);
    }

    /**
     * The gate denies before the request ever reaches CampaignController,
     * so nothing downstream of it — validation, quota checks, the sending
     * provider — is ever invoked. A Mockery expectation that the real
     * CampaignRepository contract receives zero calls is a direct proof of
     * that, not an inference from the HTTP status code alone.
     */
    public function test_locked_denial_happens_before_send_side_effects(): void
    {
        [$customer, , $workspace] = $this->apiCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateApi($customer);

        $spy = Mockery::mock(CampaignRepository::class);
        $spy->shouldNotReceive('checkQuickSendValidation');
        $spy->shouldNotReceive('sendApi');
        $spy->shouldNotReceive('quickSend');
        $spy->shouldNotReceive('apiCampaignBuilder');
        $this->app->instance(CampaignRepository::class, $spy);

        $this->postJson(route('api.sms.send'), [
            'recipient' => '15551234567',
            'message' => 'Blocked attempt.',
            'sender_id' => 'Test',
        ])->assertStatus(403);
    }

    public function test_an_inactive_customer_cannot_perform_a_real_contact_write(): void
    {
        [$customer, $business, $workspace] = $this->apiCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateApi($customer);
        $group = $this->fixtureContactGroup($customer, $business);
        $contactCountBefore = Contacts::count();

        $response = $this->postJson(route('api.contact.store', $group), ['PHONE' => '15551234567']);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_inactive']);
        $this->assertSame($contactCountBefore, Contacts::count(), 'The locked denial must happen before any Contact row is written.');
    }

    public function test_a_suspended_customer_cannot_perform_a_real_contact_write(): void
    {
        [$customer, $business, $workspace] = $this->apiCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);
        $this->authenticateApi($customer);
        $group = $this->fixtureContactGroup($customer, $business);
        $contactCountBefore = Contacts::count();

        $response = $this->postJson(route('api.contact.store', $group), ['PHONE' => '15551234567']);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_suspended']);
        $this->assertSame($contactCountBefore, Contacts::count(), 'The locked denial must happen before any Contact row is written.');
    }

    /**
     * The tenancy/ambiguity rule: a legacy customer with more than one
     * Business and no single primary can never have their Workspace
     * guessed. Both Workspaces here are genuinely Active — proving this is
     * about the ambiguity itself, never about picking a status.
     *
     * Uses sms/send deliberately: it carries no bound target resource at
     * all (PR #302 correction 3, finding B), so it is genuinely
     * actor-scoped and still exercises CustomerAccountAccessGuard::
     * decisionForActor()'s own ambiguity handling. A resource-addressed
     * route like contact.store no longer would — see
     * test_a_contact_write_is_decided_by_its_own_business_even_when_the_actor_has_an_ambiguous_business_set()
     * below for why that is now correct.
     */
    public function test_ambiguous_workspace_resolution_fails_closed(): void
    {
        $customer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Business B']));

        // Force genuine ambiguity: LegacyBusinessResolver never picks
        // "first" or "newest" when there is more than one primary.
        DB::table('businesses')->where('id', $businessA->id)->update(['is_primary' => true]);
        DB::table('businesses')->where('id', $businessB->id)->update(['is_primary' => true]);

        app(EntitlementManager::class)->assignFirstPlan($businessA->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        app(EntitlementManager::class)->assignFirstPlan($businessB->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);

        $this->authenticateApi($customer);

        $response = $this->postJson(route('api.sms.send'), [
            'recipient' => '15551234567',
            'message' => 'Blocked attempt.',
            'sender_id' => 'Test',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'workspace_ambiguous']);
    }

    // =========================================================================
    // PR #302 correction 3, finding B — resource-addressed routes must be
    // decided by the TARGET resource's own Business/Workspace, never the
    // actor's primary Business.
    // =========================================================================

    public function test_a_contact_write_in_a_secondary_locked_workspace_is_blocked_even_though_the_primary_is_active(): void
    {
        $customer = $this->createCustomer();
        $primaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Primary Active']));
        app(EntitlementManager::class)->assignFirstPlan($primaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);

        $secondaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Secondary Suspended']));
        app(EntitlementManager::class)->assignFirstPlan($secondaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        $this->lockWorkspace($secondaryBusiness->workspace, WorkspacePlanAssignmentStatus::Suspended);

        $this->authenticateApi($customer);
        $group = $this->fixtureContactGroup($customer, $secondaryBusiness);
        $contactCountBefore = Contacts::count();

        $response = $this->postJson(route('api.contact.store', $group), ['PHONE' => '15551234567']);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_suspended']);
        $this->assertSame($contactCountBefore, Contacts::count(), 'The locked denial must happen before any Contact row is written.');
    }

    public function test_a_contact_write_in_the_active_primary_workspace_remains_usable_while_a_secondary_is_locked(): void
    {
        $customer = $this->createCustomer();
        $primaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Primary Active']));
        app(EntitlementManager::class)->assignFirstPlan($primaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);

        $secondaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Secondary Suspended']));
        app(EntitlementManager::class)->assignFirstPlan($secondaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        $this->lockWorkspace($secondaryBusiness->workspace, WorkspacePlanAssignmentStatus::Suspended);

        $this->authenticateApi($customer);
        $group = $this->fixtureContactGroup($customer, $primaryBusiness);

        $response = $this->postJson(route('api.contact.store', $group), ['PHONE' => '15551234567']);

        // Never the gate's own denial: the locked secondary Workspace must
        // never contaminate a write correctly addressed to the active one.
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotContains($response->json('reason'), ['plan_inactive', 'plan_suspended', 'workspace_ambiguous']);
    }

    /**
     * The precise contrast with the pre-existing ambiguity test above: an
     * actor whose OWN Business set is ambiguous (ownership-wide) still gets
     * a normal, resource-scoped decision when the request names a concrete
     * target resource — the ambiguity never applies once a specific
     * Business is already known from the route itself.
     */
    public function test_a_contact_write_is_decided_by_its_own_business_even_when_the_actor_has_an_ambiguous_business_set(): void
    {
        $customer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Business B']));
        DB::table('businesses')->where('id', $businessA->id)->update(['is_primary' => true]);
        DB::table('businesses')->where('id', $businessB->id)->update(['is_primary' => true]);
        app(EntitlementManager::class)->assignFirstPlan($businessA->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        app(EntitlementManager::class)->assignFirstPlan($businessB->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);

        $this->authenticateApi($customer);
        $group = $this->fixtureContactGroup($customer, $businessA);

        $response = $this->postJson(route('api.contact.store', $group), ['PHONE' => '15551234567']);

        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotContains($response->json('reason'), ['plan_inactive', 'plan_suspended', 'workspace_ambiguous']);
    }

    /**
     * A brand-new customer with no Business/Workspace at all yet is the
     * same "nothing to lock" case CustomerAccountAccessResolver's own null-
     * Workspace precedent already covers on the web side — never confused
     * with genuine ambiguity.
     */
    public function test_a_customer_with_no_business_at_all_is_not_locked(): void
    {
        $customer = $this->createCustomer();
        $this->authenticateApi($customer);

        $response = $this->postJson(route('api.sms.send'), [
            'recipient' => '15551234567',
            'message' => 'No business yet.',
            'sender_id' => 'Test',
        ]);

        // Never blocked by this gate specifically: a brand-new customer's
        // request reaches CampaignController, which then legitimately
        // refuses it for its own, pre-existing, unrelated reason (no
        // 'developers' permission granted to this fixture) -- a 403 with no
        // 'reason' key, never this gate's 'reason' key.
        $this->assertNull($response->json('reason'));
    }

    // =========================================================================
    // PR #302 correction 4 — multi-resource routes (group_id + uid) must
    // never let one bound resource authorize a mutation against a different
    // one. contacts/{group_id}/update/{uid} is the real vulnerable route:
    // ContactsController::updateContact() writes $uid directly.
    // =========================================================================

    private function fixtureContact(Customer $customer, Business $business, ContactGroups $group, string $phone): Contacts
    {
        return Contacts::create([
            'customer_id' => $customer->user_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
    }

    public function test_updating_a_contact_in_a_suspended_secondary_workspace_is_blocked_even_though_the_group_is_active(): void
    {
        $customer = $this->createCustomer();
        $primaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Primary Active']));
        app(EntitlementManager::class)->assignFirstPlan($primaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);

        $secondaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Secondary Suspended']));
        app(EntitlementManager::class)->assignFirstPlan($secondaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        $this->lockWorkspace($secondaryBusiness->workspace, WorkspacePlanAssignmentStatus::Suspended);

        // The routed group is the ACTIVE primary Business; the routed
        // contact is actually recorded against the SUSPENDED secondary one
        // -- the exact bound-resources-disagree shape the finding
        // describes. CustomerAccountAccessApiGate must never pick the
        // group (the "first" bound parameter) and let this through.
        $group = $this->fixtureContactGroup($customer, $primaryBusiness);
        $contact = $this->fixtureContact($customer, $secondaryBusiness, $group, '15551230000');
        $originalPhone = $contact->phone;

        $this->authenticateApi($customer);

        $response = $this->patchJson(route('api.contact.update', [$group->uid, $contact->uid]), ['phone' => '15559998888']);

        $response->assertStatus(403);
        $this->assertSame($originalPhone, $contact->fresh()->phone, 'The denial must happen before any Contact row is written.');
    }

    public function test_updating_a_contact_in_an_inactive_secondary_workspace_is_blocked_even_though_the_group_is_active(): void
    {
        $customer = $this->createCustomer();
        $primaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Primary Active']));
        app(EntitlementManager::class)->assignFirstPlan($primaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);

        $secondaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Secondary Inactive']));
        app(EntitlementManager::class)->assignFirstPlan($secondaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        $this->lockWorkspace($secondaryBusiness->workspace, WorkspacePlanAssignmentStatus::Inactive);

        $group = $this->fixtureContactGroup($customer, $primaryBusiness);
        $contact = $this->fixtureContact($customer, $secondaryBusiness, $group, '15551230001');
        $originalPhone = $contact->phone;

        $this->authenticateApi($customer);

        $response = $this->patchJson(route('api.contact.update', [$group->uid, $contact->uid]), ['phone' => '15559998888']);

        $response->assertStatus(403);
        $this->assertSame($originalPhone, $contact->fresh()->phone);
    }

    public function test_group_and_contact_resolving_to_different_businesses_fails_closed_without_disclosing_either_plan_state(): void
    {
        $customer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Business A']));
        app(EntitlementManager::class)->assignFirstPlan($businessA->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);

        $businessB = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Business B']));
        app(EntitlementManager::class)->assignFirstPlan($businessB->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        $this->lockWorkspace($businessB->workspace, WorkspacePlanAssignmentStatus::Suspended);

        $group = $this->fixtureContactGroup($customer, $businessA);
        $contact = $this->fixtureContact($customer, $businessB, $group, '15551230002');
        $originalPhone = $contact->phone;

        $this->authenticateApi($customer);

        $response = $this->patchJson(route('api.contact.update', [$group->uid, $contact->uid]), ['phone' => '15559998888']);

        $response->assertStatus(403);
        // Never leaks that Business B is the specific locked one, and
        // never confirms Business A's status either -- a mismatch is
        // refused generically, not evaluated against any candidate.
        $this->assertNotSame('plan_suspended', $response->json('reason'));
        $this->assertNotSame('plan_inactive', $response->json('reason'));
        $this->assertSame('resource_mismatch', $response->json('reason'));
        $this->assertSame($originalPhone, $contact->fresh()->phone);
    }

    public function test_group_and_contact_in_the_same_business_but_different_group_is_rejected_by_ordinary_resource_semantics(): void
    {
        [$customer, $business] = $this->apiCustomerWithWorkspace();

        // Two DIFFERENT groups inside the SAME (Active) Business -- the
        // account-lock gate's own business_id-agreement check cannot catch
        // this (both groups share one business_id); only the controller's
        // own contact.group_id === group.id relationship check can.
        $realGroup = $this->fixtureContactGroup($customer, $business);
        $otherGroup = ContactGroups::create([
            'customer_id' => $customer->user_id,
            'business_id' => $business->id,
            'name' => 'Other Group',
        ]);
        $contact = $this->fixtureContact($customer, $business, $realGroup, '15551230003');
        $originalPhone = $contact->phone;

        $this->authenticateApi($customer);

        // Routed against $otherGroup, but the contact actually belongs to
        // $realGroup.
        $response = $this->patchJson(route('api.contact.update', [$otherGroup->uid, $contact->uid]), ['phone' => '15559998888']);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame($originalPhone, $contact->fresh()->phone, 'A mismatched group/contact pair must never be written.');
    }

    public function test_a_valid_active_group_and_contact_pair_remains_reachable(): void
    {
        [$customer, $business] = $this->apiCustomerWithWorkspace();
        $group = $this->fixtureContactGroup($customer, $business);
        $contact = $this->fixtureContact($customer, $business, $group, '15551230004');

        $this->authenticateApi($customer);

        $response = $this->patchJson(route('api.contact.update', [$group->uid, $contact->uid]), ['phone' => '15559998888']);

        // Never blocked by the gate or the relationship check: whatever
        // this app's own field-validation logic does next (not set up by
        // this fixture) is unaffected by this correction.
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotContains($response->json('reason'), ['plan_inactive', 'plan_suspended', 'workspace_ambiguous', 'resource_mismatch']);
    }

    public function test_a_valid_locked_group_and_contact_pair_discloses_the_real_reason(): void
    {
        [$customer, $business, $workspace] = $this->apiCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);
        $group = $this->fixtureContactGroup($customer, $business);
        $contact = $this->fixtureContact($customer, $business, $group, '15551230005');

        $this->authenticateApi($customer);

        $response = $this->patchJson(route('api.contact.update', [$group->uid, $contact->uid]), ['phone' => '15559998888']);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_suspended']);
    }

    /**
     * search and delete already re-query by group_id internally
     * (EloquentContactsRepository::contactDestroy(), and searchContact()'s
     * own Contacts::where('group_id', ...)->where('uid', ...) filter), so
     * a mismatched pair was never actually mutable through them -- but the
     * gate's own bound-resource conflict detection must still refuse the
     * request before either controller runs.
     */
    public function test_search_and_delete_with_mismatched_businesses_are_also_refused_by_the_gate(): void
    {
        $customer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Business A']));
        app(EntitlementManager::class)->assignFirstPlan($businessA->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        $businessB = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Business B']));
        app(EntitlementManager::class)->assignFirstPlan($businessB->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);

        $group = $this->fixtureContactGroup($customer, $businessA);
        $contact = $this->fixtureContact($customer, $businessB, $group, '15551230006');

        $this->authenticateApi($customer);

        $this->postJson(route('api.contact.search', [$group->uid, $contact->uid]))->assertStatus(403);
        $this->deleteJson(route('api.contact.delete', [$group->uid, $contact->uid]))->assertStatus(403);
        $this->assertNotNull(Contacts::find($contact->id), 'Delete must never proceed for a mismatched pair.');
    }
}
