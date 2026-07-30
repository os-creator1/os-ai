<?php

namespace Tests\Feature\Workspace;

use App\Library\Business\BusinessManager;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Eloquent\EloquentBusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Proves the post-Slice-3B, pre-migration-6 architectural boundary (RFC-003
 * §10.6, §12.4, §23): the legacy createForCustomer() write path has been
 * removed entirely (no declaration, no implementation, no caller anywhere
 * in app/ or tests/), createForCustomerInWorkspace() is the sole supported
 * creation method, BusinessManager's legacy onboarding branch uses it via
 * WorkspaceManager's resolver, and businesses.workspace_id is still
 * nullable with no FK/indexes/enforcement — that final step is Slice 4.
 * Historical M1A backfill tests (WorkspaceBackfillV1Test,
 * WorkspaceBackfillMigrationTest, BackfillWorkspacesCommandTest,
 * WorkspaceBackfillV1ConcurrencyTest) intentionally continue constructing
 * null-workspace_id Business rows directly — they are out of this file's
 * scope and are not converted.
 */
class LegacyBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    // 5/6. createForCustomer() is declared on neither the contract nor the
    // Eloquent implementation (RFC-003 §10.6 step 2, §12.4).
    public function test_create_for_customer_is_absent_from_contract_and_implementation(): void
    {
        $this->assertFalse((new ReflectionClass(BusinessRepository::class))->hasMethod('createForCustomer'));
        $this->assertFalse((new ReflectionClass(EloquentBusinessRepository::class))->hasMethod('createForCustomer'));
        $this->assertFalse(method_exists(app(BusinessRepository::class), 'createForCustomer'));
    }

    // 7. createForCustomerInWorkspace() remains declared and implemented —
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
        $this->assertTrue(class_exists(\App\Library\Workspace\WorkspaceManager::class));

        $method = (new ReflectionClass(\App\Library\Workspace\WorkspaceManager::class))
            ->getMethod('resolveLegacyOnboardingWorkspace');

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

    // 4/8. No source file anywhere under app/ or tests/ still calls or
    // declares createForCustomer() — not a production caller, not a test
    // fixture, not a mock expectation, not an anonymous-class override.
    // Matches only real call/declaration syntax (->, ::, or a function
    // declaration immediately followed by '('), so createForCustomerInWorkspace(
    // calls and prose comments/strings naming the old method for
    // documentation or reflection purposes never false-positive here.
    public function test_no_source_file_still_calls_or_declares_create_for_customer(): void
    {
        $allowedFiles = [
            app_path('Repositories/Contracts/BusinessRepository.php'),
            app_path('Repositories/Eloquent/EloquentBusinessRepository.php'),
        ];

        $offendingFiles = [];
        $pattern = '/(->|::|function\s+)createForCustomer\(/';

        foreach ([...$this->phpFilesUnder(app_path()), ...$this->phpFilesUnder(base_path('tests'))] as $file) {
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

    // 11. no M1B enforcement migration exists yet (Slice 4).
    public function test_no_enforcement_migration_exists(): void
    {
        $matches = glob(database_path('migrations/*enforce_business_workspace_constraint*'));

        $this->assertSame([], $matches);
    }

    // 9/10. businesses.workspace_id remains nullable with no FK and no final Workspace indexes.
    public function test_businesses_workspace_id_remains_nullable_with_no_fk_or_final_indexes(): void
    {
        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id'"
        );
        $this->assertSame('YES', $column->IS_NULLABLE);

        $constraints = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        $this->assertCount(0, $constraints);

        $indexes = DB::select(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses'
             AND COLUMN_NAME = 'workspace_id'"
        );
        $this->assertCount(0, $indexes);
    }
}
