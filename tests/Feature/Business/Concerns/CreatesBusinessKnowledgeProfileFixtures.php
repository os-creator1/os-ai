<?php

namespace Tests\Feature\Business\Concerns;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\User;

/**
 * Website Guided Generation contract §16 Slice 1 -- shared fixtures for
 * the Business Knowledge Profile test suites. Builds directly on the
 * existing CreatesBusinessTestData trait (Website Slice A's own
 * precedent) rather than introducing a second Business-fixture
 * convention.
 */
trait CreatesBusinessKnowledgeProfileFixtures
{
    use CreatesBusinessTestData;

    /**
     * @return array{0: Business, 1: \App\Models\Customer}
     */
    protected function profileFixtureBusiness(array $overrides = []): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes($overrides));

        return [$business->fresh(), $customer];
    }

    /**
     * @return array{0: Business, 1: BusinessLocation}
     */
    protected function profileFixtureBusinessWithPrimaryLocation(array $overrides = []): array
    {
        [$business] = $this->profileFixtureBusiness($overrides);
        $location = $this->addLocation($business, ['is_primary' => true]);

        return [$business, $location];
    }

    protected function actorUserId(): int
    {
        return User::create([
            'first_name' => 'Actor',
            'last_name' => 'User',
            'email' => 'actor' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ])->id;
    }

    /**
     * `is_primary` is deliberately excluded from BusinessLocation::
     * $fillable (it is a protected invariant, normally only set through
     * EloquentBusinessLocationRepository::upsertPrimary()/setPrimary()),
     * so it is set here via a direct property assignment after create()
     * rather than mass assignment.
     */
    protected function addLocation(Business $business, array $overrides = []): BusinessLocation
    {
        $isPrimary = (bool) ($overrides['is_primary'] ?? false);
        unset($overrides['is_primary']);

        $location = BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'name' => 'Secondary Location',
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));

        if ($isPrimary) {
            $location->is_primary = true;
            $location->save();
        }

        return $location->fresh();
    }

    protected function addService(Business $business, array $overrides = []): BusinessService
    {
        return BusinessService::create(array_merge([
            'business_id' => $business->id,
            'name' => 'Test Service ' . uniqid('', true),
            'slug' => 'test-service-' . uniqid('', true),
            'status' => 'active',
        ], $overrides));
    }
}
