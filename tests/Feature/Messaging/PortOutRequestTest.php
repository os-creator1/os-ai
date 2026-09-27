<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\PortOutRequestStatus;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Helpers\Helper;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\PortOutRequestAlreadyActiveException;
use App\Library\Messaging\Exceptions\PortOutRequestNumberNotPortableException;
use App\Library\Messaging\PortOutRequestManager;
use App\Models\BusinessMessagingNumberPortOutRequest;
use App\Models\BusinessMessagingRegistration;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Phone Numbers + A2P lane — messaging contract §13.4: "A customer may
 * port a number out. The platform must not obstruct it. Porting out is a
 * supported, documented request path, not a support escalation."
 *
 * This slice records and tracks the request only — no test here asserts
 * anything about a real Telnyx call, a number release, replacement,
 * transfer or purchase, or the 14-day renewal grace period (§13.2), which
 * this slice does not touch.
 */
class PortOutRequestTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private function manager(): PortOutRequestManager
    {
        return app(PortOutRequestManager::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'first_name' => 'Ops', 'last_name' => 'Admin',
            'email' => 'port-out-ops-admin-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        return $admin;
    }

    // =================================================================
    // The customer-facing request path.
    // =================================================================

    public function test_a_business_owner_can_request_to_port_their_number_out(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551001');
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');

        $this->assertDatabaseHas('business_messaging_number_port_out_requests', [
            'business_id' => $business->id,
            'business_messaging_number_id' => $number->id,
            'phone_number' => '+14155551001',
            'status' => PortOutRequestStatus::Requested->value,
        ]);

        $request = BusinessMessagingNumberPortOutRequest::where('business_id', $business->id)->first();
        $this->assertSame((int) $customer->user->id, $request->requested_by_user_id);
    }

    public function test_a_business_with_no_number_cannot_request_a_port_out(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => 999999])
            ->assertSessionHas('status', 'error');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 0);
    }

    // =================================================================
    // Review correction — §13.4 requires the exit path even when
    // registration is pending/rejected, or the number is suspended
    // ("paid outbound access is unavailable", §13.3). Deliberately NOT
    // resolved through BusinessMessagingIdentityResolver, which would hide
    // both.
    // =================================================================

    public function test_a_customer_can_request_a_port_out_while_registration_is_pending(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551101');
        BusinessMessagingRegistration::create([
            'business_id' => $business->id,
            'number_type' => 'local',
            'status' => 'pending',
        ]);
        $this->authenticateAs($customer, ['view_numbers']);

        // Confirms the scenario is genuine: this Business is NOT in the
        // "ready" state (registration is pending, not approved).
        $html = (string) $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('Request to port this number out', $html);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 1);
    }

    public function test_a_customer_can_request_a_port_out_while_registration_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551102');
        BusinessMessagingRegistration::create([
            'business_id' => $business->id,
            'number_type' => 'local',
            'status' => 'rejected',
        ]);
        $this->authenticateAs($customer, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 1);
    }

    public function test_a_customer_can_request_a_port_out_for_a_suspended_number(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551103', true, BusinessMessagingNumberStatus::Suspended);
        $this->authenticateAs($customer, ['view_numbers']);

        // The number is invisible to Slice 3's own outbound resolver, so
        // this Business renders the "no active number" screen — the
        // exit path must still appear there.
        $html = (string) $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('+14155551103', $html);
        $this->assertStringContainsString('Suspended', $html);
        $this->assertStringContainsString('Request to port this number out', $html);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');

        $this->assertDatabaseHas('business_messaging_number_port_out_requests', [
            'business_id' => $business->id,
            'phone_number' => '+14155551103',
        ]);
    }

    public function test_a_customer_can_cancel_a_port_out_request_for_a_suspended_number(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551104', true, BusinessMessagingNumberStatus::Suspended);
        $this->manager()->request($business, $number, (int) $customer->user->id);
        $this->authenticateAs($customer, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.cancel', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');

        $this->assertSame(PortOutRequestStatus::Cancelled, BusinessMessagingNumberPortOutRequest::where('business_id', $business->id)->first()->status);
    }

    public function test_a_released_number_is_never_offered_for_a_port_out_request(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551105', true, BusinessMessagingNumberStatus::Released);
        $this->authenticateAs($customer, ['view_numbers']);

        $html = (string) $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Request to port this number out', $html);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'error');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 0);
    }

    // =================================================================
    // Review correction — a Pending number is not yet an actually
    // acquired one, so it is never offered and never portable, even by a
    // directly posted id.
    // =================================================================

    public function test_a_pending_number_is_never_offered_for_a_port_out_request(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551201', true, BusinessMessagingNumberStatus::Pending);
        $this->authenticateAs($customer, ['view_numbers']);

        $html = (string) $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Request to port this number out', $html);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'error');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 0);
    }

    public function test_the_manager_refuses_a_pending_number_even_called_directly(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551202', true, BusinessMessagingNumberStatus::Pending);

        $this->expectException(PortOutRequestNumberNotPortableException::class);
        $this->manager()->request($business, $number, 1);
    }

    public function test_the_manager_refuses_a_released_number_even_called_directly(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551203', true, BusinessMessagingNumberStatus::Released);

        $this->expectException(PortOutRequestNumberNotPortableException::class);
        $this->manager()->request($business, $number, 1);
    }

    // =================================================================
    // Review correction — a Business may retain more than one number
    // (§4.2's one-to-many schema); each keeps independent port-out state,
    // and a non-primary number is just as portable as the primary one.
    // =================================================================

    public function test_two_retained_numbers_each_keep_independent_port_out_state(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($business);
        $numberOne = $this->attachNumber($identity, '+14155551301', true);
        $numberTwo = $this->attachNumber($identity, '+14155551302', false);
        $this->authenticateAs($customer, ['view_numbers']);

        $html = (string) $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('+14155551301', $html);
        $this->assertStringContainsString('+14155551302', $html);

        // Request port-out for the first number only.
        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $numberOne->id])
            ->assertSessionHas('status', 'success');

        $this->assertNotNull($this->manager()->activeRequestFor($numberOne));
        $this->assertNull($this->manager()->activeRequestFor($numberTwo), 'The second number must be unaffected by the first number\'s request.');

        // The second number can still be independently requested.
        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $numberTwo->id])
            ->assertSessionHas('status', 'success');

        $this->assertNotNull($this->manager()->activeRequestFor($numberTwo));

        // Cancelling the first leaves the second's own request intact.
        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.cancel', [$workspace->uid, $business->uid]), ['number_id' => $numberOne->id])
            ->assertSessionHas('status', 'success');

        $this->assertNull($this->manager()->activeRequestFor($numberOne));
        $this->assertNotNull($this->manager()->activeRequestFor($numberTwo), 'Cancelling one number\'s request must never cancel another number\'s request.');
    }

    public function test_a_non_primary_number_can_be_requested_for_port_out(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, '+14155551303', true);
        $secondary = $this->attachNumber($identity, '+14155551304', false);
        $this->authenticateAs($customer, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $secondary->id])
            ->assertSessionHas('status', 'success');

        $this->assertDatabaseHas('business_messaging_number_port_out_requests', [
            'business_messaging_number_id' => $secondary->id,
            'phone_number' => '+14155551304',
        ]);
    }

    // =================================================================
    // The ownership-safe lookups themselves.
    // =================================================================

    public function test_retained_numbers_for_finds_a_suspended_number(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551106', true, BusinessMessagingNumberStatus::Suspended);

        $this->assertSame([$number->id], $this->manager()->retainedNumbersFor($business)->pluck('id')->all());
    }

    public function test_retained_numbers_for_excludes_a_released_number(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155551107', true, BusinessMessagingNumberStatus::Released);

        $this->assertTrue($this->manager()->retainedNumbersFor($business)->isEmpty());
    }

    public function test_retained_numbers_for_excludes_a_pending_number(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155551204', true, BusinessMessagingNumberStatus::Pending);

        $this->assertTrue($this->manager()->retainedNumbersFor($business)->isEmpty(), 'A Pending number is not yet actually acquired and must never be offered for port-out.');
    }

    public function test_retained_numbers_for_includes_a_non_primary_number(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($business);
        $primary = $this->attachNumber($identity, '+14155551305', true);
        $secondary = $this->attachNumber($identity, '+14155551306', false);

        $ids = $this->manager()->retainedNumbersFor($business)->pluck('id')->all();
        $this->assertContains($primary->id, $ids);
        $this->assertContains($secondary->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_retained_numbers_for_never_returns_a_foreign_businesss_number(): void
    {
        [, $businessA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $this->attachNumber($this->attachIdentity($businessB), '+14155551109');

        $this->assertTrue($this->manager()->retainedNumbersFor($businessA)->isEmpty());
    }

    public function test_retained_number_for_id_refuses_a_foreign_businesss_number(): void
    {
        [, $businessA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $numberB = $this->attachNumber($this->attachIdentity($businessB), '+14155551305');

        $this->assertNull($this->manager()->retainedNumberFor($businessA, (int) $numberB->id));
    }

    public function test_retained_number_for_id_refuses_a_pending_number(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551205', true, BusinessMessagingNumberStatus::Pending);

        $this->assertNull($this->manager()->retainedNumberFor($business, (int) $number->id));
    }

    // =================================================================
    // Review correction — the manager itself verifies ownership and
    // eligible status, never trusting a caller-supplied (Business,
    // number) pairing, and the controller re-verifies a posted number_id
    // the same way at the mutation boundary.
    // =================================================================

    public function test_the_manager_refuses_a_number_that_does_not_belong_to_the_supplied_business(): void
    {
        [, $businessA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $numberB = $this->attachNumber($this->attachIdentity($businessB), '+14155551110');

        $this->expectException(MessagingIdentityConflictException::class);
        $this->manager()->request($businessA, $numberB, 1);
    }

    public function test_a_foreign_numbers_id_is_refused_at_the_http_mutation_boundary(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $numberB = $this->attachNumber($this->attachIdentity($businessB), '+14155551306');
        $this->authenticateAs($customerA, ['view_numbers']);

        // Business A's own owner, posting Business B's real number id
        // against Business A's own route.
        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspaceA->uid, $businessA->uid]), ['number_id' => $numberB->id])
            ->assertSessionHas('status', 'error');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 0);
    }

    // =================================================================
    // Duplicate/repeat-request prevention.
    // =================================================================

    public function test_a_repeat_request_while_one_is_already_active_is_prevented(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551002');
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'error');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 1);
    }

    public function test_cancelling_frees_the_number_for_a_genuinely_new_request(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551003');
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.cancel', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 2);
        $this->assertSame(1, BusinessMessagingNumberPortOutRequest::where('business_id', $business->id)->active()->count());
        $this->assertSame(1, BusinessMessagingNumberPortOutRequest::where('business_id', $business->id)->where('status', PortOutRequestStatus::Cancelled->value)->count());
    }

    public function test_cancelling_with_no_active_request_is_a_safe_no_op(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551004');
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.cancel', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'error');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 0);
    }

    public function test_the_manager_cancel_call_itself_is_idempotent_at_the_database_layer(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551005');
        $request = $this->manager()->request($business, $number, 1);

        $first = $this->manager()->cancel((int) $request->id, 2);
        $second = $this->manager()->cancel((int) $request->id, 3);

        $this->assertSame(1, $first, 'The first cancel() call updates exactly one row.');
        $this->assertSame(0, $second, 'A second cancel() call against an already-cancelled row updates zero rows.');

        $request->refresh();
        $this->assertSame(2, $request->cancelled_by_user_id, 'The original canceller must never be overwritten.');
    }

    public function test_mysql_itself_rejects_a_second_active_row_for_the_same_number(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551006');
        $this->manager()->request($business, $number, 1);

        $this->expectException(UniqueConstraintViolationException::class);

        // Bypasses PortOutRequestManager's own pre-check entirely, proving
        // the real backstop is MySQL's UNIQUE index on active_number_id,
        // not merely the application-level lockForUpdate() query.
        DB::table('business_messaging_number_port_out_requests')->insert([
            'business_id' => $business->id,
            'business_messaging_number_id' => $number->id,
            'phone_number' => $number->phone_number,
            'status' => PortOutRequestStatus::Requested->value,
            'requested_by_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_manager_converts_the_unique_constraint_violation_into_its_own_exception(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551007');

        // Simulate a lost race: another process already holds the
        // lockForUpdate() row and commits between this request's read and
        // its own insert, which the pre-check alone cannot see.
        DB::table('business_messaging_number_port_out_requests')->insert([
            'business_id' => $business->id,
            'business_messaging_number_id' => $number->id,
            'phone_number' => $number->phone_number,
            'status' => PortOutRequestStatus::Requested->value,
            'requested_by_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(PortOutRequestAlreadyActiveException::class);
        $this->manager()->request($business, $number, 2);
    }

    // =================================================================
    // Authorization — review correction. Porting OUT is not a number-
    // acquisition action: it is gated on view_numbers (the same baseline
    // read capability show()/deliveryUsage() already require) PLUS the
    // canonical owner-or-active-admin Workspace authority, and is
    // deliberately independent of buy_numbers in both directions.
    // =================================================================

    public function test_a_business_owner_can_request_a_port_out_with_no_buy_numbers_permission_at_all(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551008');
        // Deliberately NOT granted: buy_numbers.
        $this->authenticateAs($customer, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 1);
    }

    public function test_neither_action_requires_buy_numbers_permission(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551108');
        $this->authenticateAs($customer, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.cancel', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');
    }

    public function test_a_plain_workspace_member_cannot_request_a_port_out_even_holding_view_numbers(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551009');

        $staffCustomer = $this->createCustomer();
        $this->member($workspace, $staffCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, true);
        $this->authenticateAs($staffCustomer, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertStatus(401);

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 0);
    }

    public function test_an_active_admin_who_is_not_the_owner_can_request_a_port_out(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551010');

        $adminCustomer = $this->createCustomer();
        $this->member($workspace, $adminCustomer->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, true);
        $this->authenticateAs($adminCustomer, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspace->uid, $business->uid]), ['number_id' => $number->id])
            ->assertSessionHas('status', 'success');

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 1);
    }

    // =================================================================
    // Cross-tenant — "authorize it against the correct Business and
    // number."
    // =================================================================

    public function test_an_unrelated_businesss_member_cannot_request_a_port_out_for_a_foreign_business(): void
    {
        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $numberB = $this->attachNumber($this->attachIdentity($businessB), '+14155551011');
        $this->authenticateAs($customerA, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspaceB->uid, $businessB->uid]), ['number_id' => $numberB->id])
            ->assertNotFound();

        $this->assertDatabaseCount('business_messaging_number_port_out_requests', 0);
    }

    public function test_an_unrelated_businesss_member_cannot_cancel_a_foreign_business_port_out_request(): void
    {
        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [$customerB, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $numberB = $this->attachNumber($this->attachIdentity($businessB), '+14155551012');
        $this->manager()->request($businessB, $numberB, (int) $customerB->user->id);

        $this->authenticateAs($customerA, ['view_numbers', 'buy_numbers']);
        $this->post(route('customer.workspaces.businesses.text-messaging.number.port-out.cancel', [$workspaceB->uid, $businessB->uid]), ['number_id' => $numberB->id])
            ->assertNotFound();

        $this->assertSame(
            PortOutRequestStatus::Requested,
            BusinessMessagingNumberPortOutRequest::where('business_id', $businessB->id)->first()->status,
            'A foreign Business member must never cancel this Business\'s own port-out request.',
        );
    }

    // =================================================================
    // Platform-ops visibility — read-only, admin-only.
    // =================================================================

    public function test_a_guest_cannot_list_port_out_requests(): void
    {
        $this->get(route('admin.messaging-port-out-requests.index'))->assertUnauthorized();
    }

    public function test_a_customer_cannot_list_port_out_requests_even_with_backend_permissions_in_session(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->withSession(['permissions' => collect(['access backend', 'access_backend'])]);
        $this->actingAs($customer->user);

        $this->get(route('admin.messaging-port-out-requests.index'))->assertUnauthorized();
    }

    public function test_an_administrator_sees_port_out_requests(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551013');
        $this->manager()->request($business, $number, 1);

        $this->actingAsAdmin();
        $html = (string) $this->get(route('admin.messaging-port-out-requests.index'))->assertOk()->getContent();

        $this->assertStringContainsString($business->name, $html);
        $this->assertStringContainsString('+14155551013', $html);
        $this->assertStringContainsString('requested', $html);
    }

    // =================================================================
    // Discoverable admin menu link, same boundary as the route.
    // =================================================================

    /**
     * @return array<int, object>
     */
    private function usageBillingSubmenu(): array
    {
        $submenu = collect(Helper::menuData()['admin'])->firstWhere('name', 'Usage Billing')['submenu'] ?? [];

        return json_decode(json_encode($submenu));
    }

    public function test_an_administrator_can_find_the_port_out_requests_link_in_the_admin_menu(): void
    {
        $this->actingAsAdmin();

        $html = view('panels.submenu', ['menu' => $this->usageBillingSubmenu()])->render();

        $this->assertStringContainsString(url(config('app.admin_path') . '/messaging-port-out-requests'), $html);
        $this->assertStringContainsString('Messaging Port-Out Requests', $html);
    }

    public function test_a_non_admin_backend_account_cannot_find_the_port_out_requests_link(): void
    {
        $staff = User::create([
            'first_name' => 'Limited', 'last_name' => 'Staff',
            'email' => 'limited-staff-port-out-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($staff);

        $html = view('panels.submenu', ['menu' => $this->usageBillingSubmenu()])->render();

        $this->assertStringNotContainsString(url(config('app.admin_path') . '/messaging-port-out-requests'), $html);
    }

    // =================================================================
    // The recorder-style discipline: the audit columns are not
    // mass-assignable.
    // =================================================================

    public function test_the_cancellation_columns_are_not_mass_assignable(): void
    {
        $model = new BusinessMessagingNumberPortOutRequest();

        $this->assertFalse($model->isFillable('cancelled_at'));
        $this->assertFalse($model->isFillable('cancelled_by_user_id'));
    }

    public function test_mass_assignment_cannot_cancel_a_request_bypassing_the_manager(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+14155551014');

        $request = BusinessMessagingNumberPortOutRequest::create([
            'business_id' => (int) $business->id,
            'business_messaging_number_id' => (int) $number->id,
            'phone_number' => $number->phone_number,
            'status' => PortOutRequestStatus::Requested->value,
            'requested_by_user_id' => 1,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => 999,
        ]);

        $request->refresh();
        $this->assertNull($request->cancelled_at);
        $this->assertNull($request->cancelled_by_user_id);
    }
}
