<?php

namespace Tests\Feature\Entitlement;

use App\Models\User;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanCatalogPricingChangeRepository;
use App\Repositories\Eloquent\EloquentWorkspacePlanCatalogPricingChangeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Append-only (RFC-004 Amendment 2 §8) — deliberately no update() method,
 * mirroring WorkspaceEntitlementTransitionRepositoryTest's exact precedent.
 */
class WorkspacePlanCatalogPricingChangeRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function coreCatalogId(): int
    {
        return (int) WorkspacePlanCatalog::where('tier', 'core')->value('id');
    }

    private function growthCatalogId(): int
    {
        return (int) WorkspacePlanCatalog::where('tier', 'growth')->value('id');
    }

    private function adminId(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentWorkspacePlanCatalogPricingChangeRepository::class,
            app(WorkspacePlanCatalogPricingChangeRepository::class)
        );
    }

    public function test_create_persists_and_casts_decimal_columns(): void
    {
        $repository = app(WorkspacePlanCatalogPricingChangeRepository::class);

        $row = $repository->create([
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'actor_user_id' => $this->adminId(),
            'reason' => 'Fixture.',
            'from_price' => null,
            'to_price' => '49.00',
            'from_currency_id' => null,
            'to_currency_id' => 1,
            'from_additional_business_slot_price_ratio' => null,
            'to_additional_business_slot_price_ratio' => '0.5000',
        ]);

        $this->assertSame($this->coreCatalogId(), $row->fresh()->workspace_plan_catalog_id);
        $this->assertSame('49.00', (string) $row->fresh()->getRawOriginal('to_price'));
        $this->assertSame('0.5000', (string) $row->fresh()->getRawOriginal('to_additional_business_slot_price_ratio'));
    }

    public function test_for_catalog_returns_only_that_catalogs_rows_in_order(): void
    {
        $repository = app(WorkspacePlanCatalogPricingChangeRepository::class);
        $coreId = $this->coreCatalogId();
        $growthId = $this->growthCatalogId();
        $adminId = $this->adminId();

        $repository->create([
            'workspace_plan_catalog_id' => $growthId,
            'actor_user_id' => $adminId,
            'reason' => 'Unrelated catalog.',
        ]);
        $first = $repository->create([
            'workspace_plan_catalog_id' => $coreId,
            'actor_user_id' => $adminId,
            'reason' => 'First.',
        ]);
        $second = $repository->create([
            'workspace_plan_catalog_id' => $coreId,
            'actor_user_id' => $adminId,
            'reason' => 'Second.',
        ]);

        $result = $repository->forCatalog($coreId);

        $this->assertCount(2, $result);
        $this->assertTrue($result->first()->is($first));
        $this->assertTrue($result->last()->is($second));
    }

    public function test_no_update_method_exists_on_this_repository(): void
    {
        $reflection = new ReflectionClass(EloquentWorkspacePlanCatalogPricingChangeRepository::class);

        $this->assertFalse($reflection->hasMethod('update'));
    }
}
