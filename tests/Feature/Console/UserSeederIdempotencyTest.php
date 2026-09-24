<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * `UserSeeder` used to truncate `users`, `customers`, `roles` and
 * `role_user` before recreating the hardcoded administrator — fine for a
 * fresh install, destructive for every account, role and membership an
 * install accumulates afterward. This proves running the seeder again
 * against a populated database preserves every existing identity untouched,
 * and stays idempotent on the role/permission set it does own.
 */
class UserSeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_it_preserves_existing_users_customers_and_memberships(): void
    {
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
        $workspaceId = $business->workspace_id;

        $preExistingUserIds = User::query()->pluck('id')->sort()->values();
        $preExistingCustomerIds = Customer::query()->pluck('id')->sort()->values();
        $preExistingUserCount = $preExistingUserIds->count();
        $preExistingCustomerCount = $preExistingCustomerIds->count();

        $staff = User::create([
            'first_name' => 'Existing',
            'last_name' => 'Staff',
            'email' => 'existing-staff' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);

        WorkspaceMembership::create([
            'workspace_id' => $workspaceId,
            'user_id' => $staff->id,
            'role' => 'staff',
        ]);

        (new UserSeeder())->run();

        $this->assertSame(
            $preExistingUserIds->push($staff->id)->sort()->values()->all(),
            User::query()->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame(
            $preExistingCustomerIds->all(),
            Customer::query()->pluck('id')->sort()->values()->all(),
        );
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'email' => $staff->email]);
        $this->assertDatabaseHas('workspace_memberships', ['workspace_id' => $workspaceId, 'user_id' => $staff->id, 'role' => 'staff']);
        $this->assertSame($preExistingUserCount + 1, User::query()->count());
        $this->assertSame($preExistingCustomerCount, Customer::query()->count());
    }

    public function test_running_it_twice_creates_no_duplicate_role_or_permissions(): void
    {
        (new UserSeeder())->run();
        (new UserSeeder())->run();

        $this->assertSame(1, Role::query()->where('name', 'administrator')->count());

        $role = Role::query()->where('name', 'administrator')->firstOrFail();
        $permissionCount = $role->permissions()->count();

        $this->assertSame(count(config('permissions')), $permissionCount);

        (new UserSeeder())->run();

        $this->assertSame($permissionCount, $role->permissions()->count());
    }
}
