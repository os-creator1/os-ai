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
        $group = $this->fixtureContactGroup($customer, $businessA);

        $response = $this->postJson(route('api.contact.store', $group), ['PHONE' => '15551234567']);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'workspace_ambiguous']);
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
}
