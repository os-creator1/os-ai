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
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * PR #302 correction 3, finding A — the legacy `/api/http` counterpart of
 * CustomerAccountAccessApiGateTest. routes/http.php has neither Sanctum nor
 * any other middleware that resolves `$request->user()`: every controller
 * here authenticates a request-supplied `api_token` inside the method body.
 * These tests exercise the real routes/controllers end to end, proving the
 * inline CustomerAccountAccessGuard checks now added there behave exactly
 * like the Sanctum /api/v3 gate for the same Inactive/Suspended cases.
 */
class CustomerAccountAccessHttpGateTest extends TestCase
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
            'email' => 'http-gate-admin-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        return $this->platformAdminId = (int) $admin->id;
    }

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace, 3: string} the
     *   fourth element is the plaintext api_token
     */
    private function httpCustomerWithWorkspace(WorkspacePlanTier $tier = WorkspacePlanTier::Core): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(EntitlementManager::class)->assignFirstPlan($business->workspace, $tier, $this->platformAdminId(), 'Fixture assignment.', true, 0);

        $token = Str::random(60);
        $customer->user->update(['api_token' => $token]);
        // ContactsHTTPController/CampaignHTTPController check $user->can('developers')
        // via Customer.permissions (no session primes it here — this is a
        // stateless token request, exactly like production).
        $customer->update(['permissions' => json_encode(['developers'])]);

        return [$customer, $business, $business->workspace->fresh(), $token];
    }

    private function lockWorkspace(Workspace $workspace, WorkspacePlanAssignmentStatus $status): void
    {
        app(EntitlementManager::class)->changePlanStatus($workspace, $status, $this->platformAdminId(), 'Fixture lock.');
    }

    private function fixtureContactGroup(Customer $customer, Business $business): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $customer->user_id,
            'business_id' => $business->id,
            'name' => 'Fixture Group',
        ]);
    }

    // =========================================================================
    // sms/send — actor-scoped, real route/controller.
    // =========================================================================

    public function test_an_active_customer_retains_existing_http_sms_send_behavior(): void
    {
        [, , , $token] = $this->httpCustomerWithWorkspace();

        $response = $this->postJson(route('api_http.sms.send'), [
            'api_token' => $token,
            'recipient' => '15551234567',
            'message' => 'Active attempt.',
            'sender_id' => 'Test',
        ]);

        // Never blocked by this correction: whatever the pre-existing
        // legacy subscription/quota logic does next (unrelated to the
        // account-access gate and not set up by this fixture) is
        // unaffected.
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotContains($response->json('reason'), ['plan_inactive', 'plan_suspended', 'workspace_ambiguous']);
    }

    public function test_an_inactive_customer_cannot_call_the_real_http_sms_send_path(): void
    {
        [, , $workspace, $token] = $this->httpCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);

        $response = $this->postJson(route('api_http.sms.send'), [
            'api_token' => $token,
            'recipient' => '15551234567',
            'message' => 'Blocked attempt.',
            'sender_id' => 'Test',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_inactive']);
    }

    public function test_a_suspended_customer_cannot_call_the_real_http_sms_send_path(): void
    {
        [, , $workspace, $token] = $this->httpCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);

        $response = $this->postJson(route('api_http.sms.send'), [
            'api_token' => $token,
            'recipient' => '15551234567',
            'message' => 'Blocked attempt.',
            'sender_id' => 'Test',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_suspended']);
    }

    /**
     * The denial happens before checkQuickSendValidation()/the sending
     * provider is ever invoked — a Mockery expectation of zero calls on the
     * real CampaignRepository contract, not an inference from the status
     * code alone.
     */
    public function test_locked_denial_happens_before_http_send_side_effects(): void
    {
        [, , $workspace, $token] = $this->httpCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);

        $spy = Mockery::mock(CampaignRepository::class);
        $spy->shouldNotReceive('checkQuickSendValidation');
        $spy->shouldNotReceive('sendApi');
        $spy->shouldNotReceive('quickSend');
        $spy->shouldNotReceive('apiCampaignBuilder');
        $this->app->instance(CampaignRepository::class, $spy);

        $this->postJson(route('api_http.sms.send'), [
            'api_token' => $token,
            'recipient' => '15551234567',
            'message' => 'Blocked attempt.',
            'sender_id' => 'Test',
        ])->assertStatus(403);
    }

    // =========================================================================
    // Contact writes — resource-addressed, real route/controller.
    // =========================================================================

    public function test_an_inactive_customer_cannot_perform_a_real_http_contact_write(): void
    {
        [$customer, $business, $workspace, $token] = $this->httpCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $group = $this->fixtureContactGroup($customer, $business);
        $contactCountBefore = Contacts::count();

        $response = $this->postJson(route('api_http.contact.store', $group), [
            'api_token' => $token,
            'PHONE' => '15551234567',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_inactive']);
        $this->assertSame($contactCountBefore, Contacts::count(), 'The locked denial must happen before any Contact row is written.');
    }

    public function test_a_suspended_customer_cannot_perform_a_real_http_contact_write(): void
    {
        [$customer, $business, $workspace, $token] = $this->httpCustomerWithWorkspace();
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);
        $group = $this->fixtureContactGroup($customer, $business);
        $contactCountBefore = Contacts::count();

        $response = $this->postJson(route('api_http.contact.store', $group), [
            'api_token' => $token,
            'PHONE' => '15551234567',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_suspended']);
        $this->assertSame($contactCountBefore, Contacts::count(), 'The locked denial must happen before any Contact row is written.');
    }

    public function test_a_contact_write_in_a_secondary_locked_http_workspace_is_blocked_even_though_the_primary_is_active(): void
    {
        [$customer, $primaryBusiness, , $token] = $this->httpCustomerWithWorkspace();

        $secondaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Secondary']));
        app(EntitlementManager::class)->assignFirstPlan($secondaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        $this->lockWorkspace($secondaryBusiness->workspace, WorkspacePlanAssignmentStatus::Suspended);

        $group = $this->fixtureContactGroup($customer, $secondaryBusiness);
        $contactCountBefore = Contacts::count();

        $response = $this->postJson(route('api_http.contact.store', $group), [
            'api_token' => $token,
            'PHONE' => '15551234567',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'reason' => 'plan_suspended']);
        $this->assertSame($contactCountBefore, Contacts::count());

        // The primary, active Workspace remains completely unaffected.
        $primaryGroup = $this->fixtureContactGroup($customer, $primaryBusiness);
        $activeResponse = $this->postJson(route('api_http.contact.store', $primaryGroup), [
            'api_token' => $token,
            'PHONE' => '15557654321',
        ]);
        $this->assertNotSame(403, $activeResponse->getStatusCode());
        $this->assertNotContains($activeResponse->json('reason'), ['plan_inactive', 'plan_suspended', 'workspace_ambiguous']);
    }
}
