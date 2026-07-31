<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceContextFailureReason;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\WorkspaceContextRequiredException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WorkspaceManagerTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Legacy',
            'last_name' => 'Owner',
            'email' => 'legacy-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ], $overrides));
    }

    private function createCustomerRecord(int $userId, ?string $company = null): Customer
    {
        return Customer::create(['user_id' => $userId, 'company' => $company]);
    }

    private function createWorkspaceOwnedBy(int $ownerUserId, array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'name' => 'Existing Workspace',
            'owner_user_id' => $ownerUserId,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * is_primary is not mass-assignable (by design — neither
     * Business::$fillable nor the repository allow it), so it is set as a
     * direct property, defaulting to false when not supplied. workspaceId
     * is a required argument, not an optional attribute: businesses.workspace_id
     * is NOT NULL under the final schema, so every Business this helper
     * creates is inserted exactly once, with workspace_id already set —
     * never inserted first and assigned afterward. Tests whose premise
     * needs a Business with no Workspace candidate at all live in
     * WorkspaceManagerPreEnforcementTest instead, against the isolated
     * pre-enforcement schema where that state can still be constructed.
     */
    private function createBusiness(int $customerId, int $workspaceId, array $attributes = []): Business
    {
        $isPrimary = $attributes['is_primary'] ?? false;
        unset($attributes['is_primary'], $attributes['workspace_id']);

        $business = new Business(array_merge([
            'name' => 'Legacy Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $attributes));
        $business->customer_id = $customerId;
        $business->workspace_id = $workspaceId;
        $business->is_primary = $isPrimary;
        $business->save();

        return $business;
    }

    private function createOnboarding(int $customerId, ?int $businessId = null): CustomerOnboarding
    {
        return CustomerOnboarding::create([
            'customer_id' => $customerId,
            'business_id' => $businessId,
        ]);
    }

    // 1. missing owner User throws ModelNotFoundException.
    public function test_missing_owner_user_throws_model_not_found_exception(): void
    {
        $manager = app(WorkspaceManager::class);

        $this->expectException(ModelNotFoundException::class);
        $manager->resolveLegacyOnboardingWorkspace(999999);
    }

    // 2 & 3. no candidates creates exactly one Workspace; owner_user_id matches.
    public function test_no_candidates_creates_exactly_one_workspace_owned_by_the_owner(): void
    {
        $owner = $this->createUser();
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame($owner->id, $result->owner_user_id);
        $this->assertSame(1, Workspace::where('owner_user_id', $owner->id)->count());
    }

    // 4. generated uid is a valid UUID.
    public function test_generated_uid_is_a_valid_uuid(): void
    {
        $owner = $this->createUser();
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertTrue(Str::isUuid($result->uid));
    }

    // 5. resolver creates no Business.
    public function test_resolver_creates_no_business(): void
    {
        $owner = $this->createUser();
        $manager = app(WorkspaceManager::class);
        $businessCountBefore = DB::table('businesses')->count();

        $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame($businessCountBefore, DB::table('businesses')->count());
    }

    // 6. second resolution reuses the same Workspace.
    public function test_second_resolution_reuses_the_same_workspace(): void
    {
        $owner = $this->createUser();
        $manager = app(WorkspaceManager::class);

        $first = $manager->resolveLegacyOnboardingWorkspace($owner->id);
        $second = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Workspace::where('owner_user_id', $owner->id)->count());
    }

    // 7. customers.company wins naming. No Business is needed at all: tier
    // 1 (company) is checked before any Business-derived tier, so a
    // Business's presence or absence cannot affect this assertion — and a
    // primary Business would need a real Workspace candidate under the
    // final schema, which would make the resolver reuse it instead of
    // creating a new (company-named) one, defeating the point of this test.
    public function test_customers_company_wins_naming(): void
    {
        $owner = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->createCustomerRecord($owner->id, 'Acme Corp');
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame('Acme Corp', $result->name);
    }

    // 8/9/12 (naming tiers 2 and 3, and the multiple-primary tier-2 skip)
    // moved to WorkspaceManagerPreEnforcementTest: each genuinely needs a
    // Business with zero Workspace candidates — a state businesses.workspace_id's
    // NOT NULL constraint makes unconstructible under this file's final schema.

    // 10. User name is naming tier 4.
    public function test_user_name_is_naming_tier_four(): void
    {
        $owner = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame("Jane Doe's Workspace", $result->name);
    }

    // 11. fully blank data uses "Customer #{id}'s Workspace".
    public function test_fully_blank_data_uses_deterministic_customer_id_fallback(): void
    {
        $owner = $this->createUser(['first_name' => '', 'last_name' => '']);
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame("Customer #{$owner->id}'s Workspace", $result->name);
    }

    // 13. onboarding Business and primary Business linked to the same Workspace return it.
    public function test_onboarding_and_primary_business_linked_to_same_workspace_return_it(): void
    {
        $owner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($owner->id);
        $business = $this->createBusiness($owner->id, $workspace->id, ['is_primary' => true]);
        $this->createOnboarding($owner->id, $business->id);

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame($workspace->id, $result->id);
    }

    // 14. onboarding Business Workspace A plus primary Workspace B throws MultiplePreferredCandidates.
    public function test_onboarding_workspace_a_plus_primary_workspace_b_throws(): void
    {
        $owner = $this->createUser();
        $workspaceA = $this->createWorkspaceOwnedBy($owner->id, ['name' => 'A']);
        $workspaceB = $this->createWorkspaceOwnedBy($owner->id, ['name' => 'B']);
        $onboardingBusiness = $this->createBusiness($owner->id, $workspaceA->id, ['name' => 'Onboarding Biz']);
        $this->createBusiness($owner->id, $workspaceB->id, ['name' => 'Primary Biz', 'is_primary' => true]);
        $this->createOnboarding($owner->id, $onboardingBusiness->id);

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::MultiplePreferredCandidates, $e->reason);
        }
    }

    // 15. two primary Businesses linked to different Workspaces throw MultiplePreferredCandidates.
    public function test_two_primary_businesses_linked_to_different_workspaces_throw(): void
    {
        $owner = $this->createUser();
        $workspaceA = $this->createWorkspaceOwnedBy($owner->id, ['name' => 'A']);
        $workspaceB = $this->createWorkspaceOwnedBy($owner->id, ['name' => 'B']);
        $this->createBusiness($owner->id, $workspaceA->id, ['name' => 'Primary A', 'is_primary' => true]);
        $this->createBusiness($owner->id, $workspaceB->id, ['name' => 'Primary B', 'is_primary' => true]);

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::MultiplePreferredCandidates, $e->reason);
        }
    }

    // 16. one preferred Workspace wins despite multiple unrelated owner-owned Workspaces.
    public function test_one_preferred_workspace_wins_despite_unrelated_owned_workspaces(): void
    {
        $owner = $this->createUser();
        $unrelatedA = $this->createWorkspaceOwnedBy($owner->id, ['name' => 'Unrelated A']);
        $unrelatedB = $this->createWorkspaceOwnedBy($owner->id, ['name' => 'Unrelated B']);
        $preferred = $this->createWorkspaceOwnedBy($owner->id, ['name' => 'Preferred']);
        $this->createBusiness($owner->id, $preferred->id, ['is_primary' => true]);

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame($preferred->id, $result->id);
        $this->assertNotSame($unrelatedA->id, $result->id);
        $this->assertNotSame($unrelatedB->id, $result->id);
    }

    // 17. missing onboarding Business reference throws OnboardingBusinessReferenceInvalid.
    public function test_missing_onboarding_business_reference_throws(): void
    {
        // Independently verified before ever touching FOREIGN_KEY_CHECKS —
        // this must never run against any connection but the disposable
        // testing database.
        $this->assertSame('ultimatesms_testing', DB::connection()->getDatabaseName());

        $owner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($owner->id);
        $business = $this->createBusiness($owner->id, $workspace->id);
        $onboarding = $this->createOnboarding($owner->id, $business->id);
        $danglingBusinessId = $business->id;
        DB::table('businesses')->where('id', $business->id)->delete();

        // customer_onboardings.business_id has a real nullOnDelete() FK, so
        // the delete above already nulled it — a truly dangling reference
        // cannot be constructed through normal writes at all. Disabling FK
        // checks only for this one forced write, on this connection only,
        // is the sole way to exercise the resolver's defensive "missing
        // Business" branch, which the FK otherwise makes unreachable in
        // production. The disable/write is wrapped in try/finally and
        // restored immediately afterward — not deferred to the end of the
        // test — because RefreshDatabase's per-test transaction rollback
        // cleans up the row itself but does not reset MySQL session
        // variables; leaving this disabled for the rest of the test (or a
        // failure skipping restoration) would silently weaken every later
        // assertion in this process, not just this one row.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::table('customer_onboardings')->where('id', $onboarding->id)->update(['business_id' => $danglingBusinessId]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->assertSame(1, (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS value')->value);

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::OnboardingBusinessReferenceInvalid, $e->reason);
        }
    }

    // 18. onboarding Business belonging to another customer throws OnboardingBusinessCustomerMismatch.
    public function test_onboarding_business_belonging_to_another_customer_throws(): void
    {
        $owner = $this->createUser();
        $otherOwner = $this->createUser();
        $otherWorkspace = $this->createWorkspaceOwnedBy($otherOwner->id);
        $othersBusiness = $this->createBusiness($otherOwner->id, $otherWorkspace->id);
        $onboarding = $this->createOnboarding($owner->id);
        DB::table('customer_onboardings')->where('id', $onboarding->id)->update(['business_id' => $othersBusiness->id]);

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::OnboardingBusinessCustomerMismatch, $e->reason);
        }
    }

    // 19. mismatched onboarding Business's Workspace is never reused.
    public function test_mismatched_onboarding_business_workspace_is_never_reused(): void
    {
        $owner = $this->createUser();
        $otherOwner = $this->createUser();
        $othersWorkspace = $this->createWorkspaceOwnedBy($otherOwner->id);
        $othersBusiness = $this->createBusiness($otherOwner->id, $othersWorkspace->id);
        $onboarding = $this->createOnboarding($owner->id);
        DB::table('customer_onboardings')->where('id', $onboarding->id)->update(['business_id' => $othersBusiness->id]);

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::OnboardingBusinessCustomerMismatch, $e->reason);
            $this->assertNotContains($othersWorkspace->id, $e->candidateWorkspaceIds);
        }
    }

    // 20. one Business-linked fallback Workspace is reused even when owned by another User.
    public function test_business_linked_fallback_workspace_reused_when_owned_by_another_user(): void
    {
        $owner = $this->createUser();
        $otherOwner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($otherOwner->id);
        // Not primary, not onboarding-linked — a pure fallback candidate.
        $this->createBusiness($owner->id, $workspace->id);

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame($workspace->id, $result->id);
    }

    // 21. one directly-owned fallback Workspace is reused.
    public function test_directly_owned_fallback_workspace_is_reused(): void
    {
        $owner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($owner->id);

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame($workspace->id, $result->id);
    }

    // 22. the same Workspace appearing through both fallback sources collapses to one.
    public function test_same_workspace_through_both_fallback_sources_collapses_to_one(): void
    {
        $owner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($owner->id);
        $this->createBusiness($owner->id, $workspace->id);

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame($workspace->id, $result->id);
        $this->assertSame(1, Workspace::where('owner_user_id', $owner->id)->count());
    }

    // 23. multiple fallback candidates throw MultipleFallbackCandidates.
    public function test_multiple_fallback_candidates_throw(): void
    {
        $owner = $this->createUser();
        $this->createWorkspaceOwnedBy($owner->id, ['name' => 'Owned A']);
        $this->createWorkspaceOwnedBy($owner->id, ['name' => 'Owned B']);

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::MultipleFallbackCandidates, $e->reason);
        }
    }

    // 24. inactive preferred or fallback Workspace is reused.
    public function test_inactive_workspace_is_reused_as_preferred_and_fallback(): void
    {
        $owner = $this->createUser();
        $inactivePreferred = $this->createWorkspaceOwnedBy($owner->id, ['is_active' => false]);
        $this->createBusiness($owner->id, $inactivePreferred->id, ['is_primary' => true]);

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame($inactivePreferred->id, $result->id);

        $secondOwner = $this->createUser();
        $inactiveFallback = $this->createWorkspaceOwnedBy($secondOwner->id, ['is_active' => false]);

        $fallbackResult = $manager->resolveLegacyOnboardingWorkspace($secondOwner->id);

        $this->assertSame($inactiveFallback->id, $fallbackResult->id);
    }

    // 25. users.parent_id has no effect.
    public function test_users_parent_id_has_no_effect(): void
    {
        $parent = $this->createUser();
        $parentWorkspace = $this->createWorkspaceOwnedBy($parent->id);
        $owner = $this->createUser(['parent_id' => $parent->id]);

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertNotSame($parentWorkspace->id, $result->id);
        $this->assertSame($owner->id, $result->owner_user_id);
    }

    // 26. Workspace memberships have no effect.
    public function test_workspace_memberships_have_no_effect(): void
    {
        $owner = $this->createUser();
        $otherUser = $this->createUser();
        $someWorkspace = $this->createWorkspaceOwnedBy($otherUser->id);
        WorkspaceMembership::create([
            'workspace_id' => $someWorkspace->id,
            'user_id' => $owner->id,
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertNotSame($someWorkspace->id, $result->id);
    }

    /**
     * businesses.workspace_id now carries a NOT NULL + RESTRICT FK, so a
     * genuinely dangling reference can no longer be constructed through
     * ordinary writes at all — the RESTRICT FK alone would block deleting
     * a still-referenced Workspace. Disabling FK checks for this one
     * forced delete (mirroring test_missing_onboarding_business_reference_throws's
     * established rationale in this same file) is the sole remaining way
     * to exercise the resolver's defensive "missing Workspace" branch.
     * Wrapped in try/finally and restored immediately; RefreshDatabase's
     * per-test rollback (not a second forced write) discards the row
     * afterward, so no further repair is needed or attempted here.
     */
    private function deleteWorkspaceBypassingForeignKeyChecks(int $workspaceId): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::table('workspaces')->where('id', $workspaceId)->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    // 27. dangling preferred Workspace ID throws DanglingWorkspaceReference.
    public function test_dangling_preferred_workspace_id_throws(): void
    {
        $owner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($owner->id);
        $this->createBusiness($owner->id, $workspace->id, ['is_primary' => true]);
        $danglingWorkspaceId = $workspace->id;

        $this->deleteWorkspaceBypassingForeignKeyChecks($workspace->id);

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::DanglingWorkspaceReference, $e->reason);
            $this->assertContains($danglingWorkspaceId, $e->candidateWorkspaceIds);
        }
    }

    // 28. dangling fallback Workspace ID throws DanglingWorkspaceReference.
    public function test_dangling_fallback_workspace_id_throws(): void
    {
        $owner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($owner->id);
        $this->createBusiness($owner->id, $workspace->id);
        $danglingWorkspaceId = $workspace->id;

        $this->deleteWorkspaceBypassingForeignKeyChecks($workspace->id);

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::DanglingWorkspaceReference, $e->reason);
            $this->assertContains($danglingWorkspaceId, $e->candidateWorkspaceIds);
        }
    }

    // 29. exception IDs contain only normalized missing/conflicting IDs.
    public function test_exception_ids_contain_only_normalized_conflicting_ids(): void
    {
        $owner = $this->createUser();
        $workspaceB = $this->createWorkspaceOwnedBy($owner->id, ['name' => 'B']);
        $workspaceA = $this->createWorkspaceOwnedBy($owner->id, ['name' => 'A']);

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $expected = collect([$workspaceA->id, $workspaceB->id])->sort()->values()->all();
            $this->assertSame($expected, $e->candidateWorkspaceIds);
        }
    }

    // 30. wrapping the resolver in an outer transaction and throwing afterward rolls back a newly created Workspace.
    public function test_outer_transaction_rollback_removes_newly_created_workspace(): void
    {
        $owner = $this->createUser();
        $manager = app(WorkspaceManager::class);

        try {
            DB::transaction(function () use ($manager, $owner) {
                $manager->resolveLegacyOnboardingWorkspace($owner->id);

                throw new RuntimeException('Forced outer-transaction failure for testing.');
            });
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, Workspace::where('owner_user_id', $owner->id)->count());
    }

    // 31. WorkspaceManager does not reference WorkspaceBackfillV1.
    public function test_workspace_manager_does_not_reference_workspace_backfill_v1(): void
    {
        $source = file_get_contents(app_path('Library/Workspace/WorkspaceManager.php'));

        $this->assertStringNotContainsString('WorkspaceBackfillV1', $source);
    }

    // 32. createForCustomerInWorkspace() (the sole remaining Business-creation
    // method, Slice 3B) is never called by the resolver — it only resolves
    // a Workspace, and never persists a Business itself.
    public function test_resolver_never_calls_business_creation_methods(): void
    {
        $source = file_get_contents(app_path('Library/Workspace/WorkspaceManager.php'));

        // 'createForCustomer' as a shared prefix also catches
        // createForCustomerInWorkspace( — neither is referenced here.
        $this->assertStringNotContainsString('createForCustomer', $source);
    }
}
