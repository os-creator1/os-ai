<?php

namespace Tests\Feature\Entitlement;

use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanFeatureRepository;
use App\Repositories\Eloquent\EloquentWorkspacePlanFeatureRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WorkspacePlanFeatureRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function coreCatalog(): WorkspacePlanCatalog
    {
        return WorkspacePlanCatalog::where('tier', 'core')->firstOrFail();
    }

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentWorkspacePlanFeatureRepository::class,
            app(WorkspacePlanFeatureRepository::class)
        );
    }

    public function test_feature_keys_for_catalog_returns_the_seeded_core_matrix(): void
    {
        $repository = app(WorkspacePlanFeatureRepository::class);

        $keys = $repository->featureKeysForCatalog($this->coreCatalog())->sort()->values()->all();

        $expected = [
            'ads_basic_visibility', 'ai_coo_basic', 'automations', 'calendar',
            'conversations', 'crm', 'forms', 'seo_basic_visibility', 'website_generation',
        ];
        sort($expected);

        $this->assertSame($expected, $keys);
    }

    public function test_includes_feature_true_for_a_packaged_key_false_for_an_unpackaged_one(): void
    {
        $repository = app(WorkspacePlanFeatureRepository::class);
        $core = $this->coreCatalog();

        $this->assertTrue($repository->includesFeature($core, 'crm'));
        $this->assertFalse($repository->includesFeature($core, 'white_label'));
    }

    public function test_create_persists_a_new_feature_row(): void
    {
        $repository = app(WorkspacePlanFeatureRepository::class);
        $core = $this->coreCatalog();

        $feature = $repository->create([
            'workspace_plan_catalog_id' => $core->id,
            'feature_key' => 'seo_module',
        ]);

        $this->assertTrue($repository->includesFeature($core, 'seo_module'));
        $this->assertSame($core->id, $feature->workspace_plan_catalog_id);
    }

    public function test_create_rejects_an_unknown_feature_key(): void
    {
        $repository = app(WorkspacePlanFeatureRepository::class);

        $this->expectException(InvalidArgumentException::class);
        $repository->create([
            'workspace_plan_catalog_id' => $this->coreCatalog()->id,
            'feature_key' => 'not_a_real_feature',
        ]);
    }

    public function test_duplicate_catalog_and_feature_key_surfaces_as_a_query_exception(): void
    {
        $repository = app(WorkspacePlanFeatureRepository::class);

        $this->expectException(QueryException::class);
        $repository->create([
            'workspace_plan_catalog_id' => $this->coreCatalog()->id,
            'feature_key' => 'crm',
        ]);
    }

    public function test_no_entitlement_or_availability_decision_method_exists_on_this_repository(): void
    {
        foreach (get_class_methods(EloquentWorkspacePlanFeatureRepository::class) as $method) {
            $this->assertStringNotContainsStringIgnoringCase('decide', $method);
            $this->assertStringNotContainsStringIgnoringCase('isavailable', $method);
        }
    }
}
