<?php

namespace Tests\Feature\Workspace;

use App\Library\Business\BusinessManager;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Eloquent\EloquentBusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Proves the final, permanent post-M1B architectural boundary (RFC-003
 * §10.6, §10.7, §12.4, §23) — renamed from LegacyBoundaryTest since
 * "legacy" described the temporary M1A/M1B transition this file no longer
 * protects. This is now the boundary before Milestone 2: WorkspaceManager
 * ships only its one narrow resolver, createForCustomer() is gone
 * everywhere, businesses.workspace_id is permanently enforced, and no
 * plans/billing/entitlement/wallet work exists.
 */
class WorkspaceM1BBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    // 4 (contract/implementation half). createForCustomer() is declared on
    // neither the contract nor the Eloquent implementation.
    public function test_create_for_customer_is_absent_from_contract_and_implementation(): void
    {
        $this->assertFalse((new ReflectionClass(BusinessRepository::class))->hasMethod('createForCustomer'));
        $this->assertFalse((new ReflectionClass(EloquentBusinessRepository::class))->hasMethod('createForCustomer'));
        $this->assertFalse(method_exists(app(BusinessRepository::class), 'createForCustomer'));
    }

    // 5. createForCustomerInWorkspace() remains declared and implemented —
    // the sole supported creation method from Slice 3B onward.
    public function test_create_for_customer_in_workspace_remains_declared_and_implemented(): void
    {
        $this->assertTrue(
            (new ReflectionClass(BusinessRepository::class))->hasMethod('createForCustomerInWorkspace')
        );

        $method = (new ReflectionClass(EloquentBusinessRepository::class))->getMethod('createForCustomerInWorkspace');
        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isAbstract());
    }

    // 2/3. BusinessManager's legacy onboarding creation branch resolves and
    // persists a real Workspace via WorkspaceManager + createForCustomerInWorkspace().
    public function test_business_manager_resolves_and_persists_a_workspace_for_the_legacy_creation_path(): void
    {
        $customer = $this->createCustomer();
        $manager = app(BusinessManager::class);

        $business = $manager->createOrUpdateOnboardingBusiness($customer, null, $this->businessAttributes());

        $workspaceId = DB::table('businesses')->where('id', $business->id)->value('workspace_id');
        $this->assertNotNull($workspaceId);
        $this->assertSame(
            $workspaceId,
            DB::table('workspaces')->where('owner_user_id', $customer->user_id)->value('id')
        );
    }

    // 1. WorkspaceManager exists and exposes the approved resolver signature.
    public function test_workspace_manager_exists_with_the_approved_resolver_signature(): void
    {
        $this->assertTrue(class_exists(WorkspaceManager::class));

        $method = (new ReflectionClass(WorkspaceManager::class))->getMethod('resolveLegacyOnboardingWorkspace');

        $this->assertTrue($method->isPublic());

        $parameters = $method->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertSame('ownerUserId', $parameters[0]->getName());
        $this->assertSame('int', (string) $parameters[0]->getType());
        $this->assertSame(\App\Models\Workspace::class, (string) $method->getReturnType());
    }

    // 2/3 (source-scanned). BusinessManager references WorkspaceManager and
    // calls both resolveLegacyOnboardingWorkspace() and
    // createForCustomerInWorkspace() — behaviorally proven above too.
    public function test_business_manager_source_references_the_workspace_resolver_and_the_workspace_scoped_creator(): void
    {
        $source = file_get_contents(app_path('Library/Business/BusinessManager.php'));

        $this->assertStringContainsString('WorkspaceManager', $source);
        $this->assertStringContainsString('resolveLegacyOnboardingWorkspace', $source);
        $this->assertStringContainsString('createForCustomerInWorkspace', $source);
    }

    public function test_workspace_context_exception_and_reason_enum_exist(): void
    {
        $this->assertTrue(class_exists(\App\Exceptions\Workspace\WorkspaceContextRequiredException::class));
        $this->assertTrue(class_exists(\App\Enums\Workspace\WorkspaceContextFailureReason::class));
    }

    // 4 (source-scan half). No source file anywhere under app/, tests/,
    // database/ or routes/ still calls or declares createForCustomer() —
    // not a production caller, not a test fixture, not a mock expectation,
    // not an anonymous-class override, not a migration or route closure.
    // Matches only real call/declaration syntax (->, ::, or a function
    // declaration immediately followed by '('), so
    // createForCustomerInWorkspace( calls and prose comments/strings
    // naming the old method for documentation or reflection purposes
    // never false-positive here.
    public function test_no_source_file_still_calls_or_declares_create_for_customer(): void
    {
        $allowedFiles = [
            app_path('Repositories/Contracts/BusinessRepository.php'),
            app_path('Repositories/Eloquent/EloquentBusinessRepository.php'),
        ];

        $offendingFiles = [];
        $pattern = '/(->|::|function\s+)createForCustomer\(/';

        $directories = [app_path(), base_path('tests'), database_path(), base_path('routes')];
        $files = [];

        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                $files = [...$files, ...$this->phpFilesUnder($directory)];
            }
        }

        foreach ($files as $file) {
            if (in_array($file, $allowedFiles, true)) {
                continue;
            }

            if (preg_match($pattern, file_get_contents($file)) === 1) {
                $offendingFiles[] = $file;
            }
        }

        $this->assertSame([], $offendingFiles, 'Unexpected createForCustomer() caller/declaration site(s): ' . implode(', ', $offendingFiles));
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }

    // 10. migration 6 exists at the exact expected path.
    public function test_enforcement_migration_exists_at_the_expected_path(): void
    {
        $this->assertFileExists(database_path('migrations/2026_07_30_120006_enforce_business_workspace_constraint.php'));
    }

    // 6/7/8/9. businesses.workspace_id is NOT NULL, both final indexes
    // exist, and the final FK exists with RESTRICT.
    public function test_businesses_workspace_id_is_permanently_enforced(): void
    {
        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id'"
        );
        $this->assertSame('NO', $column->IS_NULLABLE);

        $indexes = DB::select(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND INDEX_NAME IN ('businesses_workspace_id_index', 'businesses_workspace_id_status_index')"
        );
        $this->assertCount(2, $indexes);

        $constraint = DB::selectOne(
            "SELECT REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id' AND CONSTRAINT_NAME = 'businesses_workspace_id_foreign'"
        );
        $this->assertNotNull($constraint);
        $this->assertSame('workspaces', $constraint->REFERENCED_TABLE_NAME);
        $this->assertSame('id', $constraint->REFERENCED_COLUMN_NAME);

        $deleteRule = DB::selectOne(
            "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND CONSTRAINT_NAME = 'businesses_workspace_id_foreign'"
        );
        $this->assertSame('RESTRICT', $deleteRule->DELETE_RULE);
    }

    // 11. the four historical classes still exist and carry Group('historical-m1a').
    public function test_historical_classes_still_exist_and_carry_the_historical_group(): void
    {
        $this->assertClassCarriesGroup(WorkspaceBackfillV1Test::class, 'historical-m1a');
        $this->assertClassCarriesGroup(WorkspaceBackfillMigrationTest::class, 'historical-m1a');
        $this->assertClassCarriesGroup(BackfillWorkspacesCommandTest::class, 'historical-m1a');
        $this->assertClassCarriesGroup(WorkspaceBackfillV1ConcurrencyTest::class, 'historical-m1a');
    }

    // 12. WorkspaceEnforcementMigrationTest carries Group('workspace-enforcement').
    public function test_enforcement_migration_test_carries_the_enforcement_group(): void
    {
        $this->assertClassCarriesGroup(WorkspaceEnforcementMigrationTest::class, 'workspace-enforcement');
    }

    // (Slice 4B correction) WorkspaceManagerPreEnforcementTest exists and
    // carries Group('workspace-pre-enforcement') — the three naming-tier
    // tests split out of WorkspaceManagerTest because they genuinely need
    // a Business with workspace_id = null.
    public function test_pre_enforcement_workspace_manager_test_exists_and_carries_the_pre_enforcement_group(): void
    {
        $this->assertClassCarriesGroup(WorkspaceManagerPreEnforcementTest::class, 'workspace-pre-enforcement');
    }

    // (Slice 4B correction) WorkspaceManagerTest itself is not tagged as
    // pre-enforcement — it runs in the normal suite, against the final
    // enforced schema.
    public function test_final_schema_workspace_manager_test_is_not_tagged_pre_enforcement(): void
    {
        $attributes = (new ReflectionClass(WorkspaceManagerTest::class))->getAttributes(Group::class);
        $names = array_map(fn ($attribute) => $attribute->newInstance()->name(), $attributes);

        $this->assertNotContains('workspace-pre-enforcement', $names);
        $this->assertNotContains('historical-m1a', $names);
        $this->assertNotContains('workspace-enforcement', $names);
    }

    private function assertClassCarriesGroup(string $class, string $groupName): void
    {
        $this->assertTrue(class_exists($class), "Expected class {$class} to exist.");

        $attributes = (new ReflectionClass($class))->getAttributes(Group::class);
        $this->assertNotEmpty($attributes, "Expected {$class} to carry at least one #[Group] attribute.");

        $names = array_map(fn ($attribute) => $attribute->newInstance()->name(), $attributes);
        $this->assertContains($groupName, $names, "Expected {$class} to carry #[Group('{$groupName}')].");
    }

    // 13 (extended). phpunit.xml excludes all three isolated groups by default.
    public function test_phpunit_configuration_excludes_all_isolated_groups_by_default(): void
    {
        $xml = simplexml_load_file(base_path('phpunit.xml'));
        $excludedGroups = [];

        foreach ($xml->groups->exclude->group as $group) {
            $excludedGroups[] = (string) $group;
        }

        $this->assertContains('historical-m1a', $excludedGroups);
        $this->assertContains('workspace-pre-enforcement', $excludedGroups);
        $this->assertContains('workspace-enforcement', $excludedGroups);
    }

    // (Slice 4B correction) the isolated pre-enforcement runner explicitly
    // selects the workspace-pre-enforcement group alongside historical-m1a
    // — source-scanned because "which CLI flag a shell-invoked child
    // process receives" has no other behavioral signal this test suite can
    // observe without itself spawning that process.
    public function test_historical_runner_selects_the_pre_enforcement_group(): void
    {
        $source = file_get_contents(base_path('tests/Feature/Workspace/Support/run_historical_m1a_suite.php'));

        $this->assertMatchesRegularExpression(
            "/'--group',\s*'historical-m1a',[\s\S]*?'--group',\s*'workspace-pre-enforcement'/",
            $source
        );
    }

    // (Slice 4B correction) the normal final-schema suite cannot insert a
    // Business with a null workspace_id — behavioral proof, not just a
    // source/reflection check, that the NOT NULL constraint from migration
    // 6 is genuinely active on the database this test class itself runs
    // against.
    public function test_normal_suite_cannot_insert_a_business_with_null_workspace_id(): void
    {
        $customer = $this->createCustomer();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('businesses')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'customer_id' => $customer->user_id,
            'name' => 'No Workspace',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'status' => 'draft',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // 14. no Milestone-2 effective-access/lifecycle implementation has been
    // added — WorkspaceManager still ships only its one narrow M1B
    // resolver (RFC-003 §13).
    // RFC-003 Milestone 2 Slice 2B adds exactly the read-side effective-
    // access methods (userCanAccessBusiness()/assertUserCanAccessBusiness())
    // — this boundary is updated to admit only those two, and still fails
    // the moment any other Milestone 2 method (lifecycle, membership,
    // scope, Business orchestration, ownership transfer) appears ahead of
    // its own approved slice.
    // RFC-003 Milestone 2 Slice 2C adds exactly the Workspace lifecycle
    // methods (createWorkspace(), renameWorkspace(), deactivateWorkspace(),
    // reactivateWorkspace()) — this boundary is updated to admit those
    // four in addition to Slice 2B's two, and still fails the moment any
    // other Milestone 2 method (membership lifecycle, Business-access
    // scope mutation, Business orchestration, ownership transfer) appears
    // ahead of its own approved slice.
    // RFC-003 Milestone 2 Slice 2D adds exactly the membership lifecycle
    // methods (addMember(), changeMemberRole(), deactivateMember(),
    // reactivateMember()) — this boundary is updated to admit those four
    // in addition to Slice 2B's two and Slice 2C's four, and still fails
    // the moment any other Milestone 2 method (Business-access scope
    // mutation, Business orchestration, ownership transfer) appears ahead
    // of its own approved slice.
    // RFC-003 Milestone 2 Slice 2E adds exactly the scoped Business-access
    // methods (changeMemberBusinessAccessScope(), assignBusinessToMember(),
    // unassignBusinessFromMember()) — this boundary is updated to admit
    // those three in addition to Slice 2B's two, Slice 2C's four and
    // Slice 2D's four, and still fails the moment any other Milestone 2
    // method (Business creation/reassignment, ownership transfer) appears
    // ahead of its own approved slice.
    // RFC-003 Milestone 2 Slice 2F adds exactly the Business-orchestration
    // methods (createBusinessInWorkspace(), reassignBusiness()) — this
    // boundary is updated to admit those two in addition to Slice 2B's
    // two, Slice 2C's four, Slice 2D's four and Slice 2E's three, and
    // still fails the moment ownership transfer (transferOwnership())
    // appears ahead of its own approved slice.
    public function test_workspace_manager_has_no_unapproved_milestone_2_methods(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            (new ReflectionClass(WorkspaceManager::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );

        $this->assertEqualsCanonicalizing([
            '__construct',
            'resolveLegacyOnboardingWorkspace',
            'userCanAccessBusiness',
            'assertUserCanAccessBusiness',
            'createWorkspace',
            'renameWorkspace',
            'deactivateWorkspace',
            'reactivateWorkspace',
            'addMember',
            'changeMemberRole',
            'deactivateMember',
            'reactivateMember',
            'changeMemberBusinessAccessScope',
            'assignBusinessToMember',
            'unassignBusinessFromMember',
            'createBusinessInWorkspace',
            'reassignBusiness',
        ], $methods);
    }

    // 15. no plans, billing, entitlements, wallet or Stripe work has been
    // introduced by RFC-003 M1B (RFC-003 §4, §26). Scoped to files whose
    // name combines "Workspace" with a billing/entitlement term — the
    // RFC-004/RFC-005 concepts this RFC explicitly defers — rather than a
    // blanket scan of app/ for those terms alone: Ultimate SMS already has
    // long-standing, wholly unrelated SMS sending "Plan" functionality
    // (App\Models\Plan, Admin\PlanController, etc.) that predates RFC-003
    // entirely and is not part of what this check is meant to catch.
    public function test_no_rfc004_or_rfc005_concepts_exist_yet(): void
    {
        $forbiddenTerms = ['Plan', 'Entitlement', 'Wallet', 'Ledger', 'Stripe'];
        $offending = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $basename = basename($file, '.php');

            if (! str_contains($basename, 'Workspace')) {
                continue;
            }

            foreach ($forbiddenTerms as $term) {
                if (str_contains($basename, $term)) {
                    $offending[] = $file;
                }
            }
        }

        $this->assertSame([], $offending, 'Unexpected RFC-004/RFC-005 Workspace concept file(s): ' . implode(', ', $offending));
    }

    public function test_no_enforcement_migration_named_differently_exists(): void
    {
        $matches = glob(database_path('migrations/*enforce_business_workspace_constraint*'));

        $this->assertCount(1, $matches);
    }
}
