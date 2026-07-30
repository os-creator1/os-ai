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

    // (new for Slice 2B) WorkspaceManager exists but is not yet wired into
    // BusinessManager — the legacy onboarding path still resolves nothing
    // and still calls createForCustomer() directly (also proven
    // behaviorally by test_business_manager_still_calls_the_legacy_creation_path above).
    public function test_business_manager_does_not_yet_reference_the_workspace_resolver(): void
    {
        $source = file_get_contents(app_path('Library/Business/BusinessManager.php'));

        $this->assertStringNotContainsString('WorkspaceManager', $source);
        $this->assertStringNotContainsString('resolveLegacyOnboardingWorkspace', $source);
        $this->assertStringNotContainsString('createForCustomerInWorkspace', $source);
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
