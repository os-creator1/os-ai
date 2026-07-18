<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessServiceStatus;
use App\Models\Business;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class BusinessServiceRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function createBusiness(): Business
    {
        $customer = $this->createCustomer();

        return app(BusinessRepository::class)->createForCustomer($customer, $this->businessAttributes());
    }

    public function test_sync_creates_new_services_with_unique_slugs(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessServiceRepository::class);

        $repository->syncForBusiness($business, [
            ['name' => 'Digital Photo Booth', 'is_primary' => true],
            ['name' => 'Digital Photo Booth', 'is_primary' => false],
        ]);

        $slugs = $business->services()->pluck('slug')->sort()->values()->all();

        $this->assertSame(['digital-photo-booth', 'digital-photo-booth-2'], $slugs);
    }

    public function test_sync_updates_existing_services_without_changing_slug(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessServiceRepository::class);

        $created = $repository->syncForBusiness($business, [
            ['name' => 'Digital Photo Booth', 'is_primary' => true],
        ]);
        $service = $created->first();
        $originalSlug = $service->slug;

        $repository->syncForBusiness($business, [
            ['id' => $service->id, 'name' => 'Renamed Booth', 'is_primary' => true],
        ]);

        $service->refresh();

        $this->assertSame('Renamed Booth', $service->name);
        $this->assertSame($originalSlug, $service->slug);
    }

    public function test_sync_inactivates_omitted_services_instead_of_deleting(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessServiceRepository::class);

        $created = $repository->syncForBusiness($business, [
            ['name' => 'Booth One', 'is_primary' => true],
            ['name' => 'Booth Two', 'is_primary' => false],
        ]);
        $keep = $created->firstWhere('name', 'Booth One');

        $repository->syncForBusiness($business, [
            ['id' => $keep->id, 'name' => 'Booth One', 'is_primary' => true],
        ]);

        $this->assertSame(2, $business->services()->count());
        $this->assertSame(
            BusinessServiceStatus::Inactive,
            $business->services()->where('name', 'Booth Two')->first()->status
        );
    }

    public function test_sync_rejects_a_service_id_owned_by_another_business(): void
    {
        $businessA = $this->createBusiness();
        $businessB = $this->createBusiness();
        $repository = app(BusinessServiceRepository::class);

        $created = $repository->syncForBusiness($businessA, [
            ['name' => 'Booth One', 'is_primary' => true],
        ]);
        $foreignId = $created->first()->id;

        $this->expectException(InvalidArgumentException::class);

        $repository->syncForBusiness($businessB, [
            ['id' => $foreignId, 'name' => 'Hijacked', 'is_primary' => true],
        ]);
    }

    public function test_sync_leaves_exactly_one_active_primary(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessServiceRepository::class);

        $repository->syncForBusiness($business, [
            ['name' => 'Booth One', 'is_primary' => true],
            ['name' => 'Booth Two', 'is_primary' => true],
        ]);

        $this->assertSame(1, $business->services()->where('is_primary', true)->count());
    }

    public function test_sync_falls_back_to_first_active_service_when_none_requested_primary(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessServiceRepository::class);

        $repository->syncForBusiness($business, [
            ['name' => 'Booth One', 'is_primary' => false, 'sort_order' => 1],
            ['name' => 'Booth Two', 'is_primary' => false, 'sort_order' => 2],
        ]);

        $primary = $business->services()->where('is_primary', true)->first();

        $this->assertNotNull($primary);
        $this->assertSame('Booth One', $primary->name);
    }

    public function test_set_primary_rejects_an_inactive_service(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessServiceRepository::class);

        $service = $business->services()->create([
            'name' => 'Inactive Booth',
            'slug' => 'inactive-booth',
            'status' => BusinessServiceStatus::Inactive->value,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $repository->setPrimary($service);
    }

    public function test_uid_is_automatically_generated_and_unique(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessServiceRepository::class);

        $created = $repository->syncForBusiness($business, [
            ['name' => 'Booth One', 'is_primary' => true],
            ['name' => 'Booth Two', 'is_primary' => false],
        ]);

        $uids = $created->pluck('uid');

        $this->assertTrue($uids->every(fn ($uid) => ! empty($uid)));
        $this->assertSame($uids->count(), $uids->unique()->count());
    }
}
