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
 * Proves the temporary M1A architectural boundary is still intact: the
 * legacy Business-creation path is untouched, workspace_id is still
 * nullable with no enforcement, and M1B's WorkspaceManager/exception/
 * enforcement migration do not exist yet (RFC-003 §10.2, §10.6, §23).
 */
class LegacyBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    // 16. BusinessRepository::createForCustomer() still exists.
    public function test_business_repository_contract_still_declares_create_for_customer(): void
    {
        $this->assertTrue((new ReflectionClass(BusinessRepository::class))->hasMethod('createForCustomer'));
    }

    // 17. EloquentBusinessRepository still implements createForCustomer().
    public function test_eloquent_business_repository_still_implements_create_for_customer(): void
    {
        $method = (new ReflectionClass(EloquentBusinessRepository::class))->getMethod('createForCustomer');

        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isAbstract());
    }

    // 18. the current legacy Business-creation behavior can still create a Business with workspace_id = null.
    public function test_legacy_creation_path_still_produces_a_null_workspace_id(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($customer, $this->businessAttributes());

        $this->assertNull(DB::table('businesses')->where('id', $business->id)->value('workspace_id'));
    }

    // 19 (updated for Slice 3A). BusinessManager's legacy onboarding
    // creation branch is now integrated with WorkspaceManager (RFC-003
    // §13.1): a Business created through this path resolves and persists
    // a real Workspace instead of a null workspace_id.
    public function test_business_manager_now_resolves_a_workspace_for_the_legacy_creation_path(): void
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

    // 20 (updated for Slice 2B). WorkspaceManager now exists and exposes
    // the approved resolver signature — Slice 2B explicitly introduces it.
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

    // (updated for Slice 3A) BusinessManager is now wired to the Workspace
    // resolver: it references WorkspaceManager, calls
    // resolveLegacyOnboardingWorkspace() and createForCustomerInWorkspace()
    // in its creation branch, and no longer calls createForCustomer() at
    // all (also proven behaviorally by
    // test_business_manager_now_resolves_a_workspace_for_the_legacy_creation_path
    // above). Source-scanned rather than behaviorally proven because "does
    // not call a method" has no positive runtime signal to assert on.
    public function test_business_manager_now_references_the_workspace_resolver_and_no_longer_calls_create_for_customer(): void
    {
        $source = file_get_contents(app_path('Library/Business/BusinessManager.php'));

        $this->assertStringContainsString('WorkspaceManager', $source);
        $this->assertStringContainsString('resolveLegacyOnboardingWorkspace', $source);
        $this->assertStringContainsString('createForCustomerInWorkspace', $source);

        // Must not call the plain createForCustomer(...) form anywhere
        // (createForCustomerInWorkspace(...) legitimately contains the same
        // substring, so match on the call signature, not a bare substring).
        $this->assertDoesNotMatchRegularExpression('/->createForCustomer\(/', $source);
    }

    // (new for Slice 2B) createForCustomerInWorkspace() remains present on
    // the repository contract, alongside the still-unremoved createForCustomer().
    public function test_business_repository_contract_still_declares_create_for_customer_in_workspace(): void
    {
        $this->assertTrue(
            (new ReflectionClass(BusinessRepository::class))->hasMethod('createForCustomerInWorkspace')
        );
    }

    // 21 (updated for Slice 2A/2B). WorkspaceContextRequiredException and
    // its closed WorkspaceContextFailureReason enum exist — Slice 2A
    // introduced both. WorkspaceManager's own existence and BusinessManager's
    // non-integration are proven independently above.
    public function test_workspace_context_exception_and_reason_enum_exist(): void
    {
        $this->assertTrue(class_exists(\App\Exceptions\Workspace\WorkspaceContextRequiredException::class));
        $this->assertTrue(class_exists(\App\Enums\Workspace\WorkspaceContextFailureReason::class));
    }

    // (new for Slice 3A) no production code anywhere under app/ still calls
    // createForCustomer() — only its two declaration sites (the contract
    // and the Eloquent implementation) may reference the method name.
    public function test_no_production_code_still_calls_create_for_customer(): void
    {
        $allowedFiles = [
            app_path('Repositories/Contracts/BusinessRepository.php'),
            app_path('Repositories/Eloquent/EloquentBusinessRepository.php'),
        ];

        $offendingFiles = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (in_array($file, $allowedFiles, true)) {
                continue;
            }

            if (preg_match('/->createForCustomer\(/', file_get_contents($file)) === 1) {
                $offendingFiles[] = $file;
            }
        }

        $this->assertSame([], $offendingFiles, 'Unexpected createForCustomer() caller(s): ' . implode(', ', $offendingFiles));
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

    // 22. no M1B enforcement migration exists.
    public function test_no_enforcement_migration_exists(): void
    {
        $matches = glob(database_path('migrations/*enforce_business_workspace_constraint*'));

        $this->assertSame([], $matches);
    }

    // 23. businesses.workspace_id remains nullable with no FK and no final Workspace indexes.
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
