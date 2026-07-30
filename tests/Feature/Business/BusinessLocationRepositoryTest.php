<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class BusinessLocationRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function createBusiness(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    public function test_upsert_primary_creates_a_single_primary_location(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessLocationRepository::class);

        $location = $repository->upsertPrimary($business, [
            'service_mode' => 'storefront',
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => true,
        ]);

        $this->assertTrue($location->is_primary);
        $this->assertSame(1, $business->locations()->count());
    }

    public function test_upsert_primary_updates_the_existing_primary_instead_of_duplicating(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessLocationRepository::class);

        $repository->upsertPrimary($business, [
            'service_mode' => 'storefront',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => true,
        ]);

        $repository->upsertPrimary($business, [
            'service_mode' => 'storefront',
            'city' => 'Dallas',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => true,
        ]);

        $this->assertSame(1, $business->locations()->count());
        $this->assertSame('Dallas', $business->locations()->first()->city);
    }

    public function test_set_primary_unsets_sibling_locations(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessLocationRepository::class);

        $first = $business->locations()->create([
            'service_mode' => 'service_area',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
        ]);
        $first->is_primary = true;
        $first->save();

        $second = $business->locations()->create([
            'service_mode' => 'service_area',
            'city' => 'Dallas',
            'region' => 'TX',
            'country_code' => 'US',
        ]);

        $repository->setPrimary($second);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_find_primary_returns_null_when_none_exists(): void
    {
        $business = $this->createBusiness();
        $repository = app(BusinessLocationRepository::class);

        $this->assertNull($repository->findPrimary($business));
    }

    public function test_uid_is_automatically_generated_and_unique(): void
    {
        $business = $this->createBusiness();

        $first = $business->locations()->create([
            'service_mode' => 'service_area',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
        ]);
        $second = $business->locations()->create([
            'service_mode' => 'service_area',
            'city' => 'Dallas',
            'region' => 'TX',
            'country_code' => 'US',
        ]);

        $this->assertNotNull($first->uid);
        $this->assertNotNull($second->uid);
        $this->assertNotSame($first->uid, $second->uid);
    }
}
