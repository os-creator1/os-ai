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
     * is_primary and workspace_id are not mass-assignable (by design —
     * neither Business::$fillable nor the repository allow it), so both
     * are set as direct properties after creation, defaulting to
     * false/null when not supplied.
     */
    private function createBusiness(int $customerId, array $attributes = []): Business
    {
        $isPrimary = $attributes['is_primary'] ?? false;
        $workspaceId = $attributes['workspace_id'] ?? null;
        unset($attributes['is_primary'], $attributes['workspace_id']);

        $business = Business::create(array_merge([
            'customer_id' => $customerId,
            'name' => 'Legacy Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $attributes));

        $business->is_primary = $isPrimary;
        $business->workspace_id = $workspaceId;
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

    // 7. customers.company wins naming.
    public function test_customers_company_wins_naming(): void
    {
        $owner = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->createCustomerRecord($owner->id, 'Acme Corp');
        $this->createBusiness($owner->id, ['name' => 'Some Business', 'is_primary' => true]);
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame('Acme Corp', $result->name);
    }

    // 8. one primary Business name is naming tier 2.
    public function test_one_primary_business_name_is_naming_tier_two(): void
    {
        $owner = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->createCustomerRecord($owner->id, null);
        $this->createBusiness($owner->id, ['name' => 'Primary Biz', 'is_primary' => true]);
        $this->createBusiness($owner->id, ['name' => 'Other Biz', 'is_primary' => false]);
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame('Primary Biz', $result->name);
    }

    // 9. first Business by ID is naming tier 3.
    public function test_first_business_by_id_is_naming_tier_three(): void
    {
        $owner = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->createCustomerRecord($owner->id, null);
        $this->createBusiness($owner->id, ['name' => 'First Biz', 'is_primary' => false]);
        $this->createBusiness($owner->id, ['name' => 'Second Biz', 'is_primary' => false]);
        $manager = app(WorkspaceManager::class);

        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame('First Biz', $result->name);
    }

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

    // 12. multiple primary Businesses skip naming tier 2 and use the deterministic first-Business tier.
    public function test_multiple_primary_businesses_skip_tier_two_naming(): void
    {
        $owner = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->createCustomerRecord($owner->id, null);
        $first = $this->createBusiness($owner->id, ['name' => 'First Primary', 'is_primary' => true]);
        // Force a second primary directly — nothing in the schema prevents it.
        $second = $this->createBusiness($owner->id, ['name' => 'Second Primary', 'is_primary' => true]);
        // Give each primary a distinct Workspace so the earlier candidate
        // ambiguity is resolved first — this test is purely about naming
        // once we reach Workspace creation with zero preferred candidates,
        // so both primaries here must have null workspace_id instead.
        $second->workspace_id = null;
        $second->save();

        $manager = app(WorkspaceManager::class);
        $result = $manager->resolveLegacyOnboardingWorkspace($owner->id);

        $this->assertSame('First Primary', $result->name);
    }

    // 13. onboarding Business and primary Business linked to the same Workspace return it.
    public function test_onboarding_and_primary_business_linked_to_same_workspace_return_it(): void
    {
        $owner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($owner->id);
        $business = $this->createBusiness($owner->id, ['is_primary' => true, 'workspace_id' => $workspace->id]);
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
        $onboardingBusiness = $this->createBusiness($owner->id, ['name' => 'Onboarding Biz', 'workspace_id' => $workspaceA->id]);
        $this->createBusiness($owner->id, ['name' => 'Primary Biz', 'is_primary' => true, 'workspace_id' => $workspaceB->id]);
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
        $this->createBusiness($owner->id, ['name' => 'Primary A', 'is_primary' => true, 'workspace_id' => $workspaceA->id]);
        $this->createBusiness($owner->id, ['name' => 'Primary B', 'is_primary' => true, 'workspace_id' => $workspaceB->id]);

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
        $this->createBusiness($owner->id, ['is_primary' => true, 'workspace_id' => $preferred->id]);

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
        $business = $this->createBusiness($owner->id);
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
        $othersBusiness = $this->createBusiness($otherOwner->id);
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
        $othersBusiness = $this->createBusiness($otherOwner->id, ['workspace_id' => $othersWorkspace->id]);
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
        $this->createBusiness($owner->id, ['workspace_id' => $workspace->id]);

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
        $this->createBusiness($owner->id, ['workspace_id' => $workspace->id]);

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
        $this->createBusiness($owner->id, ['is_primary' => true, 'workspace_id' => $inactivePreferred->id]);

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

    // 27. dangling preferred Workspace ID throws DanglingWorkspaceReference.
    public function test_dangling_preferred_workspace_id_throws(): void
    {
        $owner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($owner->id);
        $business = $this->createBusiness($owner->id, ['is_primary' => true, 'workspace_id' => $workspace->id]);
        DB::table('workspaces')->where('id', $workspace->id)->delete();

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::DanglingWorkspaceReference, $e->reason);
            $this->assertContains($workspace->id, $e->candidateWorkspaceIds);
        }

        // Restore referential integrity so tearDown's RefreshDatabase
        // rollback doesn't choke on the dangling reference mid-transaction.
        $business->workspace_id = null;
        $business->save();
    }

    // 28. dangling fallback Workspace ID throws DanglingWorkspaceReference.
    public function test_dangling_fallback_workspace_id_throws(): void
    {
        $owner = $this->createUser();
        $workspace = $this->createWorkspaceOwnedBy($owner->id);
        $business = $this->createBusiness($owner->id, ['workspace_id' => $workspace->id]);
        DB::table('workspaces')->where('id', $workspace->id)->delete();

        $manager = app(WorkspaceManager::class);

        try {
            $manager->resolveLegacyOnboardingWorkspace($owner->id);
            $this->fail('Expected WorkspaceContextRequiredException was not thrown.');
        } catch (WorkspaceContextRequiredException $e) {
            $this->assertSame(WorkspaceContextFailureReason::DanglingWorkspaceReference, $e->reason);
            $this->assertContains($workspace->id, $e->candidateWorkspaceIds);
        }

        $business->workspace_id = null;
        $business->save();
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
