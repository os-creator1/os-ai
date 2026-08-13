<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use App\Repositories\Eloquent\EloquentWorkspacePlanCatalogRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspacePlanCatalogRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentWorkspacePlanCatalogRepository::class,
            app(WorkspacePlanCatalogRepository::class)
        );
    }

    public function test_create_persists_a_new_catalog_row(): void
    {
        $repository = app(WorkspacePlanCatalogRepository::class);

        $catalog = $repository->create([
            'tier' => 'growth_pilot_' . uniqid(),
            'display_name' => 'Growth Pilot',
            'business_slot_included' => 3,
            'business_slot_max' => 5,
            'unlimited_business_slots' => false,
            'additional_business_slot_price_ratio' => 0.5,
            'is_active' => true,
        ]);

        $this->assertInstanceOf(WorkspacePlanCatalog::class, $catalog);
        $this->assertSame('Growth Pilot', $catalog->fresh()->display_name);
        $this->assertSame(3, $catalog->fresh()->business_slot_included);
        $this->assertTrue($catalog->fresh()->is_active);
    }

    public function test_find_by_tier_casts_the_seeded_core_row(): void
    {
        $repository = app(WorkspacePlanCatalogRepository::class);

        $core = $repository->findByTier(WorkspacePlanTier::Core);

        $this->assertNotNull($core);
        $this->assertSame(WorkspacePlanTier::Core, $core->tier);
        $this->assertSame(3, $core->business_slot_included);
        $this->assertSame(5, $core->business_slot_max);
        $this->assertFalse($core->unlimited_business_slots);
    }

    public function test_find_by_tier_casts_the_seeded_agency_row_as_unlimited(): void
    {
        $repository = app(WorkspacePlanCatalogRepository::class);

        $agency = $repository->findByTier(WorkspacePlanTier::Agency);

        $this->assertNotNull($agency);
        $this->assertSame(WorkspacePlanTier::Agency, $agency->tier);
        $this->assertTrue($agency->unlimited_business_slots);
        $this->assertNull($agency->business_slot_max);
        $this->assertNull($agency->additional_business_slot_price_ratio);
    }

    public function test_find_by_id_returns_the_exact_row(): void
    {
        $repository = app(WorkspacePlanCatalogRepository::class);
        $core = $repository->findByTier(WorkspacePlanTier::Core);

        $found = $repository->findById($core->id);

        $this->assertTrue($found->is($core));
    }

    public function test_find_by_id_returns_null_for_a_missing_id(): void
    {
        $repository = app(WorkspacePlanCatalogRepository::class);

        $this->assertNull($repository->findById(999999));
    }

    public function test_tier_uniqueness_violation_surfaces_as_a_query_exception(): void
    {
        $repository = app(WorkspacePlanCatalogRepository::class);

        $this->expectException(QueryException::class);
        $repository->create([
            'tier' => 'core',
            'display_name' => 'Duplicate Core',
            'business_slot_included' => 3,
            'business_slot_max' => 5,
            'unlimited_business_slots' => false,
            'is_active' => true,
        ]);
    }

    public function test_no_entitlement_or_slot_decision_method_exists_on_this_repository(): void
    {
        foreach (get_class_methods(EloquentWorkspacePlanCatalogRepository::class) as $method) {
            $this->assertStringNotContainsStringIgnoringCase('decide', $method);
            $this->assertStringNotContainsStringIgnoringCase('entitlement', $method);
        }
    }
}
