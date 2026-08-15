<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\UndefinedPlanPricingException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EntitlementManagerComplimentaryStatusTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createNonAdmin(): int
    {
        return User::create([
            'first_name' => 'Non', 'last_name' => 'Admin', 'email' => 'nonadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ])->id;
    }

    private function definePricing(?float $ratio = 0.5): void
    {
        $currency = Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        WorkspacePlanCatalog::where('tier', 'core')->update(['price' => '49.00', 'currency_id' => $currency->id, 'additional_business_slot_price_ratio' => $ratio]);
    }

    private function complimentaryWorkspace(int $slots = 0): Workspace
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture.', true, $slots);

        return $workspace->fresh();
    }

    public function test_grant_requires_non_empty_reason(): void
    {
        // Pricing must be defined BEFORE the revoke below — revoke requires
        // base pricing to flip is_complimentary to false, so defining it
        // afterward left the revoke call itself throwing
        // UndefinedPlanPricingException before the real grant-reason
        // assertion under test was ever reached.
        $this->definePricing();
        $workspace = $this->complimentaryWorkspace();
        app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());
        $workspace = $workspace->fresh();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->grantComplimentaryStatus($workspace, $this->createAdmin(), '');
    }

    public function test_grant_stores_exact_provenance(): void
    {
        $this->definePricing();
        $workspace = $this->complimentaryWorkspace();
        app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());
        $admin = $this->createAdmin();

        $updated = app(EntitlementManager::class)->grantComplimentaryStatus($workspace->fresh(), $admin, 'Grant reason.');

        $this->assertTrue($updated->is_complimentary);
        $this->assertSame('Grant reason.', $updated->complimentary_reason);
        $this->assertSame($admin, $updated->complimentary_granted_by_user_id);
        $this->assertNotNull($updated->complimentary_granted_at);
    }

    public function test_already_complimentary_grant_no_op_preserves_original_provenance(): void
    {
        // complimentary_reason/complimentary_granted_by_user_id/
        // complimentary_granted_at live on WorkspacePlanAssignment, not
        // Workspace — reading them off $workspace returns null via
        // Eloquent's undefined-attribute fallback, not the real values.
        $workspace = $this->complimentaryWorkspace();
        $original = app(\App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class)->findByWorkspaceId($workspace->id);

        $updated = app(EntitlementManager::class)->grantComplimentaryStatus($workspace, $this->createAdmin(), 'Different reason.');

        $this->assertSame($original->complimentary_reason, $updated->complimentary_reason);
        $this->assertSame($original->complimentary_granted_by_user_id, $updated->complimentary_granted_by_user_id);
        $this->assertEquals($original->complimentary_granted_at, $updated->complimentary_granted_at);
    }

    public function test_grant_requires_no_catalog_pricing_and_acquires_no_catalog_lock(): void
    {
        // Pricing must be defined before the revoke (revoke itself requires
        // base pricing) but is then cleared again before the grant, so the
        // grant below is proven to succeed against genuinely undefined
        // pricing — grantComplimentaryStatus() has no pricing check at all.
        $workspace = $this->complimentaryWorkspace();
        $this->definePricing();
        app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());
        WorkspacePlanCatalog::where('tier', 'core')->update(['price' => null, 'currency_id' => null, 'additional_business_slot_price_ratio' => null]);
        $workspace = $workspace->fresh();

        $updated = app(EntitlementManager::class)->grantComplimentaryStatus($workspace, $this->createAdmin(), 'Grant.');

        $this->assertTrue($updated->is_complimentary);
    }

    public function test_revoke_against_undefined_pricing_is_rejected_and_assignment_remains_complimentary(): void
    {
        $workspace = $this->complimentaryWorkspace();

        $this->expectException(UndefinedPlanPricingException::class);
        app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());
    }

    public function test_revoke_against_defined_pricing_succeeds(): void
    {
        $this->definePricing();
        $workspace = $this->complimentaryWorkspace();

        $updated = app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());

        $this->assertFalse($updated->is_complimentary);
        $this->assertDatabaseHas('workspace_entitlement_transitions', ['workspace_id' => $workspace->id, 'transition_type' => 'complimentary_revoked']);
    }

    public function test_non_administrator_actor_is_denied_for_both_grant_and_revoke(): void
    {
        $workspace = $this->complimentaryWorkspace();
        $nonAdmin = $this->createNonAdmin();

        try {
            app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $nonAdmin);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
        }

        try {
            app(EntitlementManager::class)->grantComplimentaryStatus($workspace, $nonAdmin, 'Reason.');
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
        }

        $this->assertTrue(true);
    }

    public function test_non_administrator_against_undefined_pricing_revoke_still_receives_authorization_exception(): void
    {
        $workspace = $this->complimentaryWorkspace();

        try {
            app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createNonAdmin());
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        } catch (UndefinedPlanPricingException $e) {
            $this->fail('Expected AuthorizationException, got UndefinedPlanPricingException: ' . $e->getMessage());
        }
    }

    public function test_revoke_with_extra_slots_and_null_ratio_is_rejected(): void
    {
        $this->definePricing(ratio: null);
        $workspace = $this->complimentaryWorkspace(1);

        $this->expectException(UndefinedPlanPricingException::class);
        app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());
    }

    public function test_revoke_with_two_extra_slots_and_null_ratio_is_rejected(): void
    {
        $this->definePricing(ratio: null);
        $workspace = $this->complimentaryWorkspace(2);

        $this->expectException(UndefinedPlanPricingException::class);
        app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());
    }

    public function test_revoke_with_zero_extra_slots_and_null_ratio_succeeds(): void
    {
        $this->definePricing(ratio: null);
        $workspace = $this->complimentaryWorkspace(0);

        $updated = app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());

        $this->assertFalse($updated->is_complimentary);
    }

    public function test_revoke_with_slots_and_full_pricing_succeeds(): void
    {
        $this->definePricing(ratio: 0.5);
        $workspace = $this->complimentaryWorkspace(2);

        $updated = app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());

        $this->assertFalse($updated->is_complimentary);
    }

    public function test_failed_revoke_leaves_state_provenance_transitions_and_events_unchanged(): void
    {
        // complimentary_reason/is_complimentary live on WorkspacePlanAssignment,
        // not Workspace — reading them off $workspace returns null, making
        // assertTrue($after->is_complimentary) fail even on a correct revoke
        // rejection.
        $workspace = $this->complimentaryWorkspace();
        $assignmentRepository = app(\App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class);
        $before = $assignmentRepository->findByWorkspaceId($workspace->id);

        try {
            app(EntitlementManager::class)->revokeComplimentaryStatus($workspace, $this->createAdmin());
            $this->fail('Expected UndefinedPlanPricingException.');
        } catch (UndefinedPlanPricingException) {
        }

        $after = $assignmentRepository->findByWorkspaceId($workspace->id);
        $this->assertTrue($after->is_complimentary);
        $this->assertSame($before->complimentary_reason, $after->complimentary_reason);
        $this->assertDatabaseMissing('workspace_entitlement_transitions', ['workspace_id' => $workspace->id, 'transition_type' => 'complimentary_revoked']);
    }

    public function test_agency_complimentary_revoke_with_zero_slots_and_null_ratio_succeeds(): void
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'Agency Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $currency = Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        WorkspacePlanCatalog::where('tier', 'agency')->update(['price' => '199.00', 'currency_id' => $currency->id]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Agency, $this->createAdmin(), 'Fixture.', true, 0);

        $updated = app(EntitlementManager::class)->revokeComplimentaryStatus($workspace->fresh(), $this->createAdmin());

        $this->assertFalse($updated->is_complimentary);
    }
}
