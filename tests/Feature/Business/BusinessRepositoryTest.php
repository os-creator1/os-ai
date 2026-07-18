<?php

namespace Tests\Feature\Business;

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
}
