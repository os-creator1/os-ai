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

    // 19. the existing production caller of createForCustomer() has not been replaced by a Workspace resolver.
    public function test_business_manager_still_calls_the_legacy_creation_path(): void
    {
        $customer = $this->createCustomer();
        $manager = app(BusinessManager::class);

        $business = $manager->createOrUpdateOnboardingBusiness($customer, null, $this->businessAttributes());

        // No Workspace resolver exists yet (§10.6/§13 are M1B), so a
        // Business created through the unmodified onboarding path still
        // has no workspace_id.
        $this->assertNull(DB::table('businesses')->where('id', $business->id)->value('workspace_id'));
    }

    // 20. WorkspaceManager does not exist.
    public function test_workspace_manager_does_not_exist(): void
    {
        $this->assertFalse(class_exists(\App\Library\Workspace\WorkspaceManager::class));
    }

    // 21 (updated for Slice 2A). WorkspaceContextRequiredException and its
    // closed WorkspaceContextFailureReason enum now exist — Slice 2A
    // explicitly introduces both. No resolver has been introduced to use
    // them yet: WorkspaceManager remains absent (also proven independently
    // by test_workspace_manager_does_not_exist above), so this asserts the
    // current M1B boundary rather than the earlier M1A one.
    public function test_workspace_context_exception_and_reason_enum_exist_with_no_resolver_yet(): void
    {
        $this->assertTrue(class_exists(\App\Exceptions\Workspace\WorkspaceContextRequiredException::class));
        $this->assertTrue(class_exists(\App\Enums\Workspace\WorkspaceContextFailureReason::class));
        $this->assertFalse(class_exists(\App\Library\Workspace\WorkspaceManager::class));
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
