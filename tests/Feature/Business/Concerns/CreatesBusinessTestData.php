<?php

namespace Tests\Feature\Business\Concerns;

use App\Models\Customer;
use App\Models\User;

trait CreatesBusinessTestData
{
    protected function createCustomer(): Customer
    {
        $user = User::create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'customer' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);

        return Customer::create([
            'user_id' => $user->id,
        ]);
    }

    protected function businessAttributes(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Snap Booth Co',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $overrides);
    }
}
