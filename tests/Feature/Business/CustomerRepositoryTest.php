<?php

namespace Tests\Feature\Business;

use App\Models\User;
use App\Repositories\Contracts\CustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class CustomerRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_find_by_user_id_returns_the_exact_matching_customer(): void
    {
        $customer = $this->createCustomer();
        $repository = app(CustomerRepository::class);

        $found = $repository->findByUserId($customer->user_id);

        $this->assertNotNull($found);
        $this->assertSame($customer->id, $found->id);
    }

    public function test_find_by_user_id_cannot_return_another_users_customer(): void
    {
        $customer = $this->createCustomer();
        $other = $this->createCustomer();
        $repository = app(CustomerRepository::class);

        $found = $repository->findByUserId($customer->user_id);

        $this->assertNotNull($found);
        $this->assertNotSame($other->id, $found->id);
        $this->assertSame($customer->user_id, $found->user_id);
    }

    public function test_find_by_user_id_returns_null_when_the_user_has_no_customer_profile(): void
    {
        $repository = app(CustomerRepository::class);

        $user = User::create([
            'first_name' => 'No',
            'last_name' => 'Customer',
            'email' => 'no-customer-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => false,
            'active_portal' => 'customer',
        ]);

        $this->assertNull($repository->findByUserId($user->id));
    }
}
