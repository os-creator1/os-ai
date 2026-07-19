<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessStatus;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class BusinessRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_first_business_becomes_primary(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($customer, $this->businessAttributes());

        $this->assertTrue($business->is_primary);
        $this->assertSame(BusinessStatus::Draft, $business->status);
    }

    public function test_second_business_is_not_primary_by_default(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $repository->createForCustomer($customer, $this->businessAttributes());
        $second = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Business']));

        $this->assertFalse($second->is_primary);
    }

    public function test_setting_a_business_primary_unsets_its_sibling(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $first = $repository->createForCustomer($customer, $this->businessAttributes());
        $second = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Business']));

        $repository->setPrimary($second);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_find_primary_by_customer_returns_the_primary_business(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $repository->createForCustomer($customer, $this->businessAttributes());

        $primary = $repository->findPrimaryByCustomer($customer->user_id);

        $this->assertNotNull($primary);
        $this->assertTrue($primary->is_primary);
    }

    public function test_find_owned_by_customer_rejects_other_customers(): void
    {
        $owner = $this->createCustomer();
        $stranger = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($owner, $this->businessAttributes());

        $this->assertNull($repository->findOwnedByCustomer($business->id, $stranger->user_id));
        $this->assertNotNull($repository->findOwnedByCustomer($business->id, $owner->user_id));
    }

    public function test_update_ignores_protected_fields(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($customer, $this->businessAttributes());
        $otherCustomer = $this->createCustomer();

        $repository->update($business, [
            'name' => 'Renamed Booth Co',
            'customer_id' => $otherCustomer->user_id,
            'is_primary' => false,
            'status' => BusinessStatus::Active->value,
            'canonical_domain' => 'attacker.test',
            'activated_at' => now(),
        ]);

        $business->refresh();

        $this->assertSame('Renamed Booth Co', $business->name);
        $this->assertSame($customer->user_id, $business->customer_id);
        $this->assertTrue($business->is_primary);
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertNull($business->canonical_domain);
        $this->assertNull($business->activated_at);
    }

    public function test_update_status_sets_activated_at_when_becoming_active(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($customer, $this->businessAttributes());
        $this->assertNull($business->activated_at);

        $repository->updateStatus($business, BusinessStatus::Active);

        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);
    }

    public function test_find_for_update_returns_the_correct_business(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $business = $repository->createForCustomer($customer, $this->businessAttributes());
        $other = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Business']));

        $found = $repository->findForUpdate($business->id);

        $this->assertNotNull($found);
        $this->assertSame($business->id, $found->id);
        $this->assertNotSame($other->id, $found->id);
    }

    public function test_find_for_update_returns_null_for_unknown_id(): void
    {
        $repository = app(BusinessRepository::class);

        $this->assertNull($repository->findForUpdate(999999));
    }

    public function test_uid_is_automatically_generated_and_unique(): void
    {
        $customer = $this->createCustomer();
        $repository = app(BusinessRepository::class);

        $first = $repository->createForCustomer($customer, $this->businessAttributes());
        $second = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Business']));

        $this->assertNotNull($first->uid);
        $this->assertNotNull($second->uid);
        $this->assertNotSame($first->uid, $second->uid);
    }

    /**
     * paginateForAdmin() is the one deliberately cross-tenant method on this
     * contract (RFC-001 §19 admin index — Milestone 6); every other method
     * above is tenant-scoped by design.
     */
    public function test_paginate_for_admin_returns_businesses_across_tenants(): void
    {
        $repository = app(BusinessRepository::class);
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $repository->createForCustomer($customerA, $this->businessAttributes(['name' => 'Booth Co A']));
        $repository->createForCustomer($customerB, $this->businessAttributes(['name' => 'Booth Co B']));

        $page = $repository->paginateForAdmin([], 25);

        $this->assertSame(2, $page->total());
    }

    public function test_paginate_for_admin_filters_by_search(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Snap Booth Co']));
        $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Totally Different']));

        $page = $repository->paginateForAdmin(['search' => 'Snap'], 25);

        $this->assertSame(1, $page->total());
        $this->assertSame('Snap Booth Co', $page->first()->name);
    }

    public function test_paginate_for_admin_filters_by_status(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $active = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Active Co']));
        $repository->updateStatus($active, BusinessStatus::Active);
        $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Draft Co']));

        $page = $repository->paginateForAdmin(['status' => BusinessStatus::Active->value], 25);

        $this->assertSame(1, $page->total());
        $this->assertSame('Active Co', $page->first()->name);
    }

    public function test_paginate_for_admin_filters_by_industry(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $repository->createForCustomer($customer, $this->businessAttributes([
            'name' => 'Photographer Co',
            'industry' => BusinessIndustry::Photographer->value,
        ]));
        $repository->createForCustomer($customer, $this->businessAttributes([
            'name' => 'Booth Co',
            'industry' => BusinessIndustry::PhotoBoothService->value,
        ]));

        $page = $repository->paginateForAdmin(['industry' => BusinessIndustry::Photographer->value], 25);

        $this->assertSame(1, $page->total());
        $this->assertSame('Photographer Co', $page->first()->name);
    }

    public function test_paginate_for_admin_ignores_unknown_filter_keys(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $repository->createForCustomer($customer, $this->businessAttributes());

        // 'customer_id' is not an allowed filter — passing it must not
        // narrow (or otherwise change) the query.
        $page = $repository->paginateForAdmin(['customer_id' => $customer->user_id + 999], 25);

        $this->assertSame(1, $page->total());
    }

    public function test_paginate_for_admin_caps_per_page_to_a_safe_maximum(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $repository->createForCustomer($customer, $this->businessAttributes());

        $page = $repository->paginateForAdmin([], 9999);

        $this->assertLessThanOrEqual(100, $page->perPage());
    }

    public function test_paginate_for_admin_orders_deterministically_newest_first(): void
    {
        $repository = app(BusinessRepository::class);
        $customer = $this->createCustomer();
        $first = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'First Co']));
        $second = $repository->createForCustomer($customer, $this->businessAttributes(['name' => 'Second Co']));

        $page = $repository->paginateForAdmin([], 25);

        $this->assertSame([$second->id, $first->id], $page->pluck('id')->all());
    }
}
