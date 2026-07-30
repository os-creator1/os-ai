<?php

namespace Tests\Feature\Workspace;

use App\Exceptions\Workspace\WorkspaceBackfillConflictException;
use App\Exceptions\Workspace\WorkspaceBackfillIncompleteException;
use App\Library\Workspace\Migration\WorkspaceBackfillResult;
use App\Library\Workspace\Migration\WorkspaceBackfillV1;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use RuntimeException;
use Tests\Feature\Workspace\Support\ConcurrentInsertWorkspaceBackfillV1;
use Tests\Feature\Workspace\Support\FailingWorkspaceBackfillV1;
use Tests\Feature\Workspace\Support\HistoricalWorkspaceTestCase;
use Tests\Feature\Workspace\Support\SmallPageWorkspaceBackfillV1;

#[Group('historical-m1a')]
class WorkspaceBackfillV1Test extends HistoricalWorkspaceTestCase
{
    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Legacy',
            'last_name' => 'Customer',
            'email' => 'legacy-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ], $overrides));
    }

    private function createNullWorkspaceBusiness(int $customerId, array $overrides = []): Business
    {
        return Business::create(array_merge([
            'customer_id' => $customerId,
            'name' => 'Legacy Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $overrides));
    }

    private function markPrimary(Business $business): Business
    {
        $business->is_primary = true;
        $business->save();

        return $business;
    }

    private function assignWorkspace(Business $business, int $workspaceId): Business
    {
        $business->workspace_id = $workspaceId;
        $business->save();

        return $business;
    }

    private function insertWorkspace(int $ownerUserId, array $overrides = []): int
    {
        return DB::table('workspaces')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'name' => 'Existing Workspace',
            'owner_user_id' => $ownerUserId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // 1. run with no Businesses succeeds and returns zero counters.
    public function test_run_with_no_businesses_succeeds_with_zero_counters(): void
    {
        $result = (new WorkspaceBackfillV1())->run();

        $this->assertSame(0, $result->groupsProcessed);
        $this->assertSame(0, $result->workspacesCreated);
        $this->assertSame(0, $result->workspacesReused);
        $this->assertSame(0, $result->businessesAssigned);
        $this->assertSame(0, $result->remainingNullCount);
    }

    // 2. one customer with one Business creates one Workspace and assigns it.
    public function test_single_customer_single_business_creates_and_assigns_one_workspace(): void
    {
        $user = $this->createUser();
        $business = $this->createNullWorkspaceBusiness($user->id);

        $result = (new WorkspaceBackfillV1())->run();

        $this->assertSame(1, $result->groupsProcessed);
        $this->assertSame(1, $result->workspacesCreated);
        $this->assertSame(0, $result->workspacesReused);
        $this->assertSame(1, $result->businessesAssigned);
        $this->assertSame(0, $result->remainingNullCount);

        $workspaceId = DB::table('businesses')->where('id', $business->id)->value('workspace_id');
        $this->assertNotNull($workspaceId);
        $this->assertSame($user->id, DB::table('workspaces')->where('id', $workspaceId)->value('owner_user_id'));
    }

    // 3. multiple Businesses sharing customer_id receive one Workspace.
    public function test_multiple_businesses_sharing_customer_id_receive_one_workspace(): void
    {
        $user = $this->createUser();
        $businessA = $this->createNullWorkspaceBusiness($user->id, ['name' => 'Business A']);
        $businessB = $this->createNullWorkspaceBusiness($user->id, ['name' => 'Business B']);

        $result = (new WorkspaceBackfillV1())->run();

        $this->assertSame(1, $result->groupsProcessed);
        $this->assertSame(1, $result->workspacesCreated);
        $this->assertSame(2, $result->businessesAssigned);

        $workspaceIdA = DB::table('businesses')->where('id', $businessA->id)->value('workspace_id');
        $workspaceIdB = DB::table('businesses')->where('id', $businessB->id)->value('workspace_id');

        $this->assertNotNull($workspaceIdA);
        $this->assertSame($workspaceIdA, $workspaceIdB);
    }

    // 4. customers.company wins name resolution.
    public function test_customers_company_wins_name_resolution(): void
    {
        $user = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        Customer::create(['user_id' => $user->id, 'company' => 'Acme Corp']);
        $business = $this->markPrimary($this->createNullWorkspaceBusiness($user->id, ['name' => 'Some Business']));

        (new WorkspaceBackfillV1())->run();

        $workspaceId = DB::table('businesses')->where('id', $business->id)->value('workspace_id');
        $this->assertSame('Acme Corp', DB::table('workspaces')->where('id', $workspaceId)->value('name'));
    }

    // 5. primary Business name is the second fallback.
    public function test_primary_business_name_is_second_fallback(): void
    {
        $user = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        Customer::create(['user_id' => $user->id, 'company' => null]);
        $primary = $this->markPrimary($this->createNullWorkspaceBusiness($user->id, ['name' => 'Primary Biz']));
        $this->createNullWorkspaceBusiness($user->id, ['name' => 'Other Biz']);

        (new WorkspaceBackfillV1())->run();

        $workspaceId = DB::table('businesses')->where('id', $primary->id)->value('workspace_id');
        $this->assertSame('Primary Biz', DB::table('workspaces')->where('id', $workspaceId)->value('name'));
    }

    // 6. first Business by ID is the third fallback.
    public function test_first_business_by_id_is_third_fallback(): void
    {
        $user = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        Customer::create(['user_id' => $user->id, 'company' => null]);
        $first = $this->createNullWorkspaceBusiness($user->id, ['name' => 'First Biz']);
        $this->createNullWorkspaceBusiness($user->id, ['name' => 'Second Biz']);

        (new WorkspaceBackfillV1())->run();

        $workspaceId = DB::table('businesses')->where('id', $first->id)->value('workspace_id');
        $this->assertSame('First Biz', DB::table('workspaces')->where('id', $workspaceId)->value('name'));
    }

    // 7. raw first/last name fallback is used when all business/company names are blank.
    public function test_raw_first_last_name_fallback_when_business_and_company_names_blank(): void
    {
        $user = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);
        Customer::create(['user_id' => $user->id, 'company' => '   ']);
        $business = $this->createNullWorkspaceBusiness($user->id, ['name' => '   ']);

        (new WorkspaceBackfillV1())->run();

        $workspaceId = DB::table('businesses')->where('id', $business->id)->value('workspace_id');
        $this->assertSame("Jane Doe's Workspace", DB::table('workspaces')->where('id', $workspaceId)->value('name'));
    }

    // 8. fully blank user-name fallback produces the documented deterministic non-empty name.
    public function test_fully_blank_user_name_fallback_produces_deterministic_name(): void
    {
        $user = $this->createUser(['first_name' => '', 'last_name' => '']);
        Customer::create(['user_id' => $user->id, 'company' => null]);
        $business = $this->createNullWorkspaceBusiness($user->id, ['name' => '']);

        (new WorkspaceBackfillV1())->run();

        $workspaceId = DB::table('businesses')->where('id', $business->id)->value('workspace_id');
        $name = DB::table('workspaces')->where('id', $workspaceId)->value('name');

        $this->assertSame("Customer #{$user->id}'s Workspace", $name);
        $this->assertNotSame('', trim($name));
    }

    // 9. generated Workspace uid is a valid UUID.
    public function test_generated_workspace_uid_is_a_valid_uuid(): void
    {
        $user = $this->createUser();
        $business = $this->createNullWorkspaceBusiness($user->id);

        (new WorkspaceBackfillV1())->run();

        $workspaceId = DB::table('businesses')->where('id', $business->id)->value('workspace_id');
        $uid = DB::table('workspaces')->where('id', $workspaceId)->value('uid');

        $this->assertTrue(Str::isUuid($uid));
    }

    // 10. customer_id is unchanged.
    public function test_customer_id_is_unchanged(): void
    {
        $user = $this->createUser();
        $business = $this->createNullWorkspaceBusiness($user->id);

        (new WorkspaceBackfillV1())->run();

        $this->assertSame($user->id, DB::table('businesses')->where('id', $business->id)->value('customer_id'));
    }

    // 11. existing one-Workspace partial assignment is reused.
    public function test_existing_partial_assignment_reuses_the_same_workspace(): void
    {
        $user = $this->createUser();
        $assigned = $this->createNullWorkspaceBusiness($user->id, ['name' => 'Assigned Biz']);
        $unassigned = $this->createNullWorkspaceBusiness($user->id, ['name' => 'Unassigned Biz']);
        $workspaceId = $this->insertWorkspace($user->id);
        $this->assignWorkspace($assigned, $workspaceId);

        $result = (new WorkspaceBackfillV1())->run();

        $this->assertSame(1, $result->groupsProcessed);
        $this->assertSame(0, $result->workspacesCreated);
        $this->assertSame(1, $result->workspacesReused);
        $this->assertSame(1, $result->businessesAssigned);

        $this->assertSame($workspaceId, DB::table('businesses')->where('id', $unassigned->id)->value('workspace_id'));
        $this->assertSame(1, DB::table('workspaces')->where('owner_user_id', $user->id)->count());
    }

    // 12. only previously-null Businesses count as assigned.
    public function test_only_previously_null_businesses_count_as_assigned(): void
    {
        $user = $this->createUser();
        $alreadyAssignedA = $this->createNullWorkspaceBusiness($user->id, ['name' => 'A']);
        $alreadyAssignedB = $this->createNullWorkspaceBusiness($user->id, ['name' => 'B']);
        $stillNull = $this->createNullWorkspaceBusiness($user->id, ['name' => 'C']);

        $workspaceId = $this->insertWorkspace($user->id);
        $this->assignWorkspace($alreadyAssignedA, $workspaceId);
        $this->assignWorkspace($alreadyAssignedB, $workspaceId);

        $result = (new WorkspaceBackfillV1())->run();

        $this->assertSame(1, $result->businessesAssigned);
        $this->assertSame($workspaceId, DB::table('businesses')->where('id', $stillNull->id)->value('workspace_id'));
    }

    // 13. a second run creates no duplicate Workspace and performs no additional assignment.
    public function test_second_run_creates_no_duplicate_and_assigns_nothing_more(): void
    {
        $user = $this->createUser();
        $this->createNullWorkspaceBusiness($user->id);

        $first = (new WorkspaceBackfillV1())->run();
        $second = (new WorkspaceBackfillV1())->run();

        $this->assertSame(1, $first->workspacesCreated);
        $this->assertSame(0, $second->groupsProcessed);
        $this->assertSame(0, $second->workspacesCreated);
        $this->assertSame(0, $second->businessesAssigned);
        $this->assertSame(1, DB::table('workspaces')->where('owner_user_id', $user->id)->count());
    }

    // 14. conflicting existing Workspace IDs throw WorkspaceBackfillConflictException.
    public function test_conflicting_existing_workspace_ids_throw_conflict_exception(): void
    {
        $user = $this->createUser();
        $businessA = $this->createNullWorkspaceBusiness($user->id, ['name' => 'A']);
        $businessB = $this->createNullWorkspaceBusiness($user->id, ['name' => 'B']);
        $this->createNullWorkspaceBusiness($user->id, ['name' => 'C']); // stays null, triggers group selection

        $workspaceIdA = $this->insertWorkspace($user->id);
        $workspaceIdB = $this->insertWorkspace($user->id);
        $this->assignWorkspace($businessA, $workspaceIdA);
        $this->assignWorkspace($businessB, $workspaceIdB);

        $this->expectException(WorkspaceBackfillConflictException::class);
        (new WorkspaceBackfillV1())->run();
    }

    // 15. conflict exception exposes the customer ID and conflicting Workspace IDs.
    public function test_conflict_exception_exposes_customer_id_and_conflicting_workspace_ids(): void
    {
        $user = $this->createUser();
        $businessA = $this->createNullWorkspaceBusiness($user->id, ['name' => 'A']);
        $businessB = $this->createNullWorkspaceBusiness($user->id, ['name' => 'B']);
        $this->createNullWorkspaceBusiness($user->id, ['name' => 'C']);

        $workspaceIdA = $this->insertWorkspace($user->id);
        $workspaceIdB = $this->insertWorkspace($user->id);
        $this->assignWorkspace($businessA, $workspaceIdA);
        $this->assignWorkspace($businessB, $workspaceIdB);

        try {
            (new WorkspaceBackfillV1())->run();
            $this->fail('Expected WorkspaceBackfillConflictException was not thrown.');
        } catch (WorkspaceBackfillConflictException $e) {
            $this->assertSame($user->id, $e->customerId);
            $this->assertEqualsCanonicalizing([$workspaceIdA, $workspaceIdB], $e->conflictingWorkspaceIds);
        }
    }

    // 16. a failure after Workspace insertion but before Business assignment rolls back the Workspace and assignments.
    public function test_failure_after_workspace_insert_rolls_back_workspace_and_assignments(): void
    {
        $user = $this->createUser();
        $business = $this->createNullWorkspaceBusiness($user->id);

        $action = new FailingWorkspaceBackfillV1();

        try {
            $action->run();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            // expected forced failure
        }

        $this->assertSame(0, DB::table('workspaces')->where('owner_user_id', $user->id)->count());
        $this->assertNull(DB::table('businesses')->where('id', $business->id)->value('workspace_id'));
    }

    // 17. retry after a forced transactional failure creates exactly one Workspace.
    public function test_retry_after_forced_failure_creates_exactly_one_workspace(): void
    {
        $user = $this->createUser();
        $business = $this->createNullWorkspaceBusiness($user->id);

        $failingAction = new FailingWorkspaceBackfillV1();

        try {
            $failingAction->run();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            // expected
        }

        $result = (new WorkspaceBackfillV1())->run();

        $this->assertSame(1, $result->workspacesCreated);
        $this->assertSame(1, DB::table('workspaces')->where('owner_user_id', $user->id)->count());
        $this->assertNotNull(DB::table('businesses')->where('id', $business->id)->value('workspace_id'));
    }

    // 18. earlier committed groups remain committed when a later group conflicts.
    public function test_earlier_committed_groups_remain_committed_when_a_later_group_conflicts(): void
    {
        $goodUser = $this->createUser();
        $goodBusiness = $this->createNullWorkspaceBusiness($goodUser->id);

        $badUser = $this->createUser();
        $badBusinessA = $this->createNullWorkspaceBusiness($badUser->id, ['name' => 'Bad A']);
        $badBusinessB = $this->createNullWorkspaceBusiness($badUser->id, ['name' => 'Bad B']);
        $this->createNullWorkspaceBusiness($badUser->id, ['name' => 'Bad C']);

        $wsIdA = $this->insertWorkspace($badUser->id);
        $wsIdB = $this->insertWorkspace($badUser->id);
        $this->assignWorkspace($badBusinessA, $wsIdA);
        $this->assignWorkspace($badBusinessB, $wsIdB);

        try {
            (new WorkspaceBackfillV1())->run();
            $this->fail('Expected WorkspaceBackfillConflictException was not thrown.');
        } catch (WorkspaceBackfillConflictException $e) {
            // expected
        }

        $this->assertNotNull(DB::table('businesses')->where('id', $goodBusiness->id)->value('workspace_id'));
        $this->assertSame(1, DB::table('workspaces')->where('owner_user_id', $goodUser->id)->count());
    }

    // 19. more customer groups than one internal page are processed correctly.
    public function test_more_customer_groups_than_one_page_are_processed_correctly(): void
    {
        $users = [];

        for ($i = 0; $i < 5; $i++) {
            $user = $this->createUser();
            $this->createNullWorkspaceBusiness($user->id);
            $users[] = $user;
        }

        // SmallPageWorkspaceBackfillV1::CHUNK_SIZE === 2, so 5 groups span 3 internal pages.
        $result = (new SmallPageWorkspaceBackfillV1())->run();

        $this->assertSame(5, $result->groupsProcessed);
        $this->assertSame(5, $result->workspacesCreated);
        $this->assertSame(0, $result->remainingNullCount);

        foreach ($users as $user) {
            $this->assertSame(1, DB::table('workspaces')->where('owner_user_id', $user->id)->count());
        }
    }

    // 20. no Eloquent model creating/saving events fire during the action.
    public function test_no_eloquent_model_events_fire_during_the_action(): void
    {
        $user = $this->createUser();
        $this->createNullWorkspaceBusiness($user->id);

        $fired = [];
        $recordFiredEvent = function (string $eventName) use (&$fired): void {
            $fired[] = $eventName;
        };

        Event::listen('eloquent.creating: *', $recordFiredEvent);
        Event::listen('eloquent.saving: *', $recordFiredEvent);
        Event::listen('eloquent.created: *', $recordFiredEvent);
        Event::listen('eloquent.saved: *', $recordFiredEvent);
        Event::listen('eloquent.updating: *', $recordFiredEvent);
        Event::listen('eloquent.updated: *', $recordFiredEvent);

        (new WorkspaceBackfillV1())->run();

        $this->assertSame([], $fired);
    }

    // 21. final non-zero null count throws WorkspaceBackfillIncompleteException.
    public function test_final_non_zero_null_count_throws_incomplete_exception(): void
    {
        $user = $this->createUser();
        $this->createNullWorkspaceBusiness($user->id);

        $this->expectException(WorkspaceBackfillIncompleteException::class);
        (new ConcurrentInsertWorkspaceBackfillV1())->run();
    }

    // 22. incomplete exception exposes the remaining count.
    public function test_incomplete_exception_exposes_remaining_count(): void
    {
        $user = $this->createUser();
        $this->createNullWorkspaceBusiness($user->id);

        try {
            (new ConcurrentInsertWorkspaceBackfillV1())->run();
            $this->fail('Expected WorkspaceBackfillIncompleteException was not thrown.');
        } catch (WorkspaceBackfillIncompleteException $e) {
            $this->assertSame(1, $e->remainingNullCount);
        }
    }

    // 23. returned WorkspaceBackfillResult counters are exact.
    public function test_result_counters_are_exact_across_mixed_groups(): void
    {
        $userA = $this->createUser();
        $this->createNullWorkspaceBusiness($userA->id);

        $userB = $this->createUser();
        $this->createNullWorkspaceBusiness($userB->id, ['name' => 'B1']);
        $this->createNullWorkspaceBusiness($userB->id, ['name' => 'B2']);

        $userC = $this->createUser();
        $existingAssigned = $this->createNullWorkspaceBusiness($userC->id, ['name' => 'C-assigned']);
        $this->createNullWorkspaceBusiness($userC->id, ['name' => 'C-null']);
        $workspaceId = $this->insertWorkspace($userC->id);
        $this->assignWorkspace($existingAssigned, $workspaceId);

        $result = (new WorkspaceBackfillV1())->run();

        $this->assertSame(3, $result->groupsProcessed);
        $this->assertSame(2, $result->workspacesCreated);
        $this->assertSame(1, $result->workspacesReused);
        $this->assertSame(4, $result->businessesAssigned);
        $this->assertSame(0, $result->remainingNullCount);
    }

    // 24. result object is immutable/readonly as designed.
    public function test_result_object_is_readonly(): void
    {
        $reflection = new ReflectionClass(WorkspaceBackfillResult::class);

        $this->assertTrue($reflection->isReadOnly());
    }
}
