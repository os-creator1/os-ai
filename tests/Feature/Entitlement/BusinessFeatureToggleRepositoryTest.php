<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Repositories\Contracts\BusinessFeatureToggleRepository;
use App\Repositories\Eloquent\EloquentBusinessFeatureToggleRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class BusinessFeatureToggleRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentBusinessFeatureToggleRepository::class,
            app(BusinessFeatureToggleRepository::class)
        );
    }

    public function test_create_persists_and_casts_feature_key(): void
    {
        $repository = app(BusinessFeatureToggleRepository::class);
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        $toggle = $repository->create([
            'business_id' => $business->id,
            'feature_key' => 'crm',
            'reason' => 'Owner disabled it',
            'created_by_user_id' => 1,
        ]);

        $this->assertSame(PlatformFeature::Crm, $toggle->fresh()->feature_key);
    }

    public function test_find_by_business_and_feature_returns_the_exact_row(): void
    {
        $repository = app(BusinessFeatureToggleRepository::class);
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        $toggle = $repository->create([
            'business_id' => $business->id,
            'feature_key' => 'crm',
        ]);

        $found = $repository->findByBusinessAndFeature($business->id, 'crm');

        $this->assertNotNull($found);
        $this->assertTrue($found->is($toggle));
    }

    public function test_find_by_business_and_feature_returns_null_when_absent(): void
    {
        $repository = app(BusinessFeatureToggleRepository::class);
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        $this->assertNull($repository->findByBusinessAndFeature($business->id, 'crm'));
    }

    public function test_delete_removes_the_row_re_enabling_the_feature(): void
    {
        $repository = app(BusinessFeatureToggleRepository::class);
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        $toggle = $repository->create([
            'business_id' => $business->id,
            'feature_key' => 'crm',
        ]);

        $repository->delete($toggle);

        $this->assertNull($repository->findByBusinessAndFeature($business->id, 'crm'));
    }

    public function test_create_rejects_an_unknown_feature_key(): void
    {
        $repository = app(BusinessFeatureToggleRepository::class);
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        $this->expectException(InvalidArgumentException::class);
        $repository->create([
            'business_id' => $business->id,
            'feature_key' => 'not_a_real_feature',
        ]);
    }

    public function test_duplicate_business_and_feature_key_surfaces_as_a_query_exception(): void
    {
        $repository = app(BusinessFeatureToggleRepository::class);
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        $repository->create([
            'business_id' => $business->id,
            'feature_key' => 'crm',
        ]);

        $this->expectException(QueryException::class);
        $repository->create([
            'business_id' => $business->id,
            'feature_key' => 'crm',
        ]);
    }

    public function test_no_entitlement_decision_method_exists_on_this_repository(): void
    {
        foreach (get_class_methods(EloquentBusinessFeatureToggleRepository::class) as $method) {
            $this->assertStringNotContainsStringIgnoringCase('decide', $method);
            $this->assertStringNotContainsStringIgnoringCase('effectiveentitlement', $method);
        }
    }
}
